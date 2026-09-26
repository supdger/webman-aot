[CmdletBinding()]
param(
    [string]$Compare,
    [string]$Output,
    [string]$Revision
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$timer = [Diagnostics.Stopwatch]::StartNew()
try {

if (-not [Environment]::Is64BitOperatingSystem) {
    throw 'Building the Windows installer requires Windows x64.'
}

$repository = Split-Path -Parent $PSScriptRoot
$runtimeLock = Get-Content -Raw -LiteralPath (Join-Path $repository 'installer\runtime.lock.json') |
    ConvertFrom-Json
$runtime = $runtimeLock.runtimes.'windows-x86_64'
if ($null -eq $runtime -or
    $runtime.archiveUrl -notmatch '^https://' -or
    $runtime.archiveSha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'Locked Windows PHP runtime is invalid.'
}

$inputs = Join-Path $repository 'dist\installer-inputs'
New-Item -ItemType Directory -Force -Path $inputs | Out-Null
$archive = Join-Path $inputs ([IO.Path]::GetFileName(([Uri]$runtime.archiveUrl).AbsolutePath))
$expected = [string]$runtime.archiveSha256
Write-Output '[prepare] Checking locked Windows PHP runtime ...'
$verified = (Test-Path -LiteralPath $archive) -and
    ((Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant() -eq $expected)
if (-not $verified) {
    $partial = $archive + '.partial-' + [Guid]::NewGuid().ToString('N')
    try {
        Write-Output "Downloading locked Windows PHP runtime ..."
        & curl.exe -q --fail --location --retry 3 --connect-timeout 15 --max-time 180 `
            --proto '=https' --proto-redir '=https' --output $partial $runtime.archiveUrl
        if ($LASTEXITCODE -ne 0) {
            throw 'Locked Windows PHP runtime download failed.'
        }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $partial).Hash.ToLowerInvariant()
        if ($actual -ne $expected) {
            throw 'Locked Windows PHP runtime SHA-256 mismatch.'
        }
        Move-Item -Force -LiteralPath $partial -Destination $archive
    } finally {
        if (Test-Path -LiteralPath $partial) {
            Remove-Item -Force -LiteralPath $partial
        }
    }
}
Write-Output 'Locked Windows PHP runtime SHA-256 verified.'

$temporary = Join-Path $env:TEMP ('webman-aot-source-build-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $temporary | Out-Null
$buildExit = 0
try {
    Write-Output '[prepare] Extracting temporary PHP runtime ...'
    tar.exe -xf $archive -C $temporary
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to extract locked Windows PHP runtime.'
    }
    $php = Join-Path $temporary 'php.exe'
    if (-not (Test-Path -LiteralPath $php)) {
        throw 'Locked Windows PHP runtime has no php.exe.'
    }
    $ini = Join-Path $temporary 'php.ini'
    @(
        'extension_dir="' + (Join-Path $temporary 'ext') + '"'
        'extension=zip'
    ) | Set-Content -LiteralPath $ini -Encoding ascii

    $arguments = @('-c', $ini, (Join-Path $repository 'tools\build-windows-installer.php'))
    if ($Compare) { $arguments += "--compare=$Compare" }
    if ($Output) { $arguments += "--output=$Output" }
    if ($Revision) { $arguments += "--revision=$Revision" }
    Write-Output '[build] Starting Windows installer source build ...'
    & $php @arguments
    $buildExit = $LASTEXITCODE
    if ($buildExit -eq 0) {
        $versionSource = Get-Content -Raw -LiteralPath (Join-Path $repository 'src\Version.php')
        if ($versionSource -notmatch "public const VALUE = '([^']+)'") {
            throw 'Unable to determine source package version.'
        }
        $version = $Matches[1]
        $packageDir = if ($Output) {
            if ([IO.Path]::IsPathRooted($Output)) { $Output } else { Join-Path $repository $Output }
        } else {
            Join-Path $repository 'dist\source-build'
        }
        $builtZip = Join-Path $packageDir "webman-aot-$version-windows-x86_64.zip"
        if (-not (Test-Path -LiteralPath $builtZip -PathType Leaf)) {
            throw "Built installer is missing: $builtZip"
        }

        $smokeRoot = Join-Path $temporary 'install-smoke'
        $smokePackage = Join-Path $smokeRoot 'package'
        $smokeHome = Join-Path $smokeRoot 'home'
        $smokeBin = Join-Path $smokeRoot 'bin'
        New-Item -ItemType Directory -Force -Path $smokePackage | Out-Null
        Write-Output '[verify] Extracting the built installer into a temporary directory ...'
        tar.exe -xf $builtZip -C $smokePackage
        if ($LASTEXITCODE -ne 0) {
            throw 'Unable to extract the built installer for validation.'
        }
        Write-Output '[verify] Installing into a temporary directory; user PATH is unchanged ...'
        & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $smokePackage 'install.ps1') `
            -InstallRoot $smokeHome -BinDir $smokeBin -NoPath
        if ($LASTEXITCODE -ne 0) {
            throw 'Temporary installer self-check failed.'
        }
        $previousHome = $env:WEBMAN_AOT_HOME
        $env:WEBMAN_AOT_HOME = $smokeHome
        try {
            Write-Output '[verify] Running the installed version command ...'
            $versionOutput = & (Join-Path $smokeBin 'webman-aot.cmd') version
            if ($LASTEXITCODE -ne 0 -or $versionOutput -ne "webman-aot $version") {
                throw "Installed tool version check failed: $versionOutput"
            }
            Write-Output "[OK] Temporary installation runs: $versionOutput"
        } finally {
            $env:WEBMAN_AOT_HOME = $previousHome
        }
    }
} finally {
    if (Test-Path -LiteralPath $temporary) {
        Remove-Item -Recurse -Force -LiteralPath $temporary
    }
}
if ($buildExit -ne 0) {
    Write-Output ("[ERROR] Windows installer source build failed after {0:N1} seconds (exit code {1}); see the error above." -f $timer.Elapsed.TotalSeconds, $buildExit)
    exit $buildExit
}
Write-Output ("[OK] Windows installer source build finished in {0:N1} seconds." -f $timer.Elapsed.TotalSeconds)
} catch {
    Write-Output ("[ERROR] Windows installer source build failed after {0:N1} seconds." -f $timer.Elapsed.TotalSeconds)
    throw
}
