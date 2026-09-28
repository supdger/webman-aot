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
    [string]$WorkingDirectory = $repository
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
    $info.StandardInputEncoding = $utf8
    $info.StandardOutputEncoding = $utf8
    $info.StandardErrorEncoding = $utf8
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $text = [Text.StringBuilder]::new()
    $stdout = [Text.StringBuilder]::new()
    $didStart = $false
    Write-Host "[STEP] $Name; deadline ${StepTimeoutSeconds}s; log $log"
    try {
        $didStart = $process.Start()
        # Send every menu choice before closing stdin. EOF is intentional.
        if ($InputText) { $process.StandardInput.Write($InputText) }
        $process.StandardInput.Close()
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
            if ($timer.Elapsed.TotalSeconds -ge $StepTimeoutSeconds) {
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
    $projectLock = Get-Content -Raw -LiteralPath (Join-Path $ProjectFixture 'composer.lock') | ConvertFrom-Json
    foreach ($locked in $compatibility.packages.PSObject.Properties) {
        $matches = @($projectLock.packages | Where-Object { $_.name -eq $locked.Name })
        if ($matches.Count -ne 1 -or $matches[0].version -ne $locked.Value.version -or
            $matches[0].source.reference -ne $locked.Value.reference) { throw "Fixture must match repository lock: $($locked.Name)" }
    }
    New-Item -ItemType Directory -Path $WorkRoot | Out-Null
    $logs = Join-Path $WorkRoot 'logs'
    $temp = Join-Path $WorkRoot 'temp'
    New-Item -ItemType Directory -Path $logs, $temp | Out-Null
    foreach ($target in @('User', 'Machine')) { $pathDigests[$target] = Get-PathDigest $target }
    foreach ($name in @('TEMP', 'TMP', 'LOCALAPPDATA', 'WEBMAN_AOT_BUILDER_HOME',
        'WEBMAN_AOT_NO_PAUSE', 'Path', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR')) {
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
    # Process PATH uses Windows tools and optional existing Git, never system PHP.
    $env:Path = (@("$env:SystemRoot\System32", $env:SystemRoot,
        "$env:SystemRoot\System32\WindowsPowerShell\v1.0") +
        $(if ($git) { @(Split-Path -Parent $git.Source) } else { @() })) -join ';'
    Assert-Check ($null -eq (Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue)) 'No system PHP available to entries'
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
        $home = Join-Path $WorkRoot "工具 $flavor home"
        $bin = Join-Path $WorkRoot "命令 $flavor bin"
        $before = @(Get-ChildItem -LiteralPath $temp -Recurse -Filter source-result.json -File | ForEach-Object FullName)
        $arguments = @('-InstallRoot', $home, '-BinDir', $bin, '-NoPath')
        if ($flavor -eq 'small') {
            $project = Copy-Project "项目 $flavor 中文"
            $arguments += @('-Flavor', 'small', '-Install', '-Project', $project)
            $inputText = ''
        } else { $inputText = "2`n1`n0`n" }
        $run = Invoke-Cmd "source-$flavor" (Join-Path $repository 'build.cmd') $arguments $inputText
        Assert-Check (Test-Path -LiteralPath (Join-Path $home 'current\runtime\php.exe')) "$flavor source entry installed to its private home"
        if ($flavor -eq 'small') {
            Assert-Check ($run.Text.Contains('项目构建与校验成功') -and
                (Test-Path -LiteralPath (Join-Path $project 'dist-aot') -PathType Container)) 'Source entry built and verified the real fixture'
        }
        $archives[$flavor] = Read-SourceResult $flavor $before
        $package = Join-Path $WorkRoot "安装包 $flavor 中文"
        New-Item -ItemType Directory -Path $package | Out-Null
        & (Join-Path $env:SystemRoot 'System32\tar.exe') -xf $archives[$flavor].archive -C $package
        if ($LASTEXITCODE -ne 0) { throw "$flavor package extraction failed: $LASTEXITCODE" }
        $archives[$flavor] | Add-Member -NotePropertyName package -NotePropertyValue $package
        if ($flavor -eq 'small') { Remove-OwnedDirectory $home; Remove-OwnedDirectory $bin }
    }
    $eof = Invoke-Cmd 'source-eof' (Join-Path $repository 'build.cmd')
    Assert-Check ($eof.Text.Contains('已取消构包')) 'Source EOF cancels after private bootstrap'
    $cancel = Invoke-Cmd 'source-invalid-cancel' (Join-Path $repository 'build.cmd') @() "bad`n0`n"
    Assert-Check ($cancel.Text.Contains('选择无效') -and $cancel.Text.Contains('已取消构包')) 'Source invalid selection retries and cancels'

    foreach ($flavor in @('small', 'full')) {
        $home = Join-Path $WorkRoot "package $flavor home"
        $bin = Join-Path $WorkRoot "package $flavor bin"
        $entry = Join-Path $archives[$flavor].package 'install.cmd'
        $common = @('-InstallRoot', $home, '-BinDir', $bin, '-NoPath')
        [void](Invoke-Cmd "package-$flavor-eof" $entry $common)
        Assert-Check (-not (Test-Path -LiteralPath $home)) "$flavor package EOF does not install"
        [void](Invoke-Cmd "package-$flavor-cancel" $entry $common "0`n")
        Assert-Check (-not (Test-Path -LiteralPath $home)) "$flavor package cancellation does not install"
        if ($flavor -eq 'full') {
            $project = Copy-Project "package $flavor 项目"
            $run = Invoke-Cmd "package-$flavor-project-menu" $entry $common "bad`n1`n1`n$project`n"
            Assert-Check ($run.Text.Contains('选择无效') -and $run.Text.Contains('项目构建与校验成功')) 'Full package menu installs, builds and verifies'
        } else {
            [void](Invoke-Cmd 'package-small-install' $entry ($common + @('-Install')))
            Assert-Check (Test-Path -LiteralPath (Join-Path $home 'current\runtime\php.exe')) 'Small package explicitly installs to private home'
        }
        Remove-OwnedDirectory $home
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

    $home = Join-Path $WorkRoot '工具 full home'
    $php = Join-Path $home 'current\runtime\php.exe'
    $phpArguments = @('-c', (Join-Path $home 'current\runtime\php.ini'), '-d',
        ('extension_dir=' + (Join-Path $home 'current\runtime\ext')))
    foreach ($flavor in @('small', 'full')) {
        Write-Json (Join-Path $logs "$flavor-packager-result.json") @{
            schema = 'webman-aot-builder-installer-package-result-v1'; revision = $archives[$flavor].revision
            packages = @(@{ platform = 'windows-x86_64'; path = $archives[$flavor].archive
                sha256 = $archives[$flavor].sha256; size = $archives[$flavor].size })
        }
    }
    $setupResult = Invoke-Cmd 'setup-producer' $php ($phpArguments + @(
        (Join-Path $repository 'tools\package-setup.php'),
        ('--small-result=' + (Join-Path $logs 'small-packager-result.json')),
        ('--full-result=' + (Join-Path $logs 'full-packager-result.json')),
        '--platform=windows-x86_64', '--base-url=https://example.invalid/acceptance',
        ('--output=' + (Join-Path $WorkRoot 'setup'))
    ))
    $setup = ($setupResult.Stdout | ConvertFrom-Json).launcherPath
    Remove-OwnedDirectory $home
    Remove-OwnedDirectory (Join-Path $WorkRoot '命令 full bin')
    # URLs above intentionally cannot be production assets. Native setup acceptance
    # uses its verified -Archive interface; HTTP download is a separate pending gate.
    [void](Invoke-Cmd 'setup-eof' $setup)
    [void](Invoke-Cmd 'setup-invalid-cancel' $setup @() "bad`n0`n")
    foreach ($flavor in @('small', 'full')) {
        $arguments = @(
            '-Flavor', $flavor, '-Archive', $archives[$flavor].archive, '-Install',
            '-InstallRoot', (Join-Path $WorkRoot "setup $flavor home"),
            '-BinDir', (Join-Path $WorkRoot "setup $flavor bin"), '-NoPath'
        )
        if ($flavor -eq 'full') {
            $project = Copy-Project "setup $flavor 项目"
            $arguments += @('-Project', $project)
        }
        $run = Invoke-Cmd "setup-$flavor-archive-install" $setup $arguments
        Assert-Check (Test-Path -LiteralPath (Join-Path $WorkRoot "setup $flavor home\current\runtime\php.exe")) "$flavor standalone setup verifies the bound archive and installs"
        if ($flavor -eq 'full') { Assert-Check ($run.Text.Contains('项目构建与校验成功')) 'Standalone setup builds and verifies the real fixture' }
        Remove-OwnedDirectory (Join-Path $WorkRoot "setup $flavor home")
        Remove-OwnedDirectory (Join-Path $WorkRoot "setup $flavor bin")
    }
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
} finally {
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
            pending = @('standalone setup real HTTPS download and network-failure acceptance', 'Linux deployment and business runtime')
            resources = @($WorkRoot, (Join-Path $repository 'dist'))
            cleanup = 'Retained task-owned logs and resources for inspection; runner lifetime owns CI cleanup. No pre-existing resource removed.'
        }
    }
}
if ($null -ne $failure) { exit 1 }
Write-Host ("[PASS] Native Windows entries and real compilation completed in {0:N1}s. Logs retained: {1}" -f $started.Elapsed.TotalSeconds, $logs)
exit 0
