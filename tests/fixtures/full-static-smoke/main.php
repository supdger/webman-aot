<?php

function requireFullStatic(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requireFullStatic(preg_match('/^(\d+)\.(\d+)/', PHP_VERSION, $matches) === 1, 'PHP_VERSION did not match');
    requireFullStatic(json_encode(['ok' => true]) === '{"ok":true}', 'json_encode() failed');
    echo "full-static-smoke-ok\n";
}
