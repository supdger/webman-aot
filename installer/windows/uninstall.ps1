[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder'),
    [string]$BinDir = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder\bin'),
    [switch]$Purge,
    [switch]$NoPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$resolvedHome = [IO.Path]::GetFullPath($InstallRoot).TrimEnd('\')
$profileRoot = [IO.Path]::GetFullPath($env:USERPROFILE).TrimEnd('\')
if ($resolvedHome -eq [IO.Path]::GetPathRoot($resolvedHome).TrimEnd('\') -or
    $resolvedHome -eq $profileRoot) {
    throw "Refusing unsafe Webman AOT Builder home: $resolvedHome"
}

$launcher = Join-Path $BinDir 'webman-aot.cmd'
$legacyLauncher = Join-Path $resolvedHome '.previous-launcher\webman-aot.cmd'
$binPath = [IO.Path]::GetFullPath($BinDir).TrimEnd('\')
$binInsideHome = [string]::Equals(
    $binPath, $resolvedHome, [StringComparison]::OrdinalIgnoreCase
) -or $binPath.StartsWith($resolvedHome + '\', [StringComparison]::OrdinalIgnoreCase)
$launcherPresent = Test-Path -LiteralPath $launcher
$launcherOwned = $false
if ($launcherPresent) {
    $launcherItem = Get-Item -LiteralPath $launcher
    $launcherOwned = -not ($launcherItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -and
        -not $launcherItem.PSIsContainer -and
        (Select-String -LiteralPath $launcher -SimpleMatch 'WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER' -Quiet)
}
if ($Purge -and (Test-Path -LiteralPath $legacyLauncher -PathType Leaf) -and
    $launcherPresent -and -not $launcherOwned) {
    throw "Cannot purge Builder data: a previous webman-aot command is backed up while another command occupies $launcher"
}
if ($Purge -and $binInsideHome -and $launcherPresent -and -not $launcherOwned) {
    throw "Cannot purge Builder data while an unowned webman-aot command remains inside it: $launcher"
}
$restoredPrevious = $false
if ($launcherOwned) {
    Remove-Item -Force -LiteralPath $launcher
    $launcherPresent = $false
} elseif ($launcherPresent) {
    Write-Output "Command is not owned by Webman AOT Builder; leaving it in place: $launcher"
}
if (-not $launcherPresent -and (Test-Path -LiteralPath $legacyLauncher -PathType Leaf)) {
    New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
    Move-Item -LiteralPath $legacyLauncher -Destination $launcher
    $launcherPresent = $true
    $restoredPrevious = $true
    Write-Output "Previous webman-aot command restored: $launcher"
}
$obsoleteLauncher = Join-Path $BinDir 'webman-aot-builder.cmd'
if ((Test-Path -LiteralPath $obsoleteLauncher -PathType Leaf) -and
    (Select-String -LiteralPath $obsoleteLauncher -SimpleMatch 'WEBMAN_AOT_BUILDER_HOME' -Quiet)) {
    Remove-Item -Force -LiteralPath $obsoleteLauncher
}
$removePath = Test-Path -LiteralPath (Join-Path $resolvedHome '.path-added-by-builder') -PathType Leaf
if ($Purge) {
    if ($restoredPrevious -and $binInsideHome) {
        $tempPath = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
        if ([string]::Equals(
            $tempPath, $resolvedHome, [StringComparison]::OrdinalIgnoreCase
        ) -or $tempPath.StartsWith($resolvedHome + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw "Cannot preserve the previous command: temporary directory is inside Builder data."
        }
        $saved = Join-Path $env:TEMP ('webman-aot-previous-' + [Guid]::NewGuid().ToString('N') + '.cmd')
        Move-Item -LiteralPath $launcher -Destination $saved
        try {
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue -LiteralPath $resolvedHome
        } finally {
            New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
            Move-Item -LiteralPath $saved -Destination $launcher
        }
    } else {
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue -LiteralPath $resolvedHome
    }
} else {
    foreach ($relative in @('current', 'versions', '.install-candidates')) {
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue -LiteralPath (Join-Path $resolvedHome $relative)
    }
}

if (-not $NoPath -and $removePath) {
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    $parts = @($userPath -split ';' | Where-Object { $_ -ne '' -and $_ -ne $BinDir })
    [Environment]::SetEnvironmentVariable('Path', ($parts -join ';'), 'User')
}

Write-Output 'Webman AOT Builder uninstalled.'
