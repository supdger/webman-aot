<?php

declare(strict_types=1);

namespace WebmanAot\Doctor;

final class NativeSystemProbe implements SystemProbe
{
    public function hostId(): string
    {
        if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
            return 'macos-arm64';
        }
        if (PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8) {
            return 'windows-x86_64';
        }

        return strtolower(PHP_OS_FAMILY) . '-' . strtolower(php_uname('m'));
    }

    public function freeBytes(string $path): int
    {
        $candidate = $path;
        while (!file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return 0;
            }
            $candidate = $parent;
        }
        $bytes = disk_free_space($candidate);

        return is_float($bytes) ? (int) $bytes : 0;
    }

    public function canReach(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $socket = @stream_socket_client(
            'tcp://' . $host . ':443',
            $errorCode,
            $errorMessage,
            3,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
