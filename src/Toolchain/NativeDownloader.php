<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class NativeDownloader implements Downloader
{
    public function download(string $url, string $destination): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->downloadWithWindowsCurl($url, $destination);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 60,
                'user_agent' => 'webman-aot',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $source = @fopen($url, 'rb', false, $context);
        if (!is_resource($source)) {
            throw new \RuntimeException("unable to download locked component: {$url}");
        }
        $target = @fopen($destination, 'xb');
        if (!is_resource($target)) {
            fclose($source);
            throw new \RuntimeException('unable to create candidate download');
        }
        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new \RuntimeException("unable to download locked component: {$url}");
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function downloadWithWindowsCurl(string $url, string $destination): void
    {
        $systemRoot = getenv('SystemRoot');
        $curl = is_string($systemRoot)
            ? str_replace('\\', '/', $systemRoot) . '/System32/curl.exe'
            : '';
        if (!str_starts_with($url, 'https://') || !is_file($curl)) {
            throw new \RuntimeException("secure Windows downloader is unavailable: {$url}");
        }
        $process = proc_open(
            [
                $curl,
                '-q',
                '--fail',
                '--location',
                '--silent',
                '--show-error',
                '--retry', '5',
                '--retry-delay', '2',
                '--retry-all-errors',
                '--connect-timeout', '30',
                '--proto', '=https',
                '--proto-redir', '=https',
                '--output', $destination,
                $url,
            ],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("unable to start secure Windows downloader: {$url}");
        }
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(
                "unable to download locked component: {$url}"
                . (is_string($error) && trim($error) !== '' ? ' (' . trim($error) . ')' : '')
            );
        }
    }
}
