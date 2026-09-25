#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Toolchain/FullStaticProjectOverlay.php';

use WebmanAot\Toolchain\FullStaticProjectOverlay;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(project|sysroot)=(.+)$/D', $argument, $matches) !== 1
            || isset($options[$matches[1]])
        ) {
            throw new InvalidArgumentException("invalid or duplicate option: {$argument}");
        }
        $options[$matches[1]] = $matches[2];
    }
    if (count($options) !== 2) {
        throw new InvalidArgumentException('usage: overlay-project.php --project=PATH --sysroot=PATH');
    }
    (new FullStaticProjectOverlay())->apply($options['project'], $options['sysroot']);
    $digest = hash_file('sha256', $options['project']);
    if (!is_string($digest)) {
        throw new RuntimeException('unable to hash full-static project overlay');
    }
    fwrite(STDOUT, json_encode(
        ['projectSha256' => $digest],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
