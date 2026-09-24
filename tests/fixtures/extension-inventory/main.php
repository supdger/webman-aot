<?php

function main(): void
{
    $extensions = [
        'redis',
        'event',
        'intl',
        'openssl',
        'curl',
        'zip',
        'sockets',
        'pcntl',
        'posix',
        'igbinary',
        'msgpack',
    ];

    foreach ($extensions as $extension) {
        $status = extension_loaded($extension) ? 'loaded' : 'missing';
        echo $extension . '=' . $status . "\n";
    }
}
