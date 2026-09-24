<?php

function requireRedisExtension(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requireRedisExtension(extension_loaded('redis'), 'redis is not statically registered');

    $host = getenv('AOT_REDIS_HOST');
    $port = getenv('AOT_REDIS_PORT');
    requireRedisExtension(is_string($host) && $host !== '', 'AOT_REDIS_HOST is required');

    $redis = new Redis();
    requireRedisExtension(
        $redis->connect($host, is_string($port) ? (int) $port : 6379, 2.0),
        'Redis connection failed'
    );
    $key = 'webman-aot:static-probe';
    requireRedisExtension($redis->set($key, 'redis-ok', 10), 'Redis set failed');
    requireRedisExtension($redis->get($key) === 'redis-ok', 'Redis get failed');
    requireRedisExtension($redis->del($key) === 1, 'Redis cleanup failed');
    $redis->close();

    echo "redis-extension-smoke-ok\n";
}
