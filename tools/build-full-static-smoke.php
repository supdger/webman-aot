#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/ElfStaticVerifier.php';
require dirname(__DIR__) . '/src/Toolchain/SdkArchiveGuard.php';

use WebmanAot\Toolchain\ElfStaticVerifier;
use WebmanAot\Toolchain\SdkArchiveGuard;

/**
 * @return array<string, string>
 */
function options(array $arguments): array
{
    $result = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException("invalid option: {$argument}");
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $result[$name] = $value;
    }
    return $result;
}

function required(array $options, string $name): string
{
    $value = $options[$name] ?? '';
    if ($value === '') {
        throw new InvalidArgumentException("missing --{$name}=...");
    }
    return rtrim($value, '/\\');
}

function flagPath(string $path): string
{
    return '"' . str_replace('"', '\\"', $path) . '"';
}

/**
 * @param list<string> $command
 */
function run(array $command, string $workingDirectory): void
{
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory
    );
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start TypePHP compiler');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $reportedExitCode = null;
    while (true) {
        $status = proc_get_status($process);
        foreach ([1 => STDOUT, 2 => STDERR] as $index => $destination) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                fwrite($destination, $chunk);
            }
        }
        if (!$status['running']) {
            $reportedExitCode = $status['exitcode'];
            break;
        }
        usleep(20000);
    }
    foreach ([1 => STDOUT, 2 => STDERR] as $index => $destination) {
        stream_set_blocking($pipes[$index], true);
        $chunk = stream_get_contents($pipes[$index]);
        if (is_string($chunk) && $chunk !== '') {
            fwrite($destination, $chunk);
        }
        fclose($pipes[$index]);
    }
    $closedExitCode = proc_close($process);
    $exitCode = is_int($reportedExitCode) && $reportedExitCode >= 0
        ? $reportedExitCode
        : $closedExitCode;
    if ($exitCode !== 0) {
        throw new RuntimeException("TypePHP build failed with exit code {$exitCode}");
    }
}

try {
    $options = options($argv);
    $typephp = required($options, 'typephp');
    $phpx = isset($options['phpx']) ? required($options, 'phpx') : $typephp . '/vendor/swoole/phpx';
    $hostPhp = required($options, 'php');
    $compiler = required($options, 'compiler');
    $sysroot = required($options, 'sysroot');
    $sdk = required($options, 'sdk');
    $output = required($options, 'output');

    foreach ([$typephp, $phpx, $sysroot, $sdk] as $directory) {
        if (!is_dir($directory)) {
            throw new RuntimeException("directory does not exist: {$directory}");
        }
    }
    foreach ([$hostPhp, $compiler] as $executable) {
        if (!is_file($executable) || !is_executable($executable)) {
            throw new RuntimeException("executable does not exist: {$executable}");
        }
    }
    if (file_exists($output) && !is_dir($output)) {
        throw new RuntimeException("output exists and is not a directory: {$output}");
    }
    if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
        throw new RuntimeException("unable to create output directory: {$output}");
    }

    $patchedTypePhpFiles = [
        'src/Backend/CompilerBackend.php'
            => 'cb62bcba129a6e5acdfa91c910889fe6e8c82c4eb92d5ccd23314d2d88e69bed',
        'src/Build/NativeBuildConfigurationTrait.php'
            => '691933676e46eeca1ae0fc8778423ec50959985865062fe6d43f8504fe7a05b9',
        'src/Build/NativeCommandOptionsTrait.php'
            => '7aa0c5c7205d270098df1dda36879d19f854eb015e5a887f1f269d166d7e7ed4',
        'src/Build/SourcePipelineTrait.php'
            => '95d9f4511f7337d9049bc919337118ccd6379cab2e92aecfff95a3a31d108807',
        'src/CompilerBase.php'
            => '619138a59e32dd62dc694c16895ad9abac11063b703d91d408e1f44ed3eee1bb',
        'src/Generator/Utils.php'
            => 'ae93dfb8e0b316404ebe4b7f1cad2be0e9ad4929ac6abcda1478a5cbaa7ca382',
        'src/Optimizer/FuncCallOptimizer.php'
            => '007205885631988f0589cb5d10dea7b1911820ccb316332a2ac95a3f620f21be',
        'src/Parser/StdContainerTrait.php'
            => 'e539a13eab2881a22f0e1bf1c2c2c5651962b86c4eeedf657906b7b438c77ddc',
        'src/Preprocessor.php'
            => '0bf8d2c1f3a14c985e919c90f76d3bf3d2386a3750a08e31233412c94d29db9e',
        'src/Translator.php'
            => '728da536d766a890ee1b48508444961c041357893d5f7d8ce46cddea41d202fd',
    ];
    foreach ($patchedTypePhpFiles as $relativePath => $expectedDigest) {
        $actualDigest = hash_file('sha256', $typephp . '/' . $relativePath);
        if (!is_string($actualDigest) || !hash_equals($expectedDigest, $actualDigest)) {
            throw new RuntimeException("TypePHP cross-target patch is missing or drifted: {$relativePath}");
        }
    }
    $runtimeDigest = hash_file('sha256', $phpx . '/src/misc/typephp_runtime.cc');
    if (!is_string($runtimeDigest)
        || !hash_equals('56390337e2e6d4ef5fac0d42aa775a088b46ac44b260e1fb1b6308ace37828f0', $runtimeDigest)
    ) {
        throw new RuntimeException('PHPX static-extension registry patch is missing or drifted');
    }

    $installedSdk = realpath($phpx . '/full-static/sdk');
    if ($installedSdk === false || realpath($sdk) !== $installedSdk) {
        throw new RuntimeException('the requested SDK must be installed at TypePHP vendor/swoole/phpx/full-static/sdk');
    }

    $llvmNm = dirname($compiler) . '/llvm-nm' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    (new SdkArchiveGuard())->inspect($sdk, $llvmNm);

    $fixture = $options['fixture']
        ?? dirname(__DIR__) . '/tests/fixtures/full-static-smoke/main.php';
    if (!is_file($fixture)) {
        throw new RuntimeException("smoke fixture does not exist: {$fixture}");
    }
    if (!copy($fixture, $output . '/main.php')) {
        throw new RuntimeException('unable to copy full-static smoke fixture');
    }

    $extraSources = '';
    $extraLdFlags = '';
    $staticExtensionArchive = $options['static-extension-archive'] ?? '';
    $staticExtensionModules = $options['static-extension-modules'] ?? '';
    if (($staticExtensionArchive === '') !== ($staticExtensionModules === '')) {
        throw new InvalidArgumentException(
            '--static-extension-archive and --static-extension-modules must be used together'
        );
    }
    if ($staticExtensionArchive !== '') {
        $archives = array_values(array_filter(explode(',', $staticExtensionArchive)));
        foreach ($archives as $archive) {
            if (!is_file($archive)) {
                throw new RuntimeException("static extension archive does not exist: {$archive}");
            }
        }
        $modules = array_values(array_filter(explode(',', $staticExtensionModules)));
        if ($modules === []) {
            throw new InvalidArgumentException('at least one static extension module is required');
        }
        $declarations = [];
        $entries = [];
        $forceSymbols = [];
        foreach ($modules as $module) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $module) !== 1) {
                throw new InvalidArgumentException("invalid module entry symbol: {$module}");
            }
            $declarations[] = "extern \"C\" zend_module_entry {$module};";
            $entries[] = "        &{$module},";
            $forceSymbols[] = '-Wl,-u,' . $module;
        }
        $glue = "#include <php.h>\n\n"
            . implode("\n", $declarations) . "\n\n"
            . "extern \"C\" zend_module_entry **typephp_runtime_extra_modules(size_t *count)\n"
            . "{\n"
            . "    static zend_module_entry *modules[] = {\n"
            . implode("\n", $entries) . "\n"
            . "    };\n"
            . "    *count = sizeof(modules) / sizeof(modules[0]);\n"
            . "    return modules;\n"
            . "}\n";
        if (file_put_contents($output . '/static_extensions.cc', $glue) === false) {
            throw new RuntimeException('unable to write static extension registry');
        }
        $extraSources = "  - static_extensions.cc\n";
        $extraLdFlags = implode(' ', [...$forceSymbols, ...array_map(flagPath(...), $archives)]);
    }

    $gccVersion = '12.2.1';
    $gccDirectory = $sysroot . '/usr/lib/gcc/x86_64-alpine-linux-musl/' . $gccVersion;
    $cxxInclude = $sysroot . '/usr/include/c++/' . $gccVersion;
    $targetInclude = $cxxInclude . '/x86_64-alpine-linux-musl';
    foreach ([$gccDirectory, $cxxInclude, $targetInclude] as $directory) {
        if (!is_dir($directory)) {
            throw new RuntimeException("sysroot is incomplete: {$directory}");
        }
    }
    $sysrootFlag = flagPath($sysroot);
    $gccDirectoryFlag = flagPath($gccDirectory);
    $cxxIncludeFlag = flagPath($cxxInclude);
    $targetIncludeFlag = flagPath($targetInclude);
    $sysrootLibraryFlag = flagPath($sysroot . '/usr/lib');

    $project = <<<YAML
name: full-static-cross-smoke
mode: bin
php-version: "8.4"
build-dir: build
output: full_static_cross_smoke
cxx-std: c++17
target-platform: x86_64-unknown-linux-musl
reproducible-source-prefix: /usr/src/webman-aot
cxx-flags: >-
  --sysroot={$sysrootFlag}
  -isystem {$cxxIncludeFlag}
  -isystem {$targetIncludeFlag}
c-flags: --sysroot={$sysrootFlag}
asm-flags: --sysroot={$sysrootFlag}
ld-flags: >-
  --sysroot={$sysrootFlag}
  -fuse-ld=lld
  -Wl,--allow-multiple-definition
  -Wl,--strip-debug
  -B{$gccDirectoryFlag}
  -L{$sysrootLibraryFlag}
  -L{$gccDirectoryFlag}
  {$extraLdFlags}
sources:
  - main.php
{$extraSources}
YAML;
    if (file_put_contents($output . '/project.yml', $project . PHP_EOL) === false) {
        throw new RuntimeException('unable to write smoke project.yml');
    }

    run(
        [
            $hostPhp,
            $typephp . '/bin/tpc.php',
            $output . '/project.yml',
            '--full-static',
            '--compiler=' . $compiler,
            '--job=4',
            '--no-progress',
            '--force',
        ],
        $output
    );

    $artifact = $output . '/full_static_cross_smoke';
    $llvmObjcopy = dirname($compiler) . '/llvm-objcopy'
        . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (!is_file($llvmObjcopy) || !is_executable($llvmObjcopy)) {
        throw new RuntimeException("llvm-objcopy does not exist: {$llvmObjcopy}");
    }
    run([$llvmObjcopy, '--remove-section=.comment', $artifact], $output);
    (new ElfStaticVerifier())->assertFullyStaticX86_64($artifact);
    fwrite(
        STDOUT,
        json_encode(
            [
                'artifact' => $artifact,
                'sha256' => hash_file('sha256', $artifact),
                'size' => filesize($artifact),
                'target' => 'x86_64-unknown-linux-musl',
                'interpreter' => false,
                'dynamicSegment' => false,
                'neededLibraries' => [],
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
