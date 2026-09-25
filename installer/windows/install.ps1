[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'webman-aot'),
    [string]$BinDir = (Join-Path $env:LOCALAPPDATA 'webman-aot\bin'),
    [switch]$NoPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if (-not [Environment]::Is64BitOperatingSystem -or
    $env:PROCESSOR_ARCHITECTURE -notin @('AMD64', 'x86')) {
    throw 'This package requires Windows x64.'
}

$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$manifestPath = Join-Path $packageRoot 'payload-manifest.sha256'
foreach ($line in Get-Content -LiteralPath $manifestPath) {
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

$candidate = Join-Path $InstallRoot ('.install-candidates\install-' + $PID + '-' + [Guid]::NewGuid().ToString('N'))
$backup = $null
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

    New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
    Copy-Item -Force -LiteralPath (Join-Path $packageRoot 'payload\launcher\webman-aot.cmd') -Destination (Join-Path $BinDir 'webman-aot.cmd')

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
} finally {
    if (Test-Path -LiteralPath $candidate) {
        Remove-Item -Recurse -Force -LiteralPath $candidate
    }
}

Write-Output "Webman AOT installed in: $InstallRoot"
Write-Output "Command installed as: $(Join-Path $BinDir 'webman-aot.cmd')"
