[CmdletBinding()]
param(
    [string] $Script = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ([string]::IsNullOrWhiteSpace($Script)) {
    $Script = Join-Path (Split-Path -Parent $PSScriptRoot) 'tools\windows-replay.ps1'
}

$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $Script,
    [ref] $tokens,
    [ref] $errors
)
if ($errors.Count -ne 0) {
    throw "Windows preparation script has a PowerShell syntax error"
}
$functions = @($ast.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
    $node.Name -eq 'Get-WorkRelativePath'
}, $true))
if ($functions.Count -ne 1) {
    throw 'Windows preparation path function is missing or duplicated'
}
Invoke-Expression $functions[0].Extent.Text

$actual = Get-WorkRelativePath `
    'C:\aot\candidate\prepared' `
    'C:\aot\candidate\prepared\llvm\bin\clang++.exe'
if ($actual -ne 'llvm/bin/clang++.exe') {
    throw "prepared tool path was not made relative: $actual"
}
try {
    Get-WorkRelativePath `
        'C:\aot\candidate\prepared' `
        'C:\aot\other\clang++.exe' | Out-Null
    throw 'path escape was accepted'
} catch {
    if ($_.Exception.Message -eq 'path escape was accepted') {
        throw
    }
}
Write-Output 'PASS'
