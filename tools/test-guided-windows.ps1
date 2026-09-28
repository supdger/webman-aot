[CmdletBinding()]
param(
    [string]$ProjectFixture,
    [Parameter(Mandatory = $true)][string]$WorkRoot,
    [ValidateRange(30, 3600)][int]$StepTimeoutSeconds = 1800
)

# Native acceptance only. Default fixture is maintained source plus composer.lock;
# its vendor is prepared privately with verified Composer and this build's PHP.
# A supplied fixture is copied, never installed into. No services/database/global install.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$utf8 = [Text.UTF8Encoding]::new($false)
$repository = Split-Path -Parent $PSScriptRoot
$records = [Collections.Generic.List[object]]::new()
$savedEnvironment = @{}
$pathDigests = @{}
$failure = $null
$tlsProcess = $null
$tlsStarted = $false
$tlsPort = $null
$crlProcess = $null
$crlStarted = $false
$crlPort = $null
$httpsAccepted = $false
$started = [Diagnostics.Stopwatch]::StartNew()

function Get-PathDigest([string]$Target) {
    $value = [Environment]::GetEnvironmentVariable('Path', $Target)
    if ($null -eq $value) { $value = '<unset>' }
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($utf8.GetBytes($value)))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Assert-Check([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
    Write-Host "[PASS] $Message"
}

function Write-Json([string]$Path, $Value) {
    [IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json -Depth 12), $utf8)
}

function Quote-Cmd([string]$Value) {
    # All test-owned paths and command arguments are literal; refuse CMD syntax.
    if ($Value -match '["%&|<>^!\r\n]') { throw "Unsupported CMD metacharacter in test argument: $Value" }
    return '"' + $Value + '"'
}

function Invoke-Cmd(
    [string]$Name, [string]$Entry, [string[]]$Arguments = @(),
    [string]$InputText = '', [bool]$ExpectSuccess = $true,
    [string]$WorkingDirectory = $repository,
    [ValidateRange(30, 3600)][int]$TimeoutSeconds = $StepTimeoutSeconds
) {
    $wrapper = Join-Path $logs ($Name + '.cmd')
    $line = 'call ' + (Quote-Cmd $Entry)
    foreach ($argument in $Arguments) { $line += ' ' + (Quote-Cmd $argument) }
    [IO.File]::WriteAllText($wrapper, "@echo off`r`nchcp 65001 >nul`r`n$line`r`nexit /b %errorlevel%`r`n", $utf8)
    $log = Join-Path $logs ($Name + '.log')
    $writer = [IO.StreamWriter]::new($log, $false, $utf8)
    $writer.AutoFlush = $true
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = Join-Path $env:SystemRoot 'System32\cmd.exe'
    $info.Arguments = '/d /s /c "' + (Quote-Cmd $wrapper) + '"'
    $info.WorkingDirectory = $WorkingDirectory
    $info.UseShellExecute = $false
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = $utf8
    $info.StandardErrorEncoding = $utf8
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $text = [Text.StringBuilder]::new()
    $stdout = [Text.StringBuilder]::new()
    $didStart = $false
    Write-Host "[STEP] $Name; deadline ${TimeoutSeconds}s; log $log"
    try {
        # .NET Framework constructs and flushes its stdin writer during Start.
        $previousInputEncoding = [Console]::InputEncoding
        try {
            [Console]::InputEncoding = $utf8
            $didStart = $process.Start()
        } finally { [Console]::InputEncoding = $previousInputEncoding }
        # Send every menu choice before closing stdin. EOF is intentional.
        $inputStream = $process.StandardInput.BaseStream
        if ($InputText) {
            $inputBytes = $utf8.GetBytes($InputText)
            $inputStream.Write($inputBytes, 0, $inputBytes.Length)
            $inputStream.Flush()
        }
        $inputStream.Close()
        $buffers = @([char[]]::new(4096), [char[]]::new(4096))
        $readers = @($process.StandardOutput, $process.StandardError)
        $pending = @(
            $readers[0].ReadAsync($buffers[0], 0, 4096),
            $readers[1].ReadAsync($buffers[1], 0, 4096)
        )
        $done = @($false, $false)
        $lastStatus = 0.0
        while (-not ($done[0] -and $done[1] -and $process.HasExited)) {
            for ($index = 0; $index -lt 2; $index++) {
                if (-not $done[$index] -and $pending[$index].IsCompleted) {
                    $length = $pending[$index].GetAwaiter().GetResult()
                    if ($length -eq 0) { $done[$index] = $true; continue }
                    $chunk = [string]::new($buffers[$index], 0, $length)
                    [Console]::Write($chunk)
                    $writer.Write($chunk)
                    [void]$text.Append($chunk)
                    if ($index -eq 0) { [void]$stdout.Append($chunk) }
                    $pending[$index] = $readers[$index].ReadAsync($buffers[$index], 0, 4096)
                }
            }
            if ($timer.Elapsed.TotalSeconds -ge $TimeoutSeconds) {
                # Kill only this test-owned command tree; never stop a service.
                & (Join-Path $env:SystemRoot 'System32\taskkill.exe') /PID $process.Id /T /F | Out-Host
                throw "$Name exceeded its deadline; command tree stopped."
            }
            if ($timer.Elapsed.TotalSeconds - $lastStatus -ge 5) {
                Write-Host ("[STATUS] {0}: {1:N1}s elapsed; command PID {2}" -f $Name, $timer.Elapsed.TotalSeconds, $process.Id)
                $lastStatus = $timer.Elapsed.TotalSeconds
            }
            Start-Sleep -Milliseconds 25
        }
        $process.WaitForExit()
        $record = [pscustomobject]@{
            stage = $Name; exitCode = $process.ExitCode
            seconds = [Math]::Round($timer.Elapsed.TotalSeconds, 2); log = $log
        }
        $records.Add($record)
        Write-Host ("[RESULT] {0}: exit {1}; {2:N1}s" -f $Name, $process.ExitCode, $timer.Elapsed.TotalSeconds)
        $success = $process.ExitCode -eq 0
        if ($success -ne $ExpectSuccess) { throw "$Name returned unexpected exit code $($process.ExitCode); see $log" }
        return [pscustomobject]@{ Code = $process.ExitCode; Text = $text.ToString(); Stdout = $stdout.ToString() }
    } finally {
        if ($didStart -and -not $process.HasExited) {
            & (Join-Path $env:SystemRoot 'System32\taskkill.exe') /PID $process.Id /T /F | Out-Host
        }
        $writer.Dispose()
        $process.Dispose()
    }
}

function Record-RuntimeInventory([string]$Name, [string]$RuntimeRoot,
    [string]$ReportedHome, [string]$WorkingDirectory, [bool]$UseBootstrap = $false) {
    # Preserve the caller's exact mixed/native separator spelling for -c/-d.
    $runtimePhp = $RuntimeRoot + '\php.exe'
    $runtimeIni = $RuntimeRoot + '\php.ini'
    $runtimeExt = $RuntimeRoot + '\ext'
    $probe = Join-Path $logs 'runtime-probe.php'
    $output = Join-Path $logs ($Name + '-php.json')
    $fileOutput = Join-Path $logs ($Name + '-files.json')
    Write-Host "[inventory] Preparing fixed $Name probe; all runtime reads run with a 60s deadline."
    $probeSource = @'
<?php
$files = [];
foreach ([$argv[3] . '\php.exe', $argv[4], $argv[5] . '\php_zip.dll'] as $file) {
    $files[$file] = is_file($file) ? hash_file('sha256', $file) : null;
}
$metadata = [
    'runtimeArgument' => $argv[3], 'homeForProbe' => $argv[6],
    'iniArgument' => $argv[4], 'extensionArgument' => $argv[5], 'files' => $files,
    'iniText' => file_get_contents($argv[4]),
    'scope' => 'Unchanged package runtime/configuration; probe does not replace actual failed build.',
];
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
if (file_put_contents($argv[2], json_encode($metadata, $flags) . "\n") === false) { exit(1); }
$data = [
    'phpBinary' => PHP_BINARY, 'phpVersion' => PHP_VERSION,
    'loadedIni' => php_ini_loaded_file(), 'scannedIni' => php_ini_scanned_files(),
    'extensionDir' => ini_get('extension_dir'), 'zipLoaded' => extension_loaded('zip'),
    'zipArchiveExists' => class_exists('ZipArchive'), 'extensions' => get_loaded_extensions(),
    'builderHome' => getenv('WEBMAN_AOT_BUILDER_HOME'), 'cwd' => getcwd(),
    'relativeInputExists' => isset($argv[7]) ? is_file($argv[7]) : null,
];
$json = json_encode($data, $flags);
if (file_put_contents($argv[1], $json . "\n") === false) { exit(1); }
echo $json, "\n";
'@
    [IO.File]::WriteAllText($probe, $probeSource, $utf8)
    $previousHome = $env:WEBMAN_AOT_BUILDER_HOME
    $previousCallerDirectory = $env:WEBMAN_AOT_CALLER_CWD
    try {
        $env:WEBMAN_AOT_BUILDER_HOME = $ReportedHome
        $probeArguments = @($probe, $output, $fileOutput, $RuntimeRoot, $runtimeIni, $runtimeExt, $ReportedHome)
        if ($UseBootstrap) {
            $env:WEBMAN_AOT_CALLER_CWD = $WorkingDirectory
            [void](Invoke-Cmd $Name '.\php.exe' (@('-c', 'php.ini', '-d', 'extension_dir=ext',
                '..\app\tools\windows-php-bootstrap.php') + $probeArguments + @('composer.json')) '' $true $RuntimeRoot 60)
            $state = [IO.File]::ReadAllText($output, $utf8) | ConvertFrom-Json
            Assert-Check ($state.loadedIni -and $state.zipLoaded -and $state.zipArchiveExists) 'Relative runtime configuration loads ZIP and ZipArchive'
            Assert-Check ([IO.Path]::GetFullPath($state.cwd) -eq [IO.Path]::GetFullPath($WorkingDirectory) -and
                $state.relativeInputExists) 'Runtime bootstrap restores the Chinese caller directory and relative project input'
        } else {
            [void](Invoke-Cmd $Name $runtimePhp (@('-c', $runtimeIni, '-d',
                ('extension_dir=' + $runtimeExt)) + $probeArguments) '' $true $WorkingDirectory 60)
        }
    } finally {
        $env:WEBMAN_AOT_BUILDER_HOME = $previousHome
        $env:WEBMAN_AOT_CALLER_CWD = $previousCallerDirectory
    }
}

function Record-MenuInput([string]$RuntimeRoot, [string]$CallerDirectory) {
    $fixedInput = "2`n1`n0`n"
    $inputHex = [BitConverter]::ToString($utf8.GetBytes($fixedInput)).Replace('-', '').ToLowerInvariant()
    Write-Host "[diagnostic] Fixed fixture stdin written as UTF-8 without BOM: $inputHex"
    $probe = Join-Path $WorkRoot 'menu-input-probe.php'
    $source = @'
<?php
if (!stream_isatty(STDIN)) { stream_set_blocking(STDIN, false); }
$lines = [];
for ($index = 0; $index < 3; $index++) {
    $line = fgets(STDIN);
    $lines[] = $line === false ? null : bin2hex($line);
}
echo 'NATIVE_INPUT_HEX=', json_encode($lines), "\n";
'@
    [IO.File]::WriteAllText($probe, $source, $utf8)
    $original = [IO.File]::ReadAllText((Join-Path $repository 'tools\build-windows-installer.ps1'), $utf8)
    $repositoryStatement = '$repository = Split-Path -Parent $PSScriptRoot'
    $guidedTarget = "(Join-Path `$repository 'tools\guided.php')"
    Assert-Check ([regex]::Matches($original, [regex]::Escape($repositoryStatement)).Count -eq 1 -and
        [regex]::Matches($original, [regex]::Escape($guidedTarget)).Count -eq 1) 'Input diagnostic matches exactly its two fixed source substitutions'
    $copy = $original.Replace($repositoryStatement, ('$repository = ' + "'" + $repository.Replace("'", "''") + "'"))
    $copy = $copy.Replace($guidedTarget, ("'" + $probe.Replace("'", "''") + "'"))
    $sourceCopy = Join-Path $WorkRoot 'menu-input-source.ps1'
    [IO.File]::WriteAllText($sourceCopy, $copy, [Text.UTF8Encoding]::new($true))
    $entry = Join-Path $WorkRoot 'menu-input-source.cmd'
    [IO.File]::WriteAllText($entry, "@echo off`r`npowershell.exe -NoProfile -ExecutionPolicy Bypass -File " +
        (Quote-Cmd $sourceCopy) + " -Guided -NoPath`r`nexit /b %errorlevel%`r`n", $utf8)
    $sourceProbe = Invoke-Cmd 'menu-input-source-bootstrap' $entry @() $fixedInput $true $CallerDirectory 60
    $previousCallerDirectory = $env:WEBMAN_AOT_CALLER_CWD
    try {
        $env:WEBMAN_AOT_CALLER_CWD = $CallerDirectory
        $directProbe = Invoke-Cmd 'menu-input-runtime-direct' '.\php.exe' @('-c', 'php.ini', '-d',
            'extension_dir=ext', '..\app\tools\windows-php-bootstrap.php', $probe) $fixedInput $true $RuntimeRoot 60
    } finally { $env:WEBMAN_AOT_CALLER_CWD = $previousCallerDirectory }
    foreach ($capturedRun in @($sourceProbe, $directProbe)) {
        $hexMatch = [regex]::Match($capturedRun.Stdout, 'NATIVE_INPUT_HEX=(\[[^\r\n]+\])')
        Assert-Check $hexMatch.Success 'Native input diagnostic reports the fixed fixture bytes'
        $actualLines = $hexMatch.Groups[1].Value | ConvertFrom-Json
        Assert-Check (($actualLines -join ',') -eq '320a,310a,300a') 'Native input preserves all three fixture lines without a BOM'
    }
}

function Copy-Project([string]$Name) {
    $destination = Join-Path $WorkRoot $Name
    New-Item -ItemType Directory -Path $destination | Out-Null
    Get-ChildItem -LiteralPath $ProjectFixture -Force | Where-Object {
        $_.Name -notin @('.git', 'dist-aot', '.webman-aot', 'runtime')
    } | Copy-Item -Destination $destination -Recurse
    return $destination
}

function Remove-OwnedDirectory([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    if (-not $absolute.StartsWith($WorkRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Cleanup refused a path outside WorkRoot.'
    }
    if (Test-Path -LiteralPath $absolute) {
        Write-Host "[cleanup] Removing successful test-owned installation: $absolute"
        Remove-Item -LiteralPath ('\\?\' + $absolute) -Recurse -Force
        Assert-Check (-not (Test-Path -LiteralPath $absolute)) 'Task-owned installation removed'
    }
}

function Read-SourceResult([string]$Flavor, [string[]]$Previous) {
    $results = @(Get-ChildItem -LiteralPath $temp -Recurse -Filter source-result.json -File |
        Where-Object { $_.FullName -notin $Previous })
    Assert-Check ($results.Count -eq 1) "$Flavor source entry preserved exactly one successful result"
    $result = Get-Content -Raw -LiteralPath $results[0].FullName | ConvertFrom-Json
    Assert-Check ($result.schema -eq 'webman-aot-builder-source-build-result-v1' -and
        $result.platform -eq 'windows-x86_64' -and $result.flavor -eq $Flavor) "$Flavor result identity"
    Assert-Check ([IO.Path]::IsPathRooted($result.archive) -and
        (Test-Path -LiteralPath $result.archive -PathType Leaf)) "$Flavor archive is an actual absolute path"
    Assert-Check ((Get-Item -LiteralPath $result.archive).Length -eq $result.size -and
        (Get-FileHash -LiteralPath $result.archive -Algorithm SHA256).Hash.ToLowerInvariant() -eq $result.sha256) "$Flavor outer archive size and SHA-256"
    Assert-Check ('payload-manifest' -in $result.verified -and
        'isolated-install-version' -in $result.verified) "$Flavor backend completed real isolated self-check"
    Copy-Item -LiteralPath $results[0].FullName -Destination (Join-Path $logs "$Flavor-source-result.json")
    return $result
}

try {
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT -or
        -not [Environment]::Is64BitOperatingSystem) { throw 'This test requires native Windows x64.' }
    $suppliedFixture = -not [string]::IsNullOrWhiteSpace($ProjectFixture)
    if (-not $suppliedFixture) { $ProjectFixture = Join-Path $repository 'tools\fixtures\guided-webman' }
    $ProjectFixture = [IO.Path]::GetFullPath($ProjectFixture)
    $WorkRoot = [IO.Path]::GetFullPath($WorkRoot)
    [void](Quote-Cmd $ProjectFixture)
    [void](Quote-Cmd $WorkRoot)
    if (Test-Path -LiteralPath $WorkRoot) { throw 'WorkRoot must be a new, test-owned directory.' }
    if (-not (Test-Path -LiteralPath $ProjectFixture -PathType Container)) { throw 'A supplied real Webman fixture is required; no stub fallback.' }
    $links = @(Get-Item -LiteralPath $ProjectFixture; Get-ChildItem -LiteralPath $ProjectFixture -Force -Recurse) |
        Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }
    if (@($links).Count) { throw 'Project fixture must not contain links/reparse points.' }
    foreach ($required in @('composer.json', 'composer.lock', 'start.php', 'app')) {
        if (-not (Test-Path -LiteralPath (Join-Path $ProjectFixture $required))) { throw "Real fixture is missing $required" }
    }
    $compatibility = Get-Content -Raw -LiteralPath (Join-Path $repository 'compatibility\locks\webman-workerman-2026-09-25.json') | ConvertFrom-Json
    # Composer permits case-distinct PSR-4 keys (Support\ / support\).
    # Windows PowerShell ConvertFrom-Json rejects them; preserve dictionary keys.
    Add-Type -AssemblyName System.Web.Extensions
    $reader = [Web.Script.Serialization.JavaScriptSerializer]::new()
    $projectLock = $reader.DeserializeObject((Get-Content -Raw -LiteralPath (Join-Path $ProjectFixture 'composer.lock')))
    foreach ($locked in $compatibility.packages.PSObject.Properties) {
        $lockedPackages = @($projectLock['packages'] | Where-Object { $_['name'] -eq $locked.Name })
        if ($lockedPackages.Count -ne 1 -or $lockedPackages[0]['version'] -ne $locked.Value.version -or
            $lockedPackages[0]['source']['reference'] -ne $locked.Value.reference) { throw "Fixture must match repository lock: $($locked.Name)" }
    }
    New-Item -ItemType Directory -Path $WorkRoot | Out-Null
    $logs = Join-Path $WorkRoot 'logs'
    $temp = Join-Path $WorkRoot 'temp'
    New-Item -ItemType Directory -Path $logs, $temp | Out-Null
    foreach ($target in @('User', 'Machine')) { $pathDigests[$target] = Get-PathDigest $target }
    foreach ($name in @('TEMP', 'TMP', 'LOCALAPPDATA', 'WEBMAN_AOT_BUILDER_HOME',
        'WEBMAN_AOT_NO_PAUSE', 'Path', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'CURL_HOME', 'NO_PROXY')) {
        $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }
    $env:TEMP = $temp
    $env:TMP = $temp
    $env:LOCALAPPDATA = Join-Path $WorkRoot 'cache'
    $env:WEBMAN_AOT_BUILDER_HOME = Join-Path $WorkRoot 'unused default home'
    $env:WEBMAN_AOT_NO_PAUSE = '1'
    $env:COMPOSER_HOME = Join-Path $WorkRoot 'composer-home'
    $env:COMPOSER_CACHE_DIR = Join-Path $WorkRoot 'composer-cache'
    $git = Get-Command git.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    Assert-Check ($null -ne $git) 'Runner provides existing Git for native source build'
    $python = Get-Command python.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1
    $openssl = Join-Path (Split-Path -Parent (Split-Path -Parent $git.Source)) 'usr\bin\openssl.exe'
    Assert-Check (Test-Path -LiteralPath $openssl -PathType Leaf) 'Runner Git provides existing OpenSSL for task-only TLS files'
    # Process PATH uses Windows tools and optional existing Git, never system PHP.
    $env:Path = (@("$env:SystemRoot\System32", $env:SystemRoot,
        "$env:SystemRoot\System32\WindowsPowerShell\v1.0") +
        $(if ($git) { @(Split-Path -Parent $git.Source) } else { @() })) -join ';'
    Assert-Check ($null -eq (Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue)) 'No system PHP available to entries'
    $smoke = Join-Path $WorkRoot 'native helper 中文.cmd'
    [IO.File]::WriteAllText($smoke, "@echo off`r`necho NATIVE_HELPER_READY`r`necho %~1`r`nexit /b 0`r`n", $utf8)
    $helper = Invoke-Cmd 'native-helper-smoke' $smoke @('中文 spaced argument')
    Assert-Check ($helper.Stdout.Contains('NATIVE_HELPER_READY') -and $helper.Stdout.Contains('中文 spaced argument')) 'Native CMD Unicode path/argument, EOF, stream and exit APIs execute before source bootstrap'
    $fixture = Copy-Project 'fixture source'
    $ProjectFixture = $fixture
    if (-not $suppliedFixture) {
        # Bootstrap through the real source entry; no global runtime prerequisite.
        [void](Invoke-Cmd 'fixture-runtime-source' (Join-Path $repository 'build.cmd') @('-Flavor', 'small', '-NoPath'))
        $bootstrap = Read-SourceResult 'small' @()
        $runtimePackage = Join-Path $WorkRoot 'fixture runtime'
        New-Item -ItemType Directory -Path $runtimePackage | Out-Null
        & (Join-Path $env:SystemRoot 'System32\tar.exe') -xf $bootstrap.archive -C $runtimePackage
        if ($LASTEXITCODE -ne 0) { throw "Private fixture runtime extraction failed: $LASTEXITCODE" }
        $runtime = Join-Path $runtimePackage 'payload\runtime'
        $runtimeLock = (Get-Content -Raw -LiteralPath (Join-Path $repository 'installer\runtime.lock.json') | ConvertFrom-Json).runtimes.'windows-x86_64'
        $php = Join-Path $runtime 'php.exe'
        Assert-Check ((Get-FileHash -Algorithm SHA256 -LiteralPath $php).Hash.ToLowerInvariant() -eq
            $runtimeLock.binarySha256) 'Fixture PHP binary matches the product runtime lock'
        $composer = Join-Path $WorkRoot 'composer-2.9.5.phar'
        # Authority checked 2026-09-28:
        # https://getcomposer.org/download/2.9.5/composer.phar.sha256sum
        $composerSha256 = 'c86ce603fe836bf0861a38c93ac566c8f1e69ac44b2445d9b7a6a17ea2e9972a'
        [void](Invoke-Cmd 'fixture-composer-download' (Join-Path $env:SystemRoot 'System32\curl.exe') @(
            '--fail', '--location', '--no-progress-bar', '--no-silent', '--progress-meter',
            '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '30', '--max-time', '600',
            '--output', $composer, 'https://getcomposer.org/download/2.9.5/composer.phar'
        ))
        Assert-Check ((Get-FileHash -Algorithm SHA256 -LiteralPath $composer).Hash.ToLowerInvariant() -eq
            $composerSha256) 'Composer 2.9.5 matches its official SHA-256 before execution'
        $lockBefore = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $fixture 'composer.lock')).Hash
        [void](Invoke-Cmd 'fixture-locked-install' $php @(
            '-c', (Join-Path $runtime 'php.ini'), '-d', ('extension_dir=' + (Join-Path $runtime 'ext')),
            $composer, 'install', '--no-plugins', '--no-scripts', '--no-interaction', '--prefer-dist', '--no-dev'
        ) '' $true $fixture)
        Assert-Check ((Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $fixture 'composer.lock')).Hash -eq
            $lockBefore) 'Fixture dependency installation did not change composer.lock'
        Record-RuntimeInventory 'runtime-ascii' $runtime $env:WEBMAN_AOT_BUILDER_HOME $fixture
        Remove-OwnedDirectory $runtimePackage
    }
    foreach ($required in @('vendor\autoload.php', 'vendor\workerman\webman-framework\src\support\bootstrap.php')) {
        Assert-Check (Test-Path -LiteralPath (Join-Path $fixture $required) -PathType Leaf) "Real fixture contains $required; no stub fallback"
    }
    Write-Json (Join-Path $logs 'platform.json') @{
        platform = [Environment]::OSVersion.ToString(); powershell = $PSVersionTable.PSVersion.ToString()
        architecture = $env:PROCESSOR_ARCHITECTURE; pathBefore = $pathDigests; fixtureLockSha256 =
        (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $ProjectFixture 'composer.lock')).Hash.ToLowerInvariant()
        scope = 'native entries, real fixture compilation and build-host verification; no Linux deployment'
    }

    $archives = @{}
    foreach ($flavor in @('small', 'full')) {
        $installHome = Join-Path $WorkRoot "工具 $flavor home"
        $bin = Join-Path $WorkRoot "命令 $flavor bin"
        $before = @(Get-ChildItem -LiteralPath $temp -Recurse -Filter source-result.json -File | ForEach-Object FullName)
        $arguments = @('-InstallRoot', $installHome, '-BinDir', $bin, '-NoPath')
        if ($flavor -eq 'small') {
            $project = Copy-Project "项目 $flavor 中文"
            $arguments += @('-Flavor', 'small', '-Install', '-Project', $project)
            $inputText = ''
        } else { $inputText = "2`n1`n0`n" }
        $run = Invoke-Cmd "source-$flavor" (Join-Path $repository 'build.cmd') $arguments $inputText
        Assert-Check (Test-Path -LiteralPath (Join-Path $installHome 'current\runtime\php.exe')) "$flavor source entry installed to its private home"
        if ($flavor -eq 'small') {
            Assert-Check ($run.Text.Contains('项目构建与校验成功') -and
                (Test-Path -LiteralPath (Join-Path $project 'dist-aot') -PathType Container)) 'Source entry built and verified the real fixture'
            Record-RuntimeInventory 'runtime-chinese-relative' (Join-Path $installHome 'current\runtime') $installHome $project $true
            Record-MenuInput (Join-Path $installHome 'current\runtime') $project
        }
        $archives[$flavor] = Read-SourceResult $flavor $before
        $package = Join-Path $WorkRoot "安装包 $flavor 中文"
        New-Item -ItemType Directory -Path $package | Out-Null
        Write-Host "[STEP] Extracting verified $flavor ZIP to $package"
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [IO.Compression.ZipFile]::ExtractToDirectory($archives[$flavor].archive, $package)
        Write-Host "[RESULT] Verified $flavor ZIP extracted to its Chinese task-owned directory"
        $archives[$flavor] | Add-Member -NotePropertyName package -NotePropertyValue $package
        if ($flavor -eq 'small') { Remove-OwnedDirectory $installHome; Remove-OwnedDirectory $bin }
    }
    $eof = Invoke-Cmd 'source-eof' (Join-Path $repository 'build.cmd')
    Assert-Check ($eof.Text.Contains('已取消构包')) 'Source EOF cancels after private bootstrap'
    $cancel = Invoke-Cmd 'source-invalid-cancel' (Join-Path $repository 'build.cmd') @() "bad`n0`n"
    Assert-Check ($cancel.Text.Contains('选择无效') -and $cancel.Text.Contains('已取消构包')) 'Source invalid selection retries and cancels'

    foreach ($flavor in @('small', 'full')) {
        $installHome = Join-Path $WorkRoot "package $flavor home"
        $bin = Join-Path $WorkRoot "package $flavor bin"
        $entry = Join-Path $archives[$flavor].package 'install.cmd'
        $common = @('-InstallRoot', $installHome, '-BinDir', $bin, '-NoPath')
        [void](Invoke-Cmd "package-$flavor-eof" $entry $common)
        Assert-Check (-not (Test-Path -LiteralPath $installHome)) "$flavor package EOF does not install"
        [void](Invoke-Cmd "package-$flavor-cancel" $entry $common "0`n")
        Assert-Check (-not (Test-Path -LiteralPath $installHome)) "$flavor package cancellation does not install"
        if ($flavor -eq 'full') {
            $project = Copy-Project "package $flavor 项目"
            $run = Invoke-Cmd "package-$flavor-project-menu" $entry $common "bad`n1`n1`n$project`n"
            Assert-Check ($run.Text.Contains('选择无效') -and $run.Text.Contains('项目构建与校验成功')) 'Full package menu installs, builds and verifies'
        } else {
            [void](Invoke-Cmd 'package-small-install' $entry ($common + @('-Install')))
            Assert-Check (Test-Path -LiteralPath (Join-Path $installHome 'current\runtime\php.exe')) 'Small package explicitly installs to private home'
        }
        Remove-OwnedDirectory $installHome
        Remove-OwnedDirectory $bin
    }
    $badProject = Invoke-Cmd 'package-project-failure' (Join-Path $archives.small.package 'install.cmd') @(
        '-Install', '-InstallRoot', (Join-Path $WorkRoot 'failure home'), '-BinDir', (Join-Path $WorkRoot 'failure bin'),
        '-NoPath', '-Project', (Join-Path $WorkRoot 'missing project')
    ) '' $false
    Assert-Check (-not $badProject.Text.Contains('校验本次项目产物') -and $badProject.Text.Contains('/issues')) 'Project failure stops downstream verification with Issues guidance'
    $damaged = Join-Path $WorkRoot 'damaged package'
    Copy-Item -LiteralPath $archives.small.package -Destination $damaged -Recurse
    Add-Content -LiteralPath (Join-Path $damaged 'payload\app\LICENSE') -Value 'intentional acceptance corruption'
    [void](Invoke-Cmd 'package-manifest-failure' (Join-Path $damaged 'install.cmd') @(
        '-Install', '-InstallRoot', (Join-Path $WorkRoot 'damaged home'), '-BinDir', (Join-Path $WorkRoot 'damaged bin'), '-NoPath'
    ) '' $false)
    Assert-Check (-not (Test-Path -LiteralPath (Join-Path $WorkRoot 'damaged home\current'))) 'Damaged payload is never promoted'

    $installHome = Join-Path $WorkRoot '工具 full home'
    $producerRuntime = Join-Path $installHome 'current\runtime'
    $phpArguments = @('-c', 'php.ini', '-d', 'extension_dir=ext', '..\app\tools\windows-php-bootstrap.php')
    foreach ($flavor in @('small', 'full')) {
        Write-Json (Join-Path $logs "$flavor-packager-result.json") @{
            schema = 'webman-aot-builder-installer-package-result-v1'; revision = $archives[$flavor].revision
            packages = @(@{ platform = 'windows-x86_64'; path = $archives[$flavor].archive
                sha256 = $archives[$flavor].sha256; size = $archives[$flavor].size })
        }
    }
    # Task-only CA and loopback HTTPS exercise the unchanged setup download path.
    # curl reads a private config; no certificate store or persistent PATH is changed.
    $tls = Join-Path $WorkRoot 'tls'
    $trusted = Join-Path $tls 'trusted-config'
    $untrusted = Join-Path $tls 'untrusted-config'
    New-Item -ItemType Directory -Path $tls, $trusted, $untrusted | Out-Null
    [void](Invoke-Cmd 'tls-curl-version' (Join-Path $env:SystemRoot 'System32\curl.exe') @('--version'))
    [void](Invoke-Cmd 'tls-python-version' $python.Source @('--version'))
    [void](Invoke-Cmd 'tls-openssl-version' $openssl @('version'))
    $ca = Join-Path $tls 'ca.pem'
    $caKey = Join-Path $tls 'ca.key'
    $serverKey = Join-Path $tls 'server.key'
    $csr = Join-Path $tls 'server.csr'
    $certificate = Join-Path $tls 'server.pem'
    $extensions = Join-Path $tls 'server.ext'
    [void](Invoke-Cmd 'tls-private-ca' $openssl @('req', '-x509', '-newkey', 'rsa:2048', '-nodes',
        '-keyout', $caKey, '-out', $ca, '-days', '1', '-subj', '/CN=Guided task CA',
        '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'))
    # Schannel checks revocation normally against this task-only, signed CRL.
    $crlPem = Join-Path $tls 'ca.crl.pem'
    $crlDer = Join-Path $tls 'ca.crl'
    $caConfig = Join-Path $tls 'ca.conf'
    [IO.File]::WriteAllText((Join-Path $tls 'index.txt'), '', $utf8)
    [IO.File]::WriteAllText((Join-Path $tls 'crlnumber'), "01`n", $utf8)
    $caDirectory = $tls.Replace('\', '/')
    $configText = "[ca]`ndefault_ca=task_ca`n[task_ca]`ndatabase=$caDirectory/index.txt`n" +
        "private_key=$caDirectory/ca.key`ncertificate=$caDirectory/ca.pem`ncrlnumber=$caDirectory/crlnumber`n" +
        "default_md=sha256`ndefault_crl_days=1`n"
    [IO.File]::WriteAllText($caConfig, $configText, $utf8)
    [void](Invoke-Cmd 'tls-crl-create' $openssl @('ca', '-gencrl', '-batch', '-config', $caConfig, '-out', $crlPem) '' $true $repository 60)
    [void](Invoke-Cmd 'tls-crl-verify' $openssl @('crl', '-in', $crlPem, '-CAfile', $ca, '-verify',
        '-noout', '-issuer', '-lastupdate', '-nextupdate') '' $true $repository 60)
    [void](Invoke-Cmd 'tls-crl-der' $openssl @('crl', '-in', $crlPem, '-outform', 'DER', '-out', $crlDer) '' $true $repository 60)
    $crlScript = Join-Path $tls 'crl-server.py'
    $crlReady = Join-Path $tls 'crl-ready.json'
    $crlRequests = Join-Path $logs 'crl-requests.jsonl'
    $crlSource = @'
import http.server, json, os, pathlib, sys
from urllib.parse import urlsplit
crl, ready, log = sys.argv[1:]
class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlsplit(self.path).path
        status = 200 if path == "/ca.crl" else 404
        with open(log, "a", encoding="utf-8") as out:
            out.write(json.dumps({"path": path, "status": status}) + "\n")
        self.send_response(status)
        if status == 200:
            data = pathlib.Path(crl).read_bytes()
            self.send_header("Content-Type", "application/pkix-crl")
            self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        if status == 200:
            self.wfile.write(data)
    def log_message(self, *args):
        pass
server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
ready_tmp = pathlib.Path(ready + ".tmp")
ready_tmp.write_text(json.dumps({"pid": os.getpid(), "port": server.server_port}), encoding="utf-8")
ready_tmp.replace(ready)
server.serve_forever()
'@
    [IO.File]::WriteAllText($crlScript, $crlSource, $utf8)
    $crlInfo = [Diagnostics.ProcessStartInfo]::new()
    $crlInfo.FileName = $python.Source
    $crlInfo.Arguments = (@('-u', $crlScript, $crlDer, $crlReady, $crlRequests) | ForEach-Object { Quote-Cmd $_ }) -join ' '
    $crlInfo.UseShellExecute = $false
    $crlProcess = [Diagnostics.Process]::new()
    $crlProcess.StartInfo = $crlInfo
    $crlStarted = $crlProcess.Start()
    if (-not $crlStarted) { throw 'Task CRL server did not start.' }
    $crlWait = [Diagnostics.Stopwatch]::StartNew()
    while (-not (Test-Path -LiteralPath $crlReady)) {
        if ($crlProcess.HasExited -or $crlWait.Elapsed.TotalSeconds -ge 30) { throw 'Task CRL server did not become ready.' }
        Start-Sleep -Milliseconds 100
    }
    $crlServer = Get-Content -Raw -LiteralPath $crlReady | ConvertFrom-Json
    Assert-Check ($crlServer.pid -eq $crlProcess.Id) 'HTTP CRL listener readiness belongs to this task process'
    $crlPort = [int]$crlServer.port
    $crlUrl = "http://127.0.0.1:$crlPort/ca.crl"
    Write-Host "[STEP] Task HTTP CRL server PID $($crlProcess.Id); $crlUrl; requests $crlRequests"
    Write-Json (Join-Path $logs 'crl-server.json') @{
        pid = $crlProcess.Id; port = $crlPort; distributionPoint = $crlUrl
        crlSha256 = (Get-FileHash -LiteralPath $crlDer -Algorithm SHA256).Hash.ToLowerInvariant()
        scope = 'DER CRL only; task CA signing key and configuration are never served'
    }
    [IO.File]::WriteAllText($extensions, "subjectAltName=IP:127.0.0.1`nbasicConstraints=critical,CA:FALSE`nkeyUsage=critical,digitalSignature,keyEncipherment`nextendedKeyUsage=serverAuth`ncrlDistributionPoints=URI:$crlUrl`n", $utf8)
    [void](Invoke-Cmd 'tls-server-request' $openssl @('req', '-newkey', 'rsa:2048', '-nodes',
        '-keyout', $serverKey, '-out', $csr, '-subj', '/CN=127.0.0.1'))
    [void](Invoke-Cmd 'tls-server-certificate' $openssl @('x509', '-req', '-in', $csr,
        '-CA', $ca, '-CAkey', $caKey, '-CAcreateserial', '-out', $certificate,
        '-days', '1', '-sha256', '-extfile', $extensions))
    [void](Invoke-Cmd 'tls-leaf-distribution-point' $openssl @('x509', '-in', $certificate,
        '-noout', '-ext', 'crlDistributionPoints') '' $true $repository 60)
    [IO.File]::WriteAllText((Join-Path $trusted '.curlrc'), ('cacert = "' + $ca.Replace('\', '/') + '"' + "`n"), $utf8)
    [IO.File]::WriteAllText((Join-Path $untrusted '.curlrc'), "# Task-private config deliberately has no CA trust.`n", $utf8)
    $serverScript = Join-Path $tls 'server.py'
    $ready = Join-Path $tls 'ready.json'
    $requests = Join-Path $logs 'tls-requests.jsonl'
    $serverSource = @'
import http.server, json, os, pathlib, shutil, ssl, sys
from urllib.parse import urlsplit
cert, key, ready, log, *archives = sys.argv[1:]
files = {"/" + pathlib.Path(p).name: pathlib.Path(p) for p in archives}
class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlsplit(self.path).path
        source = files.get(path)
        status = 200 if source is not None else 404
        with open(log, "a", encoding="utf-8") as out:
            out.write(json.dumps({"path": path, "status": status}) + "\n")
        self.send_response(status)
        if source is not None:
            self.send_header("Content-Length", str(source.stat().st_size))
        self.end_headers()
        if source is not None:
            with source.open("rb") as inp:
                shutil.copyfileobj(inp, self.wfile)
    def log_message(self, *args):
        pass
server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(cert, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
ready_tmp = pathlib.Path(ready + ".tmp")
ready_tmp.write_text(json.dumps({"pid": os.getpid(), "port": server.server_port}), encoding="utf-8")
ready_tmp.replace(ready)
server.serve_forever()
'@
    [IO.File]::WriteAllText($serverScript, $serverSource, $utf8)
    $serverInfo = [Diagnostics.ProcessStartInfo]::new()
    $serverInfo.FileName = $python.Source
    $serverInfo.Arguments = (@('-u', $serverScript, $certificate, $serverKey, $ready, $requests,
        $archives.small.archive, $archives.full.archive) | ForEach-Object { Quote-Cmd $_ }) -join ' '
    $serverInfo.UseShellExecute = $false
    $tlsProcess = [Diagnostics.Process]::new()
    $tlsProcess.StartInfo = $serverInfo
    $tlsStarted = $tlsProcess.Start()
    if (-not $tlsStarted) { throw 'Task HTTPS server did not start.' }
    $wait = [Diagnostics.Stopwatch]::StartNew()
    while (-not (Test-Path -LiteralPath $ready)) {
        if ($tlsProcess.HasExited -or $wait.Elapsed.TotalSeconds -ge 30) { throw 'Task HTTPS server did not become ready.' }
        Start-Sleep -Milliseconds 100
    }
    $server = Get-Content -Raw -LiteralPath $ready | ConvertFrom-Json
    Assert-Check ($server.pid -eq $tlsProcess.Id) 'HTTPS server readiness belongs to this task process'
    $tlsPort = [int]$server.port
    $baseUrl = "https://127.0.0.1:$tlsPort"
    Write-Host "[STEP] Task HTTPS server PID $($tlsProcess.Id); $baseUrl; requests $requests"
    Write-Json (Join-Path $logs 'tls-server.json') @{
        pid = $tlsProcess.Id; port = $tlsPort; baseUrl = $baseUrl
        caSha256 = (Get-FileHash -LiteralPath $ca -Algorithm SHA256).Hash.ToLowerInvariant()
        trustScope = 'process CURL_HOME only; certificate stores untouched'
    }
    $producer = $phpArguments + @((Join-Path $repository 'tools\package-setup.php'),
        ('--small-result=' + (Join-Path $logs 'small-packager-result.json')),
        ('--full-result=' + (Join-Path $logs 'full-packager-result.json')), '--platform=windows-x86_64')
    $previousCallerDirectory = $env:WEBMAN_AOT_CALLER_CWD
    try {
        $env:WEBMAN_AOT_CALLER_CWD = $repository
        $setupResult = Invoke-Cmd 'setup-producer' '.\php.exe' ($producer + @(
            "--base-url=$baseUrl", ('--output=' + (Join-Path $WorkRoot 'setup')))) '' $true $producerRuntime
        $setup = ($setupResult.Stdout | ConvertFrom-Json).launcherPath
        $missingResult = Invoke-Cmd 'setup-404-producer' '.\php.exe' ($producer + @(
            "--base-url=$baseUrl/missing", ('--output=' + (Join-Path $WorkRoot 'setup-404')))) '' $true $producerRuntime
        $missingSetup = ($missingResult.Stdout | ConvertFrom-Json).launcherPath
    } finally { $env:WEBMAN_AOT_CALLER_CWD = $previousCallerDirectory }
    $launcherBytes = [IO.File]::ReadAllBytes($setup)
    $launcherText = $utf8.GetString($launcherBytes)
    Copy-Item -LiteralPath $setup -Destination (Join-Path $logs 'generated-setup.cmd')
    Copy-Item -LiteralPath $missingSetup -Destination (Join-Path $logs 'generated-setup-404.cmd')
    $bareLf = [regex]::Matches($launcherText, '(?<!\r)\n').Count
    $crlf = [regex]::Matches($launcherText, '\r\n').Count
    Write-Json (Join-Path $logs 'setup-launcher-bytes.json') @{
        path = $setup; size = $launcherBytes.Length; bareLf = $bareLf; crlf = $crlf
        sha256 = (Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant()
        hasUtf8Bom = ($launcherBytes.Length -ge 3 -and $launcherBytes[0] -eq 239 -and
            $launcherBytes[1] -eq 187 -and $launcherBytes[2] -eq 191)
    }
    Assert-Check ($bareLf -eq 0 -and $crlf -gt 0) 'Actual generated Windows launcher uses CRLF before CMD execution'
    Write-Json (Join-Path $logs 'tls-setup.json') @{
        setupSha256 = (Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant()
        smallArchiveSha256 = $archives.small.sha256; fullArchiveSha256 = $archives.full.sha256
    }
    Remove-OwnedDirectory $installHome
    Remove-OwnedDirectory (Join-Path $WorkRoot '命令 full bin')
    $env:CURL_HOME = $trusted
    $env:NO_PROXY = '127.0.0.1'
    [void](Invoke-Cmd 'setup-eof' $setup)
    [void](Invoke-Cmd 'setup-invalid-cancel' $setup @() "bad`n0`n")
    foreach ($flavor in @('small', 'full')) {
        $env:LOCALAPPDATA = Join-Path $WorkRoot "https $flavor cache"
        $arguments = @('-Flavor', $flavor, '-Install',
            '-InstallRoot', (Join-Path $WorkRoot "setup $flavor home"),
            '-BinDir', (Join-Path $WorkRoot "setup $flavor bin"), '-NoPath')
        if ($flavor -eq 'full') {
            $project = Copy-Project "setup $flavor 项目"
            $arguments += @('-Project', $project)
        }
        $run = Invoke-Cmd "setup-$flavor-https-install" $setup $arguments
        Assert-Check (-not $run.Text.Contains('NativeCommandError')) "$flavor successful setup shows download progress without a PowerShell error wrapper"
        Assert-Check ($run.Text.Contains('下载：') -and $run.Text.Contains('外层 SHA-256 校验成功') -and
            (Test-Path -LiteralPath (Join-Path $WorkRoot "setup $flavor home\current\runtime\php.exe"))) "$flavor standalone setup downloads HTTPS, verifies bound SHA and installs"
        $served = @(Get-Content -LiteralPath $requests | ForEach-Object { $_ | ConvertFrom-Json } |
            Where-Object { $_.path -eq ('/' + [IO.Path]::GetFileName($archives[$flavor].archive)) -and $_.status -eq 200 })
        Assert-Check ($served.Count -eq 1) "$flavor setup fetched its actual archive exactly once"
        if ($flavor -eq 'full') { Assert-Check ($run.Text.Contains('项目构建与校验成功')) 'HTTPS standalone setup builds and verifies the real fixture' }
        Remove-OwnedDirectory (Join-Path $WorkRoot "setup $flavor home")
        Remove-OwnedDirectory (Join-Path $WorkRoot "setup $flavor bin")
    }
    $env:LOCALAPPDATA = Join-Path $WorkRoot 'https 404 cache'
    $httpFailure = Invoke-Cmd 'setup-http-404' $missingSetup @('-Flavor', 'small', '-Install',
        '-InstallRoot', (Join-Path $WorkRoot 'https 404 home'), '-BinDir', (Join-Path $WorkRoot 'https 404 bin'), '-NoPath') '' $false
    $missingRequests = @(Get-Content -LiteralPath $requests | ForEach-Object { $_ | ConvertFrom-Json } |
        Where-Object { $_.status -eq 404 -and $_.path.StartsWith('/missing/') })
    Assert-Check ($httpFailure.Code -eq 22 -and $missingRequests.Count -eq 1 -and
        -not $httpFailure.Text.Contains('包检查成功') -and
        -not (Test-Path -LiteralPath (Join-Path $WorkRoot 'https 404 home')) -and
        -not (Test-Path -LiteralPath (Join-Path $WorkRoot 'https 404 bin'))) 'Actual HTTP 404 returns curl 22 and never executes package code'
    $requestCount = @(Get-Content -LiteralPath $requests).Count
    $env:CURL_HOME = $untrusted
    $env:LOCALAPPDATA = Join-Path $WorkRoot 'https untrusted cache'
    $tlsFailure = Invoke-Cmd 'setup-tls-untrusted' $setup @('-Flavor', 'small', '-Install',
        '-InstallRoot', (Join-Path $WorkRoot 'https untrusted home'), '-BinDir', (Join-Path $WorkRoot 'https untrusted bin'), '-NoPath') '' $false
    Assert-Check ($tlsFailure.Code -eq 60 -and $tlsFailure.Text -match '(?i)certificate|cert|证书' -and
        @(Get-Content -LiteralPath $requests).Count -eq $requestCount -and
        -not $tlsFailure.Text.Contains('包检查成功') -and
        -not (Test-Path -LiteralPath (Join-Path $WorkRoot 'https untrusted home')) -and
        -not (Test-Path -LiteralPath (Join-Path $WorkRoot 'https untrusted bin'))) 'Untrusted task CA returns curl 60 before HTTP GET or package execution'
    $crlServed = @(Get-Content -LiteralPath $crlRequests | ForEach-Object { $_ | ConvertFrom-Json } |
        Where-Object { $_.path -eq '/ca.crl' -and $_.status -eq 200 })
    Assert-Check ($crlServed.Count -ge 1) 'Native Schannel fetched the signed task CRL over HTTP without weakening revocation checks'
    $httpsAccepted = $true
    $badArchive = Join-Path $WorkRoot 'corrupt.zip'
    [IO.File]::WriteAllText($badArchive, 'intentionally invalid archive', $utf8)
    [void](Invoke-Cmd 'setup-outer-digest-failure' $setup @(
        '-Flavor', 'small', '-Archive', $badArchive, '-Install',
        '-InstallRoot', (Join-Path $WorkRoot 'setup damaged home'),
        '-BinDir', (Join-Path $WorkRoot 'setup damaged bin'), '-NoPath'
    ) '' $false)
    Assert-Check (-not (Test-Path -LiteralPath (Join-Path $WorkRoot 'setup damaged home'))) 'Outer archive failure never installs package code'
} catch {
    $failure = $_.Exception.Message
    Write-Host "[FAIL] $failure"
    # Preserve the real source-small failure. Inventory reads its installed files only.
    if ($pathDigests.Count) {
        $installedRuntime = Join-Path $WorkRoot '工具 small home\current\runtime'
        $sourceLog = Join-Path $logs 'source-small.log'
        if ((Test-Path -LiteralPath ($installedRuntime + '\php.exe')) -and
            (Test-Path -LiteralPath $sourceLog)) {
            try {
                $sourceText = [IO.File]::ReadAllText($sourceLog, $utf8)
                $reported = [regex]::Matches($sourceText, '安装目标：([^\r\n]+)')
                if ($reported.Count -eq 0) { throw 'Source log did not preserve its actual home argument.' }
                $reportedHome = $reported[$reported.Count - 1].Groups[1].Value.Trim()
                $nativeHome = [IO.Path]::GetFullPath($reportedHome)
                if ($nativeHome -ne [IO.Path]::GetFullPath((Join-Path $WorkRoot '工具 small home'))) {
                    throw 'Inventory refused a home outside the expected task installation.'
                }
                $failedProject = Join-Path $WorkRoot '项目 small 中文'
                Record-RuntimeInventory 'runtime-chinese-mixed' ($reportedHome + '\current\runtime') $reportedHome $failedProject
                Record-RuntimeInventory 'runtime-chinese-native' ($nativeHome + '\current\runtime') $nativeHome $failedProject
            } catch {
                Write-Host ('[inventory failure] Original acceptance failure retained; runtime probe: ' + $_.Exception.Message)
            }
        }
    }
} finally {
    if ($null -ne $tlsProcess) {
        if ($tlsStarted) {
            if (-not $tlsProcess.HasExited) { $tlsProcess.Kill(); $tlsProcess.WaitForExit() }
            $serverPid = $tlsProcess.Id
            $portClosed = $true
            if ($null -ne $tlsPort) {
                $client = [Net.Sockets.TcpClient]::new()
                try { $client.Connect('127.0.0.1', $tlsPort); $portClosed = $false } catch { } finally { $client.Dispose() }
            }
            Write-Json (Join-Path $logs 'tls-cleanup.json') @{ pid = $serverPid; port = $tlsPort; stopped = $true; portClosed = $portClosed }
            Write-Host "[cleanup] Task HTTPS server PID $serverPid stopped; port closed: $portClosed"
            if (-not $portClosed) { $failure = 'Task HTTPS server port remained open after cleanup.' }
        }
        $tlsProcess.Dispose()
    }
    if ($null -ne $crlProcess) {
        if ($crlStarted) {
            if (-not $crlProcess.HasExited) { $crlProcess.Kill(); $crlProcess.WaitForExit() }
            $crlPid = $crlProcess.Id
            $crlPortClosed = $true
            if ($null -ne $crlPort) {
                $crlClient = [Net.Sockets.TcpClient]::new()
                try { $crlClient.Connect('127.0.0.1', $crlPort); $crlPortClosed = $false } catch { } finally { $crlClient.Dispose() }
            }
            Write-Json (Join-Path $logs 'crl-cleanup.json') @{ pid = $crlPid; port = $crlPort; stopped = $true; portClosed = $crlPortClosed }
            Write-Host "[cleanup] Task HTTP CRL server PID $crlPid stopped; port closed: $crlPortClosed"
            if (-not $crlPortClosed) { $failure = 'Task HTTP CRL server port remained open after cleanup.' }
        }
        $crlProcess.Dispose()
    }
    if ($pathDigests.Count) {
        foreach ($target in @('User', 'Machine')) {
            if ((Get-PathDigest $target) -ne $pathDigests[$target]) {
                $failure = "Persistent $target PATH changed during native acceptance."
                Write-Host "[FAIL] $failure"
            }
        }
    }
    foreach ($name in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
    }
    if ($pathDigests.Count) {
        Write-Json (Join-Path $logs 'outcome.json') @{
            success = $null -eq $failure; failure = $failure; seconds = $started.Elapsed.TotalSeconds
            stages = @($records.ToArray()); persistentPathUnchanged = $failure -notlike '*PATH changed*'
            httpsAccepted = $httpsAccepted
            pending = @($(if (-not $httpsAccepted) { 'standalone setup real HTTPS download and network-failure acceptance' }), 'Linux deployment and business runtime')
            resources = @($WorkRoot, (Join-Path $repository 'dist'))
            cleanup = 'Retained task-owned logs and resources for inspection; runner lifetime owns CI cleanup. No pre-existing resource removed.'
        }
    }
}
if ($null -ne $failure) { exit 1 }
Write-Host ("[PASS] Native Windows entries and real compilation completed in {0:N1}s. Logs retained: {1}" -f $started.Elapsed.TotalSeconds, $logs)
exit 0
