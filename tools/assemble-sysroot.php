#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
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

/**
 * @param list<string> $command
 */
function execute(array $command): void
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("unable to start command: {$command[0]}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("command failed with exit code {$exitCode}: {$command[0]}");
    }
}

try {
    $options = parseOptions($argv);
    $artifacts = rtrim($options['artifacts'] ?? '', '/\\');
    $output = rtrim($options['output'] ?? '', '/\\');
    $tar = $options['tar'] ?? 'tar';
    if ($artifacts === '' || $output === '') {
        throw new InvalidArgumentException(
            'Usage: php tools/assemble-sysroot.php --artifacts=<download-dir> --output=<empty-dir> [--tar=<tar>]'
        );
    }
    if (!is_dir($artifacts)) {
        throw new RuntimeException("artifact directory does not exist: {$artifacts}");
    }
    if (is_dir($output) && count(array_diff(scandir($output) ?: [], ['.', '..'])) > 0) {
        throw new RuntimeException("sysroot output directory must be empty: {$output}");
    }
    if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
        throw new RuntimeException("unable to create sysroot directory: {$output}");
    }

    $lock = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/toolchain.lock.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $packages = array_values(array_filter(
        $lock['components'] ?? [],
        static fn(array $component): bool => ($component['kind'] ?? null) === 'sysroot-package'
    ));
    if (count($packages) !== 5) {
        throw new RuntimeException('expected exactly five locked sysroot packages');
    }

    foreach ($packages as $package) {
        $archive = $artifacts . '/' . basename((string) $package['sourceUrl']);
        $actualDigest = is_file($archive) ? hash_file('sha256', $archive) : false;
        if (!is_string($actualDigest) || !hash_equals((string) $package['sha256'], $actualDigest)) {
            throw new RuntimeException("sysroot package digest mismatch: {$archive}");
        }
        execute([$tar, '-xf', $archive, '-C', $output]);
    }

    $required = [
        '/usr/include/stdio.h',
        '/usr/include/c++/12.2.1/vector',
        '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl/bits/c++config.h',
        '/usr/lib/libstdc++.a',
        '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/crtbegin.o',
        '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/libgcc.a',
    ];
    foreach ($required as $relativePath) {
        if (!is_file($output . $relativePath)) {
            throw new RuntimeException("assembled sysroot is incomplete: {$relativePath}");
        }
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'target' => 'x86_64-alpine-linux-musl',
                'packages' => array_column($packages, 'id'),
                'output' => $output,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
