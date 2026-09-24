#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/ElfStaticVerifier.php';

use WebmanAot\Toolchain\ElfStaticVerifier;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tools/verify-elf.php <artifact>\n");
    exit(64);
}

try {
    $verifier = new ElfStaticVerifier();
    $verifier->assertFullyStaticX86_64($argv[1]);
    $result = $verifier->inspect($argv[1]);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
