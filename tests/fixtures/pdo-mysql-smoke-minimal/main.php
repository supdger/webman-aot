<?php

function requirePdoMysql(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    requirePdoMysql(extension_loaded('PDO'), 'PDO is not statically registered');
    requirePdoMysql(extension_loaded('mysqlnd'), 'mysqlnd is not statically registered');
    requirePdoMysql(extension_loaded('pdo_mysql'), 'pdo_mysql is not statically registered');

    $dsn = getenv('AOT_MYSQL_DSN');
    $user = getenv('AOT_MYSQL_USER');
    $password = getenv('AOT_MYSQL_PASSWORD');
    requirePdoMysql(is_string($dsn) && $dsn !== '', 'AOT_MYSQL_DSN is required');

    $pdo = new PDO(
        $dsn,
        is_string($user) ? $user : '',
        is_string($password) ? $password : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $value = $pdo->query('SELECT 1')->fetchColumn();
    requirePdoMysql((int) $value === 1, 'unexpected MySQL probe result');
    echo "pdo-mysql-smoke-ok\n";
}
