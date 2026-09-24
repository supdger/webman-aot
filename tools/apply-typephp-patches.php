#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';

use WebmanAot\Toolchain\UnifiedPatchApplier;

/**
 * @return array<string, string>
 */
function patchOptions(array $arguments): array
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

function patchRequired(array $options, string $name): string
{
    $value = rtrim($options[$name] ?? '', '/\\');
    if ($value === '') {
        throw new InvalidArgumentException("missing --{$name}=...");
    }
    return $value;
}

try {
    $options = patchOptions($argv);
    $typephp = patchRequired($options, 'typephp');
    $phpx = patchRequired($options, 'phpx');
    if (!is_dir($typephp) || !is_dir($phpx)) {
        throw new RuntimeException('TypePHP and PHPX source directories must exist');
    }

    $vendoredPhpx = $typephp . '/vendor/swoole/phpx';
    $vendoredRealPath = realpath($vendoredPhpx);
    $phpxRealPath = realpath($phpx);
    if ($vendoredRealPath === false || $phpxRealPath === false || $vendoredRealPath !== $phpxRealPath) {
        throw new RuntimeException('PHPX must be available at TypePHP vendor/swoole/phpx');
    }

    $patchDirectory = dirname(__DIR__) . '/toolchain/patches/typephp/0.9.2';
    $manifest = json_decode(
        (string) file_get_contents($patchDirectory . '/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $rules = $manifest['rules'] ?? null;
    if (!is_array($rules) || $rules === []) {
        throw new RuntimeException('TypePHP patch manifest has no rules');
    }

    foreach ($rules as $rule) {
        $path = (string) ($rule['path'] ?? '');
        $expected = (string) ($rule['beforeSha256'] ?? '');
        $actual = is_file($typephp . '/' . $path)
            ? hash_file('sha256', $typephp . '/' . $path)
            : false;
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException("TypePHP pristine source digest mismatch: {$path}");
        }
    }

    $applier = new UnifiedPatchApplier();
    foreach ([
        '0001-full-static-sdk-target.patch',
        '0002-static-extension-registry.patch',
        '0003-reproducible-source-identities.patch',
        '0004-full-static-host-target-separation.patch',
        '0005-windows-clang-response-paths.patch',
    ] as $patch) {
        $applier->apply($patchDirectory . '/' . $patch, $typephp);
    }

    foreach ($rules as $rule) {
        $path = (string) $rule['path'];
        $expected = (string) $rule['afterSha256'];
        $actual = hash_file('sha256', $typephp . '/' . $path);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException("patched TypePHP source digest mismatch: {$path}");
        }
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'component' => 'typephp-source',
                'version' => '0.9.2',
                'patches' => 5,
                'rules' => count($rules),
                'status' => 'applied-and-verified',
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
