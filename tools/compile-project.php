#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Toolchain/ElfStaticVerifier.php';
require dirname(__DIR__) . '/src/Toolchain/StaticSdkFingerprint.php';
require dirname(__DIR__) . '/src/Toolchain/TypePhpProjectCompiler.php';

use WebmanAot\Toolchain\TypePhpProjectCompiler;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z][a-z0-9-]*)=(.+)$/D', $argument, $matches) !== 1
            || isset($options[$matches[1]])
        ) {
            throw new InvalidArgumentException("invalid or duplicate option: {$argument}");
        }
        $options[$matches[1]] = $matches[2];
    }
    foreach ([
        'mirror',
        'name',
        'php',
        'typephp',
        'phpx',
        'compiler',
        'objcopy',
        'sysroot',
        'phprc',
        'sdk-sha256',
    ] as $required) {
        if (!isset($options[$required])) {
            throw new InvalidArgumentException("missing --{$required}=...");
        }
    }
    $result = (new TypePhpProjectCompiler())->compile(
        $options['mirror'],
        $options['name'],
        [
            'php' => $options['php'],
            'typephp' => $options['typephp'],
            'phpx' => $options['phpx'],
            'compiler' => $options['compiler'],
            'objcopy' => $options['objcopy'],
            'sysroot' => $options['sysroot'],
            'phprc' => $options['phprc'],
            'sdkSha256' => $options['sdk-sha256'],
        ]
    );
    fwrite(STDOUT, json_encode(
        $result,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
