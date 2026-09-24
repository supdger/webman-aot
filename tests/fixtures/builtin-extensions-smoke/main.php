<?php

function requireBuiltinExtension(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    $formatter = new IntlDateFormatter(
        'en_US',
        IntlDateFormatter::SHORT,
        IntlDateFormatter::SHORT,
        'UTC'
    );
    requireBuiltinExtension(is_string($formatter->format(0)), 'Intl formatting failed');

    requireBuiltinExtension(
        openssl_digest('webman-aot', 'sha256') !== false,
        'OpenSSL digest failed'
    );

    $url = getenv('AOT_HTTP_URL');
    requireBuiltinExtension(is_string($url) && $url !== '', 'AOT_HTTP_URL is required');
    $curl = curl_init($url);
    requireBuiltinExtension($curl !== false, 'Curl initialization failed');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    requireBuiltinExtension(
        is_string($body) && $body !== '' && $statusCode === 200,
        'Curl HTTP probe failed'
    );

    $zipPath = '/tmp/webman-aot-extension-probe.zip';
    $zip = new ZipArchive();
    requireBuiltinExtension($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Zip open failed');
    requireBuiltinExtension($zip->addFromString('probe.txt', 'webman-aot-zip-ok'), 'Zip write failed');
    requireBuiltinExtension($zip->close(), 'Zip close failed');
    $zipRead = new ZipArchive();
    requireBuiltinExtension($zipRead->open($zipPath) === true, 'Zip reopen failed');
    requireBuiltinExtension($zipRead->getFromName('probe.txt') === 'webman-aot-zip-ok', 'Zip read failed');
    $zipRead->close();
    unlink($zipPath);

    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    requireBuiltinExtension($socket !== false, 'Sockets create failed');
    socket_close($socket);

    requireBuiltinExtension(posix_getpid() > 1, 'POSIX pid probe failed');

    $pid = pcntl_fork();
    requireBuiltinExtension($pid >= 0, 'PCNTL fork failed');
    if ($pid === 0) {
        exit(0);
    }
    $status = 0;
    requireBuiltinExtension(pcntl_waitpid($pid, $status) === $pid, 'PCNTL wait failed');
    requireBuiltinExtension(pcntl_wifexited($status), 'PCNTL child did not exit cleanly');

    echo "builtin-extensions-smoke-ok\n";
}
