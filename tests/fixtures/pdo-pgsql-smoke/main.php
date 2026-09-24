<?php

function requirePdoPgsql(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requirePdoPgsql(extension_loaded('PDO'), 'PDO is not statically registered');
    requirePdoPgsql(
        in_array('pgsql', PDO::getAvailableDrivers(), true),
        'pgsql is not registered as a PDO driver'
    );

    $dsn = getenv('AOT_PGSQL_DSN');
    $user = getenv('AOT_PGSQL_USER');
    $password = getenv('AOT_PGSQL_PASSWORD');
    requirePdoPgsql(is_string($dsn) && $dsn !== '', 'AOT_PGSQL_DSN is required');

    $pdo = new PDO(
        $dsn,
        is_string($user) ? $user : '',
        is_string($password) ? $password : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $value = $pdo->query('SELECT 1')->fetchColumn();
    requirePdoPgsql((int) $value === 1, 'unexpected PostgreSQL probe result');
    echo "pdo-pgsql-smoke-ok\n";
}
