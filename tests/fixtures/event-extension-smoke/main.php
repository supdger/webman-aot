<?php

function requireEventExtension(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requireEventExtension(extension_loaded('event'), 'event is not statically registered');

    $base = new EventBase();
    $method = $base->getMethod();
    requireEventExtension(is_string($method) && $method !== '', 'EventBase backend is unavailable');

    echo "event-extension-smoke-ok:" . $method . "\n";
}
