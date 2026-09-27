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

Remove-Item -Force -ErrorAction SilentlyContinue -LiteralPath (Join-Path $BinDir 'webman-aot-builder.cmd')
if ($Purge) {
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue -LiteralPath $resolvedHome
} else {
    foreach ($relative in @('current', 'versions', '.install-candidates')) {
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue -LiteralPath (Join-Path $resolvedHome $relative)
    }
}

if (-not $NoPath) {
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    $parts = @($userPath -split ';' | Where-Object { $_ -ne '' -and $_ -ne $BinDir })
    [Environment]::SetEnvironmentVariable('Path', ($parts -join ';'), 'User')
}

Write-Output 'Webman AOT Builder uninstalled.'
