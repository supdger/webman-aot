[CmdletBinding()]
param(
    [string] $Artifacts = '',

    [string] $WorkRoot = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-LastExitCode([string] $Step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$Step failed with exit code $LASTEXITCODE"
    }
}

function Get-LockedComponent([object] $Lock, [string] $Id) {
    $component = $Lock.components | Where-Object { $_.id -eq $Id } | Select-Object -First 1
    if ($null -eq $component) {
        throw "locked component is missing: $Id"
    }
    return $component
}

function Get-ArchivePath([object] $Component, [string] $Directory) {
    $uri = [Uri] $Component.sourceUrl
    return Join-Path $Directory ([IO.Path]::GetFileName($uri.AbsolutePath))
}

function Invoke-VerifiedDownload([object] $Component, [string] $Path) {
    $partial = "$Path.partial"
    if (-not (Test-Path -LiteralPath $partial -PathType Leaf)) {
        $legacyPartial = Get-ChildItem `
            -LiteralPath (Split-Path -Parent $Path) `
            -Filter "$([IO.Path]::GetFileName($Path)).partial-*" `
            -File |
            Sort-Object Length -Descending |
            Select-Object -First 1
        if ($null -ne $legacyPartial) {
            Move-Item -LiteralPath $legacyPartial.FullName -Destination $partial
        }
    }

    $curl = Get-Command 'curl.exe' -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -ne $curl) {
        & $curl.Source `
            '--fail' `
            '--location' `
            '--retry' '5' `
            '--retry-delay' '2' `
            '--retry-all-errors' `
            '--connect-timeout' '30' `
            '--continue-at' '-' `
            '--output' $partial `
            $Component.sourceUrl
        Assert-LastExitCode "resumable download for $($Component.id)"
    } else {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                Remove-Item -LiteralPath $partial -Force -ErrorAction SilentlyContinue
                Invoke-WebRequest `
                    -UseBasicParsing `
                    -Uri $Component.sourceUrl `
                    -OutFile $partial
                break
            } catch {
                if ($attempt -eq 3) {
                    throw
                }
                Start-Sleep -Seconds (2 * $attempt)
            }
        }
    }

    $downloadDigest = (Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($downloadDigest -ne ([string] $Component.sha256).ToLowerInvariant()) {
        Remove-Item -LiteralPath $partial -Force
        throw "downloaded archive digest mismatch: $partial"
    }
    Move-Item -LiteralPath $partial -Destination $Path
    Get-ChildItem `
        -LiteralPath (Split-Path -Parent $Path) `
        -Filter "$([IO.Path]::GetFileName($Path)).partial-*" `
        -File |
        Remove-Item -Force
}

function Resolve-LockedArchive([object] $Component, [string] $Directory) {
    $path = Get-ArchivePath $Component $Directory
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        Write-Host "Downloading $($Component.id) ..."
        Invoke-VerifiedDownload $Component $path
    }
    $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne ([string] $Component.sha256).ToLowerInvariant()) {
        throw "locked archive digest mismatch: $path"
    }
    return $path
}

function Get-SingleDirectory([string] $Directory, [string] $Description) {
    $directories = @(Get-ChildItem -LiteralPath $Directory -Directory)
    if ($directories.Count -ne 1) {
        throw "$Description must contain exactly one directory: $Directory"
    }
    return $directories[0].FullName
}

if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT -or
    [Environment]::Is64BitOperatingSystem -ne $true) {
    throw 'windows-replay.ps1 requires a Windows x64 host'
}

$repository = Split-Path -Parent $PSScriptRoot
$systemTar = Join-Path $env:SystemRoot 'System32\tar.exe'
if (-not (Test-Path -LiteralPath $systemTar -PathType Leaf)) {
    throw "Windows system tar is unavailable: $systemTar"
}
$privateRoot = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'webman-aot'
if ([string]::IsNullOrWhiteSpace($Artifacts)) {
    $Artifacts = Join-Path $privateRoot 'artifacts'
}
if ([string]::IsNullOrWhiteSpace($WorkRoot)) {
    $runId = '{0}-{1}' -f (
        [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'),
        [Guid]::NewGuid().ToString('N').Substring(0, 8)
    )
    $WorkRoot = Join-Path (Join-Path $privateRoot 'replay') $runId
}
$Artifacts = [IO.Path]::GetFullPath($Artifacts)
$WorkRoot = [IO.Path]::GetFullPath($WorkRoot)
if (-not (Test-Path -LiteralPath $Artifacts -PathType Container)) {
    New-Item -ItemType Directory -Path $Artifacts | Out-Null
}
if (Test-Path -LiteralPath $WorkRoot) {
    if ((Get-ChildItem -LiteralPath $WorkRoot -Force | Measure-Object).Count -ne 0) {
        throw "work root must not exist or must be empty: $WorkRoot"
    }
} else {
    New-Item -ItemType Directory -Path $WorkRoot | Out-Null
}

$lock = Get-Content -LiteralPath (Join-Path $repository 'toolchain.lock.json') -Raw |
    ConvertFrom-Json
$componentIds = @(
    'typephp-source',
    'typephp-windows-x64',
    'phpx-source',
    'phpx-sdk-linux-x64',
    'llvm-windows-x64',
    'alpine-musl-dev-x86-64',
    'alpine-linux-headers-x86-64',
    'alpine-libstdcpp-dev-x86-64',
    'alpine-fortify-headers-x86-64',
    'alpine-gcc-x86-64'
)
$archives = @{}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
foreach ($id in $componentIds) {
    $component = Get-LockedComponent $lock $id
    $archives[$id] = Resolve-LockedArchive $component $Artifacts
}

$hostExtract = Join-Path $WorkRoot 'typephp-host'
Write-Host 'Extracting TypePHP Windows host package ...'
New-Item -ItemType Directory -Path $hostExtract | Out-Null
& $systemTar -xf $archives['typephp-windows-x64'] -C $hostExtract
Assert-LastExitCode 'TypePHP Windows archive extraction'
$hostRoot = Get-SingleDirectory $hostExtract 'TypePHP Windows archive'
$php = Join-Path $hostRoot 'php.exe'
$sevenZip = Join-Path $hostRoot 'PhpManager\private\bin\7za-x64.exe'
foreach ($required in @($php, $sevenZip)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "official TypePHP Windows package is incomplete: $required"
    }
}

$sourceExtract = Join-Path $WorkRoot 'typephp-source'
Write-Host 'Extracting TypePHP source ...'
New-Item -ItemType Directory -Path $sourceExtract | Out-Null
& $systemTar -xf $archives['typephp-source'] -C $sourceExtract
Assert-LastExitCode 'TypePHP source extraction'
$typephpRoot = Get-SingleDirectory $sourceExtract 'TypePHP source archive'
Copy-Item -LiteralPath (Join-Path $hostRoot 'vendor') -Destination $typephpRoot -Recurse
$swooleVendor = Join-Path $typephpRoot 'vendor\swoole'
New-Item -ItemType Directory -Path $swooleVendor -Force | Out-Null

$phpxExtract = Join-Path $WorkRoot 'phpx-source'
Write-Host 'Extracting PHPX source ...'
New-Item -ItemType Directory -Path $phpxExtract | Out-Null
& $systemTar -xf $archives['phpx-source'] -C $phpxExtract
Assert-LastExitCode 'PHPX source extraction'
$phpxSource = Get-SingleDirectory $phpxExtract 'PHPX source archive'
$phpx = Join-Path $swooleVendor 'phpx'
Move-Item -LiteralPath $phpxSource -Destination $phpx

$sdkExtract = Join-Path $WorkRoot 'sdk-extract'
Write-Host 'Extracting Linux x64 PHPX SDK ...'
New-Item -ItemType Directory -Path $sdkExtract | Out-Null
& $sevenZip x '-y' "-o$sdkExtract" $archives['phpx-sdk-linux-x64'] | Out-Host
Assert-LastExitCode 'PHPX SDK xz extraction'
$sdkTar = Get-ChildItem -LiteralPath $sdkExtract -Filter '*.tar' -File |
    Select-Object -First 1
if ($null -eq $sdkTar) {
    throw 'PHPX SDK xz archive did not yield a tar file'
}
$sdkPayload = Join-Path $WorkRoot 'sdk-payload'
New-Item -ItemType Directory -Path $sdkPayload | Out-Null
& $sevenZip x '-y' "-o$sdkPayload" $sdkTar.FullName | Out-Host
Assert-LastExitCode 'PHPX SDK tar extraction'
$sdkSource = Get-SingleDirectory $sdkPayload 'PHPX SDK archive'
$fullStatic = Join-Path $phpx 'full-static'
New-Item -ItemType Directory -Path $fullStatic -Force | Out-Null
$sdk = Join-Path $fullStatic 'sdk'
Move-Item -LiteralPath $sdkSource -Destination $sdk

$llvmRoot = Join-Path $WorkRoot 'llvm'
Write-Host 'Installing private LLVM toolchain into isolated work root ...'
& $archives['llvm-windows-x64'] '/S' "/D=$llvmRoot"
Assert-LastExitCode 'LLVM silent private installation'
$compiler = Join-Path $llvmRoot 'bin\clang++.exe'
$llvmNm = Join-Path $llvmRoot 'bin\llvm-nm.exe'
$llvmObjcopy = Join-Path $llvmRoot 'bin\llvm-objcopy.exe'
$llvmDeadline = [DateTime]::UtcNow.AddMinutes(3)
$compilerVersion = ''
$llvmReady = $false
while ([DateTime]::UtcNow -lt $llvmDeadline) {
    if (
        (Test-Path -LiteralPath $compiler -PathType Leaf) -and
        (Test-Path -LiteralPath $llvmNm -PathType Leaf) -and
        (Test-Path -LiteralPath $llvmObjcopy -PathType Leaf)
    ) {
        $compilerVersion = (& $compiler --version 2>$null | Select-Object -First 1)
        if ($compilerVersion -match '^clang version 19\.1\.7(?:\s|$)') {
            $llvmReady = $true
            break
        }
    }
    Start-Sleep -Seconds 2
}
if (-not $llvmReady) {
    throw "locked LLVM package did not become executable as version 19.1.7: $compilerVersion"
}

Write-Host 'Stripping debug sections from the private SDK work copy ...'
$strippedSdk = & $php (Join-Path $repository 'tools\strip-sdk-debug.php') `
    "--sdk=$sdk" `
    "--objcopy=$llvmObjcopy" |
    ConvertFrom-Json
Assert-LastExitCode 'private SDK debug stripping'
if ($strippedSdk.sha256 -ne 'bc4b4053092176f8e046a5db0b66c659daa4f23468c09c982223d4fea24d7eeb') {
    throw "stripped SDK digest mismatch: $($strippedSdk.sha256)"
}
$strippedSdk | ConvertTo-Json -Depth 4 | Write-Host

$phpConfig = Join-Path $WorkRoot 'php-config'
New-Item -ItemType Directory -Path $phpConfig | Out-Null
$phpIni = Get-Content -LiteralPath (Join-Path $hostRoot 'php.ini') -Raw
$absoluteExtensionDirectory = (Join-Path $hostRoot 'ext').Replace('\', '/')
$phpIni = $phpIni -replace 'extension_dir = "\./ext/"', "extension_dir = `"$absoluteExtensionDirectory/`""
[IO.File]::WriteAllText(
    (Join-Path $phpConfig 'php.ini'),
    $phpIni,
    [System.Text.UTF8Encoding]::new($false)
)
$env:PHP_HOME = $hostRoot
$env:PHPX_HOME = $phpx
$env:PHPRC = $phpConfig
$env:PATH = "$hostRoot;$(Split-Path -Parent $compiler);$env:PATH"

Write-Host 'Applying guarded TypePHP/PHPX patches ...'
& $php (Join-Path $repository 'tools\apply-typephp-patches.php') `
    "--typephp=$typephpRoot" `
    "--phpx=$phpx"
Assert-LastExitCode 'TypePHP patch application'

$sysroot = Join-Path $WorkRoot 'sysroot'
Write-Host 'Assembling locked Linux x86-64 musl sysroot ...'
& $php (Join-Path $repository 'tools\assemble-sysroot.php') `
    "--artifacts=$Artifacts" `
    "--output=$sysroot" `
    "--tar=$systemTar"
Assert-LastExitCode 'musl sysroot assembly'

Write-Host 'Calculating normalized reproducibility input ...'
$normalizedInput = & $php (Join-Path $repository 'tools\reproducibility-input.php') |
    ConvertFrom-Json
Assert-LastExitCode 'normalized input calculation'

$output = Join-Path $WorkRoot 'probe'
Write-Host 'Building full-static Linux x86-64 probe ...'
& $php (Join-Path $repository 'tools\build-full-static-smoke.php') `
    "--typephp=$typephpRoot" `
    "--php=$php" `
    "--phpx=$phpx" `
    "--compiler=$compiler" `
    "--sysroot=$sysroot" `
    "--sdk=$sdk" `
    "--output=$output"
Assert-LastExitCode 'Windows full-static replay'

$artifact = Join-Path $output 'full_static_cross_smoke'
$result = [ordered] @{
    schema = 'webman-aot-windows-replay-v1'
    host = 'windows-x86_64'
    containerUsed = $false
    normalizedInputSha256 = $normalizedInput.sha256
    expectedNormalizedInputSha256 = '579b865e9fab8b916d74aef2f9d55a7edea46108a490af568e1e4d0cf82c88b4'
    matchesMacNormalizedInput = $false
    artifact = $artifact
    artifactSize = (Get-Item -LiteralPath $artifact).Length
    artifactSha256 = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash.ToLowerInvariant()
    expectedMacArtifactSha256 = 'e7f63178b53b4ce82f934965a190bb5dd851f5ff646ec22230bdc8a2809e0017'
    matchesMacArtifact = $false
}
$result.matchesMacNormalizedInput = (
    $result.normalizedInputSha256 -eq $result.expectedNormalizedInputSha256
)
$result.matchesMacArtifact = $result.artifactSha256 -eq $result.expectedMacArtifactSha256
$evidence = Join-Path $WorkRoot 'windows-replay.json'
[IO.File]::WriteAllText(
    $evidence,
    ($result | ConvertTo-Json -Depth 8),
    [System.Text.UTF8Encoding]::new($false)
)
$result | ConvertTo-Json -Depth 8
if (-not $result.matchesMacNormalizedInput) {
    throw "Windows normalized input SHA-256 differs from Mac baseline; evidence: $evidence"
}
if (-not $result.matchesMacArtifact) {
    throw "Windows ELF SHA-256 differs from Mac baseline; evidence: $evidence"
}
