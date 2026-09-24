#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/SdkArchiveGuard.php';

use WebmanAot\Toolchain\SdkArchiveGuard;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/guard-sdk.php <sdk-directory> <llvm-nm>\n");
    exit(64);
}

try {
    $result = (new SdkArchiveGuard())->inspect($argv[1], $argv[2]);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
