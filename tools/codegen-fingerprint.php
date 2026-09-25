#!/usr/bin/env php
<?php

declare(strict_types=1);

if (count($argv) !== 2 || !str_starts_with($argv[1], '--build=')) {
    fwrite(STDERR, "usage: codegen-fingerprint.php --build=PATH\n");
    exit(64);
}
$root = realpath(substr($argv[1], strlen('--build=')));
if (!is_string($root) || !is_dir($root)) {
    fwrite(STDERR, "generated build directory is missing\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->isLink()
        || !in_array(strtolower($entry->getExtension()), ['cc', 'h'], true)
    ) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'cache/')) {
        continue;
    }
    $contents = file_get_contents($entry->getPathname());
    if (!is_string($contents)) {
        throw new RuntimeException("unable to read generated file: {$relative}");
    }
    $files[$relative] = hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
}
ksort($files, SORT_STRING);
foreach ($files as $relative => $sha256) {
    fwrite(STDOUT, "{$sha256}  {$relative}\n");
}
