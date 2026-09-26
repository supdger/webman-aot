<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class NativeDownloader implements Downloader
{
    public function download(string $url, string $destination, ?\Closure $progress = null): void
    {
        $downloadUrl = self::sourceDownloadUrl($url);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->downloadWithWindowsCurl($downloadUrl, $destination, $progress);
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
        $source = @fopen($downloadUrl, 'rb', false, $context);
        if (!is_resource($source)) {
            throw new \RuntimeException("unable to download locked component: {$url}");
        }
        $target = @fopen($destination, 'xb');
        if (!is_resource($target)) {
            fclose($source);
            throw new \RuntimeException('unable to create candidate download');
        }
        try {
            $downloaded = 0;
            $nextReport = microtime(true) + 5;
            while (!feof($source)) {
                $copied = stream_copy_to_stream($source, $target, 1024 * 1024);
                if ($copied === false || ($copied === 0 && !feof($source))) {
                    throw new \RuntimeException("unable to download locked component: {$url}");
                }
                $downloaded += $copied;
                if ($progress !== null && microtime(true) >= $nextReport) {
                    $progress($downloaded);
                    $nextReport = microtime(true) + 5;
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    public static function sourceDownloadUrl(string $url): string
    {
        if (preg_match(
            '~^https://github\.com/([^/]+)/([^/]+)/archive/refs/tags/([^/]+)\.tar\.gz$~D',
            $url,
            $matches
        ) === 1) {
            return "https://codeload.github.com/{$matches[1]}/{$matches[2]}/tar.gz/refs/tags/{$matches[3]}";
        }
        if (preg_match(
            '~^https://github\.com/([^/]+)/([^/]+)/archive/([^/]+)\.zip$~D',
            $url,
            $matches
        ) === 1) {
            return "https://codeload.github.com/{$matches[1]}/{$matches[2]}/zip/{$matches[3]}";
        }

        return $url;
    }

    /**
     * @param (\Closure(int):void)|null $progress
     */
    private function downloadWithWindowsCurl(
        string $url,
        string $destination,
        ?\Closure $progress
    ): void
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
                '--progress-bar',
                '--show-error',
                '--retry', '5',
                '--retry-delay', '2',
                '--retry-all-errors',
                '--connect-timeout', '15',
                '--max-time', '180',
                '--proto', '=https',
                '--proto-redir', '=https',
                '--output', $destination,
                $url,
            ],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'w'],
                2 => STDERR,
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("unable to start secure Windows downloader: {$url}");
        }
        $exit = null;
        $nextReport = microtime(true) + 5;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if ($progress !== null && microtime(true) >= $nextReport) {
                clearstatcache(true, $destination);
                $bytes = is_file($destination) ? filesize($destination) : 0;
                $progress(is_int($bytes) ? $bytes : 0);
                $nextReport = microtime(true) + 5;
            }
            usleep(200000);
        }
        $closed = proc_close($process);
        $exitCode = $exit >= 0 ? $exit : $closed;
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "unable to download locked component: {$url} (curl exit code {$exitCode})"
            );
        }
    }
}
