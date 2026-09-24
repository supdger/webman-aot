#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the locked Event, Igbinary and Msgpack sources for Linux amd64 musl.
 *
 * This recipe intentionally consumes source archives whose digests are pinned
 * in toolchain.lock.json. It never uses host PHP headers or shared libraries.
 */

/**
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException("invalid option: {$argument}");
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
    return $options;
}

function requireOption(array $options, string $name): string
{
    $value = $options[$name] ?? '';
    if ($value === '') {
        throw new InvalidArgumentException("missing --{$name}=...");
    }
    return rtrim($value, '/\\');
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, ?string $workingDirectory = null): void
{
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $workingDirectory
    );
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start command: ' . implode(' ', $command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            sprintf('command failed with exit code %d: %s', $exitCode, implode(' ', $command))
        );
    }
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("unable to create directory: {$path}");
    }
}

/**
 * @return array<string, array{version: string, sourceUrl: string, sha256: string}>
 */
function lockedComponents(string $root): array
{
    $contents = file_get_contents($root . '/toolchain.lock.json');
    if ($contents === false) {
        throw new RuntimeException('unable to read toolchain.lock.json');
    }
    $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    $required = ['pecl-event', 'libevent-source', 'pecl-igbinary', 'pecl-msgpack'];
    $result = [];
    foreach ($lock['components'] ?? [] as $component) {
        if (!is_array($component) || !in_array($component['id'] ?? null, $required, true)) {
            continue;
        }
        $result[$component['id']] = [
            'version' => (string) $component['version'],
            'sourceUrl' => (string) $component['sourceUrl'],
            'sha256' => (string) $component['sha256'],
        ];
    }
    foreach ($required as $id) {
        if (!isset($result[$id])) {
            throw new RuntimeException("toolchain lock is missing {$id}");
        }
    }
    return $result;
}

function assertDigest(string $path, string $expected): void
{
    $actual = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException("source archive digest mismatch: {$path}");
    }
}

/**
 * @param list<string> $includes
 * @param list<string> $defines
 */
function compileC(
    string $compiler,
    string $sysroot,
    array $includes,
    array $defines,
    string $source,
    string $object
): void {
    $command = [
        $compiler,
        '--target=x86_64-unknown-linux-musl',
        '--sysroot=' . $sysroot,
        '-std=gnu11',
        '-O2',
        '-fPIC',
        '-D_GNU_SOURCE=1',
        '-DZTS=1',
        '-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1',
    ];
    foreach ($defines as $define) {
        $command[] = '-D' . $define;
    }
    foreach ($includes as $include) {
        $command[] = '-I' . $include;
    }
    $command[] = '-c';
    $command[] = $source;
    $command[] = '-o';
    $command[] = $object;
    runCommand($command);
}

try {
    $root = dirname(__DIR__);
    $options = parseOptions($argv);
    $compiler = requireOption($options, 'compiler');
    $cmake = requireOption($options, 'cmake');
    $tar = requireOption($options, 'tar');
    $sysroot = requireOption($options, 'sysroot');
    $sdk = requireOption($options, 'sdk');
    $sources = requireOption($options, 'sources');
    $output = requireOption($options, 'output');

    foreach ([$compiler, $cmake, $tar] as $executable) {
        if (!is_file($executable) || !is_executable($executable)) {
            throw new RuntimeException("executable does not exist: {$executable}");
        }
    }
    foreach ([$sysroot, $sdk, $sources] as $directory) {
        if (!is_dir($directory)) {
            throw new RuntimeException("directory does not exist: {$directory}");
        }
    }
    if (file_exists($output) && !is_dir($output)) {
        throw new RuntimeException("output exists and is not a directory: {$output}");
    }
    ensureDirectory($output);

    $locks = lockedComponents($root);
    $archives = [
        'pecl-event' => $sources . '/event-' . $locks['pecl-event']['version'] . '.tgz',
        'libevent-source' => $sources . '/libevent-' . $locks['libevent-source']['version'] . '.tar.gz',
        'pecl-igbinary' => $sources . '/igbinary-' . $locks['pecl-igbinary']['version'] . '.tgz',
        'pecl-msgpack' => $sources . '/msgpack-' . $locks['pecl-msgpack']['version'] . '.tgz',
    ];
    foreach ($archives as $id => $archive) {
        assertDigest($archive, $locks[$id]['sha256']);
    }

    $sourceRoot = $output . '/src';
    $buildRoot = $output . '/build';
    $objectRoot = $output . '/objects';
    ensureDirectory($sourceRoot);
    ensureDirectory($buildRoot);
    ensureDirectory($objectRoot);
    foreach ($archives as $archive) {
        runCommand([$tar, '-xzf', $archive, '-C', $sourceRoot]);
    }

    $eventVersion = $locks['pecl-event']['version'];
    $libeventVersion = $locks['libevent-source']['version'];
    $igbinaryVersion = $locks['pecl-igbinary']['version'];
    $msgpackVersion = $locks['pecl-msgpack']['version'];
    $eventSource = $sourceRoot . '/event-' . $eventVersion;
    $libeventSource = $sourceRoot . '/libevent-' . $libeventVersion;
    $igbinarySource = $sourceRoot . '/igbinary-' . $igbinaryVersion;
    $msgpackSource = $sourceRoot . '/msgpack-' . $msgpackVersion;
    foreach ([$eventSource, $libeventSource, $igbinarySource, $msgpackSource] as $directory) {
        if (!is_dir($directory)) {
            throw new RuntimeException("archive did not produce expected directory: {$directory}");
        }
    }

    $toolchainFile = $buildRoot . '/musl-x86_64.cmake';
    $toolchain = sprintf(
        "set(CMAKE_SYSTEM_NAME Linux)\n"
        . "set(CMAKE_SYSTEM_PROCESSOR x86_64)\n"
        . "set(CMAKE_C_COMPILER \"%s\")\n"
        . "set(CMAKE_C_COMPILER_TARGET \"x86_64-unknown-linux-musl\")\n"
        . "set(CMAKE_SYSROOT \"%s\")\n"
        . "set(CMAKE_TRY_COMPILE_TARGET_TYPE STATIC_LIBRARY)\n"
        . "set(CMAKE_C_FLAGS_INIT \"-D_GNU_SOURCE=1 -O2 -fPIC\")\n",
        addcslashes($compiler, "\\\""),
        addcslashes($sysroot, "\\\"")
    );
    if (file_put_contents($toolchainFile, $toolchain) === false) {
        throw new RuntimeException('unable to write CMake toolchain file');
    }

    $libeventBuild = $buildRoot . '/libevent';
    runCommand([
        $cmake,
        '-S', $libeventSource,
        '-B', $libeventBuild,
        '-DCMAKE_POLICY_VERSION_MINIMUM=3.5',
        '-DCMAKE_TOOLCHAIN_FILE=' . $toolchainFile,
        '-DCMAKE_BUILD_TYPE=Release',
        '-DEVENT__LIBRARY_TYPE=STATIC',
        '-DEVENT__DISABLE_OPENSSL=ON',
        '-DEVENT__DISABLE_BENCHMARK=ON',
        '-DEVENT__DISABLE_TESTS=ON',
        '-DEVENT__DISABLE_REGRESS=ON',
        '-DEVENT__DISABLE_SAMPLES=ON',
    ]);
    runCommand([$cmake, '--build', $libeventBuild, '--parallel', '4', '--target', 'event_core_static']);
    $libeventArchive = $libeventBuild . '/lib/libevent_core.a';
    if (!is_file($libeventArchive)) {
        throw new RuntimeException('libevent core archive was not produced');
    }

    $php = $sdk . '/include/php';
    $phpIncludes = [
        $php,
        $php . '/main',
        $php . '/Zend',
        $php . '/TSRM',
        $php . '/ext',
        $php . '/ext/date/lib',
    ];

    $igbinaryObjects = [];
    $igbinaryIncludes = [...$phpIncludes, $igbinarySource, $igbinarySource . '/src/php7'];
    foreach (['igbinary.c', 'hash_si.c', 'hash_si_ptr.c'] as $file) {
        $source = $igbinarySource . '/src/php7/' . $file;
        $object = $objectRoot . '/igbinary-' . basename($file, '.c') . '.o';
        compileC(
            $compiler,
            $sysroot,
            $igbinaryIncludes,
            ['HAVE_IGBINARY=1', 'HAVE_PHP_SESSION=1'],
            $source,
            $object
        );
        $igbinaryObjects[] = $object;
    }

    $msgpackObjects = [];
    $msgpackIncludes = [...$phpIncludes, $msgpackSource];
    foreach (['msgpack.c', 'msgpack_pack.c', 'msgpack_unpack.c', 'msgpack_class.c', 'msgpack_convert.c'] as $file) {
        $source = $msgpackSource . '/' . $file;
        $object = $objectRoot . '/msgpack-' . basename($file, '.c') . '.o';
        compileC(
            $compiler,
            $sysroot,
            $msgpackIncludes,
            ['HAVE_PHP_SESSION=1'],
            $source,
            $object
        );
        $msgpackObjects[] = $object;
    }

    $eventObjects = [];
    $eventIncludes = [
        ...$phpIncludes,
        $libeventSource . '/include',
        $libeventBuild . '/include',
        $eventSource . '/php8',
        $eventSource . '/php8/src',
        $eventSource . '/php8/classes',
    ];
    $eventFiles = [
        'php_event.c',
        'src/util.c',
        'src/fe.c',
        'src/pe.c',
        'classes/event.c',
        'classes/base.c',
        'classes/event_config.c',
        'classes/buffer_event.c',
        'classes/buffer.c',
        'classes/event_util.c',
    ];
    foreach ($eventFiles as $file) {
        $source = $eventSource . '/php8/' . $file;
        $stem = str_replace('/', '-', basename(dirname($file)) . '-' . basename($file, '.c'));
        $object = $objectRoot . '/event-' . trim($stem, '.-') . '.o';
        compileC(
            $compiler,
            $sysroot,
            $eventIncludes,
            ['NDEBUG=1', 'PHP_EVENT_SOCKETS=1'],
            $source,
            $object
        );
        $eventObjects[] = $object;
    }

    $llvmAr = dirname($compiler) . '/llvm-ar';
    if (!is_file($llvmAr) || !is_executable($llvmAr)) {
        throw new RuntimeException("llvm-ar does not exist next to compiler: {$llvmAr}");
    }
    $extensionArchive = $output . '/libwebman_aot_extensions.a';
    runCommand([
        $llvmAr,
        'rcs',
        $extensionArchive,
        ...$igbinaryObjects,
        ...$msgpackObjects,
        ...$eventObjects,
    ]);

    $manifest = [
        'schema' => 'webman-aot-static-extensions-v1',
        'target' => 'x86_64-unknown-linux-musl',
        'modules' => [
            'event_module_entry',
            'igbinary_module_entry',
            'msgpack_module_entry',
        ],
        'archives' => [
            [
                'path' => basename($extensionArchive),
                'sha256' => hash_file('sha256', $extensionArchive),
            ],
            [
                'path' => $libeventArchive,
                'sha256' => hash_file('sha256', $libeventArchive),
            ],
        ],
        'sources' => array_map(
            static fn(string $id): array => [
                'id' => $id,
                'version' => $locks[$id]['version'],
                'sha256' => $locks[$id]['sha256'],
                'sourceUrl' => $locks[$id]['sourceUrl'],
            ],
            array_keys($archives)
        ),
    ];
    $manifestPath = $output . '/static-extensions.json';
    if (file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL
    ) === false) {
        throw new RuntimeException('unable to write static extension manifest');
    }

    fwrite(STDOUT, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
