[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'webman-aot'),
    [string]$BinDir = (Join-Path $env:LOCALAPPDATA 'webman-aot\bin'),
    [switch]$NoPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$installTimer = [Diagnostics.Stopwatch]::StartNew()

if (-not [Environment]::Is64BitOperatingSystem -or
    $env:PROCESSOR_ARCHITECTURE -notin @('AMD64', 'x86')) {
    throw 'This package requires Windows x64.'
}

$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$full = Test-Path -LiteralPath (Join-Path $packageRoot 'payload\minimal-toolchain\component.zip') -PathType Leaf
$installPath = [IO.Path]::GetFullPath($InstallRoot)
$installDrive = [IO.Path]::GetPathRoot($installPath)
if ($installPath.TrimEnd('\') -eq $installDrive.TrimEnd('\') -or
    $installPath.TrimEnd('\') -eq $env:USERPROFILE.TrimEnd('\') -or
    ((Test-Path -LiteralPath $InstallRoot) -and
        ((Get-Item -LiteralPath $InstallRoot).Attributes -band [IO.FileAttributes]::ReparsePoint))) {
    throw "Unsafe Webman AOT installation directory: $InstallRoot"
}
if ($full) {
    $freeBytes = ([IO.DriveInfo]::new($installDrive)).AvailableFreeSpace
    if ($freeBytes -lt 4GB) {
        throw ("Complete installation needs at least 4 GiB free on {0}; available: {1:N1} GiB. Choose a larger drive with -InstallRoot and -BinDir." -f
            $installDrive, ($freeBytes / 1GB))
    }
    Write-Output '[install] Complete package: installing the locked minimal toolchain offline (no downloads).'
}
$manifestPath = Join-Path $packageRoot 'payload-manifest.sha256'
$manifestLines = @(Get-Content -LiteralPath $manifestPath)
foreach ($line in $manifestLines) {
    if ($line -notmatch '^([a-f0-9]{64})  (.+)$') {
        throw "Invalid payload manifest line: $line"
    }
    $relative = $Matches[2].Replace('/', [IO.Path]::DirectorySeparatorChar)
    $path = Join-Path $packageRoot $relative
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
    if ($actual -ne $Matches[1]) {
        throw "Payload digest mismatch: $relative"
    }
}
Write-Output "Package contents SHA-256 verified: $($manifestLines.Count) files"

$candidate = Join-Path $InstallRoot ('.w-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
$backup = $null
$fullBackup = $null
$newCurrent = $false
$newToolchains = $false
$newLauncher = $false
try {
    $candidateCurrent = Join-Path $candidate 'current'
    New-Item -ItemType Directory -Force -Path $candidateCurrent | Out-Null
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $packageRoot 'payload\app') -Destination (Join-Path $candidateCurrent 'app')
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $packageRoot 'payload\runtime') -Destination (Join-Path $candidateCurrent 'runtime')

    $previousHome = $env:WEBMAN_AOT_HOME
    $env:WEBMAN_AOT_HOME = $candidate
    try {
        & (Join-Path $candidateCurrent 'runtime\php.exe') `
            -c (Join-Path $candidateCurrent 'runtime\php.ini') `
            -d "extension_dir=$(Join-Path $candidateCurrent 'runtime\ext')" `
            (Join-Path $candidateCurrent 'app\bin\webman-aot.php') --version | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw 'Candidate self-check failed.'
        }
    } finally {
        $env:WEBMAN_AOT_HOME = $previousHome
    }

    New-Item -ItemType Directory -Force -Path (Join-Path $InstallRoot '.install-backups') | Out-Null
    $current = Join-Path $InstallRoot 'current'
    New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
    $launcher = Join-Path $BinDir 'webman-aot.cmd'
    if ($full) {
        $bundle = Join-Path $packageRoot 'payload\minimal-toolchain\component.zip'
        $offlineScript = Join-Path $candidateCurrent 'app\installer\offline-prepare.php'
        $previousHome = $env:WEBMAN_AOT_HOME
        $env:WEBMAN_AOT_HOME = $candidate
        try {
            & (Join-Path $candidateCurrent 'runtime\php.exe') `
                -c (Join-Path $candidateCurrent 'runtime\php.ini') `
                -d "extension_dir=$(Join-Path $candidateCurrent 'runtime\ext')" `
                $offlineScript $bundle
            if ($LASTEXITCODE -ne 0) { throw 'Offline toolchain preparation failed.' }
        } finally {
            $env:WEBMAN_AOT_HOME = $previousHome
        }
        $fullBackup = Join-Path $InstallRoot ('.install-backups\full-' +
            [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + $PID)
        New-Item -ItemType Directory -Force -Path $fullBackup | Out-Null
        foreach ($name in @('current', 'toolchains', 'versions')) {
            $old = Join-Path $InstallRoot $name
            if (Test-Path -LiteralPath $old) {
                Move-Item -LiteralPath $old -Destination (Join-Path $fullBackup $name)
            }
        }
        if (Test-Path -LiteralPath $launcher) {
            Move-Item -LiteralPath $launcher -Destination (Join-Path $fullBackup 'webman-aot.cmd')
        }
        Move-Item -LiteralPath $candidateCurrent -Destination $current
        $newCurrent = $true
        Move-Item -LiteralPath (Join-Path $candidate 'toolchains') -Destination (Join-Path $InstallRoot 'toolchains')
        $newToolchains = $true
        Copy-Item -LiteralPath (Join-Path $packageRoot 'payload\launcher\webman-aot.cmd') -Destination $launcher
        $newLauncher = $true
        $previousHome = $env:WEBMAN_AOT_HOME
        $env:WEBMAN_AOT_HOME = $InstallRoot
        try {
            & (Join-Path $current 'runtime\php.exe') `
                -c (Join-Path $current 'runtime\php.ini') `
                -d "extension_dir=$(Join-Path $current 'runtime\ext')" `
                (Join-Path $current 'app\installer\offline-prepare.php') $bundle
            if ($LASTEXITCODE -ne 0) { throw 'Activated offline toolchain self-check failed.' }
        } finally {
            $env:WEBMAN_AOT_HOME = $previousHome
        }
    } else {
        if (Test-Path -LiteralPath $current) {
            $backup = Join-Path $InstallRoot ('.install-backups\current-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + $PID)
            Move-Item -LiteralPath $current -Destination $backup
        }
        try {
            Move-Item -LiteralPath $candidateCurrent -Destination $current
        } catch {
            if ($null -ne $backup -and (Test-Path -LiteralPath $backup)) {
                Move-Item -LiteralPath $backup -Destination $current
            }
            throw
        }
        Copy-Item -Force -LiteralPath (Join-Path $packageRoot 'payload\launcher\webman-aot.cmd') -Destination $launcher
    }

    if (-not $NoPath) {
        $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
        $parts = @($userPath -split ';' | Where-Object { $_ -ne '' })
        if ($parts -notcontains $BinDir) {
            $newPath = (($parts + $BinDir) -join ';')
            [Environment]::SetEnvironmentVariable('Path', $newPath, 'User')
        }
        if (($env:Path -split ';') -notcontains $BinDir) {
            $env:Path = $env:Path + ';' + $BinDir
        }
    }
} catch {
    if ($full -and $null -ne $fullBackup) {
        Write-Warning 'Complete installation failed; restoring the previous installation.'
        if ($newLauncher -and (Test-Path -LiteralPath $launcher)) {
            Remove-Item -Force -LiteralPath $launcher
        }
        if ($newToolchains -and (Test-Path -LiteralPath (Join-Path $InstallRoot 'toolchains'))) {
            Remove-Item -Recurse -Force -LiteralPath (Join-Path $InstallRoot 'toolchains')
        }
        if ($newCurrent -and (Test-Path -LiteralPath $current)) {
            Remove-Item -Recurse -Force -LiteralPath $current
        }
        foreach ($name in @('current', 'toolchains', 'versions')) {
            $saved = Join-Path $fullBackup $name
            if (Test-Path -LiteralPath $saved) {
                Move-Item -LiteralPath $saved -Destination (Join-Path $InstallRoot $name)
            }
        }
        $savedLauncher = Join-Path $fullBackup 'webman-aot.cmd'
        if (Test-Path -LiteralPath $savedLauncher) {
            Move-Item -LiteralPath $savedLauncher -Destination $launcher
        }
    }
    throw
} finally {
    if (Test-Path -LiteralPath $candidate) {
        Remove-Item -Recurse -Force -LiteralPath $candidate
    }
}

Write-Output "Webman AOT installed in: $InstallRoot"
Write-Output "Command installed as: $(Join-Path $BinDir 'webman-aot.cmd')"
if ($full) {
    Write-Output 'Complete offline toolchain ready. Enter a Webman project and run webman-aot build.'
}
Write-Output ('Installation completed in {0:N1} seconds.' -f $installTimer.Elapsed.TotalSeconds)
