[CmdletBinding()]
param(
    [string]$Compare,
    [switch]$CompareRelease,
    [string]$Output,
    [string]$Revision
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($Compare -and $CompareRelease) {
    throw 'Choose either -Compare or -CompareRelease, not both.'
}

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
try {
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
    if ($CompareRelease) { $arguments += '--compare-release' }
    if ($Output) { $arguments += "--output=$Output" }
    if ($Revision) { $arguments += "--revision=$Revision" }
    & $php @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Windows source build or comparison failed.'
    }
} finally {
    if (Test-Path -LiteralPath $temporary) {
        Remove-Item -Recurse -Force -LiteralPath $temporary
    }
}
