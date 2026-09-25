#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * TypePHP reads these host-runtime tables during translation. Record the
 * actual driver, not the PHP version written in the target SDK lock.
 *
 * @param list<string> $names
 */
function tableFingerprint(array $names): array
{
    sort($names, SORT_STRING);
    return ['count' => count($names), 'sha256' => hash('sha256', implode("\n", $names))];
}

$extensions = get_loaded_extensions();
$functions = get_defined_functions()['internal'];
$classes = get_declared_classes();
$constants = array_keys(get_defined_constants());
sort($extensions, SORT_STRING);
if (($argv[1] ?? null) === '--list-functions' && count($argv) === 2) {
    sort($functions, SORT_STRING);
    foreach ($functions as $function) {
        fwrite(STDOUT, $function . PHP_EOL);
    }
    exit(0);
}
if ($argc !== 1) {
    fwrite(STDERR, "usage: host-php-fingerprint.php [--list-functions]\n");
    exit(64);
}

fwrite(STDOUT, json_encode(
    [
        'schema' => 'webman-aot-host-php-v1',
        'phpVersion' => PHP_VERSION,
        'phpVersionId' => PHP_VERSION_ID,
        'zts' => PHP_ZTS === 1,
        'extensions' => tableFingerprint($extensions),
        'extensionNames' => array_values(array_unique($extensions)),
        'internalFunctions' => tableFingerprint($functions),
        'declaredClasses' => tableFingerprint($classes),
        'definedConstants' => tableFingerprint($constants),
    ],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL);
