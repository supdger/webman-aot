<?php

function requireSerializerExtension(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requireSerializerExtension(extension_loaded('igbinary'), 'igbinary is not statically registered');
    requireSerializerExtension(extension_loaded('msgpack'), 'msgpack is not statically registered');

    $value = ['name' => 'webman-aot', 'count' => 3];

    $igbinary = igbinary_serialize($value);
    requireSerializerExtension(
        igbinary_unserialize($igbinary) === $value,
        'igbinary round trip failed'
    );

    $msgpack = msgpack_serialize($value);
    requireSerializerExtension(
        msgpack_unserialize($msgpack) === $value,
        'msgpack round trip failed'
    );

    echo "serializer-extensions-smoke-ok\n";
}
