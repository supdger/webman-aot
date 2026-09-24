#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function stripOptions(array $arguments): array
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

function stripRequired(array $options, string $name): string
{
    $value = rtrim($options[$name] ?? '', '/\\');
    if ($value === '') {
        throw new InvalidArgumentException("missing --{$name}=...");
    }
    return $value;
}

function stripFile(string $objcopy, string $file): void
{
    $process = proc_open(
        [$objcopy, '--strip-debug', $file],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException("unable to start llvm-objcopy for {$file}");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "llvm-objcopy failed for {$file}: " . trim((string) $stdout . (string) $stderr)
        );
    }
}

try {
    $options = stripOptions($argv);
    $sdk = realpath(stripRequired($options, 'sdk'));
    $objcopy = realpath(stripRequired($options, 'objcopy'));
    if ($sdk === false || !is_dir($sdk)) {
        throw new RuntimeException('SDK directory does not exist');
    }
    if ($objcopy === false || !is_file($objcopy) || !is_executable($objcopy)) {
        throw new RuntimeException('llvm-objcopy executable does not exist');
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sdk, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), ['a', 'o'], true)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($sdk) + 1));
        $files[$relative] = $entry->getPathname();
    }
    ksort($files, SORT_STRING);
    if ($files === []) {
        throw new RuntimeException('SDK contains no static archives or object files');
    }

    $digestInput = '';
    foreach ($files as $relative => $file) {
        stripFile($objcopy, $file);
        $digest = hash_file('sha256', $file);
        if (!is_string($digest)) {
            throw new RuntimeException("unable to hash stripped SDK file: {$relative}");
        }
        $digestInput .= $relative . "\0" . $digest . "\n";
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'schema' => 'webman-aot-stripped-sdk-v1',
                'files' => count($files),
                'sha256' => hash('sha256', $digestInput),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
