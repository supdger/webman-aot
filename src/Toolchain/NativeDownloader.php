<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class NativeDownloader implements Downloader
{
    /**
     * @param (\Closure(string):void)|null $diagnostic
     */
    public function download(
        string $url,
        string $destination,
        ?\Closure $progress = null,
        ?\Closure $diagnostic = null
    ): void
    {
        $downloadUrl = self::sourceDownloadUrl($url);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->downloadWithWindowsCurl($downloadUrl, $destination, $progress, $diagnostic);
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
        ?\Closure $progress,
        ?\Closure $diagnostic
    ): void
    {
        $systemRoot = getenv('SystemRoot');
        $curl = is_string($systemRoot)
            ? str_replace('\\', '/', $systemRoot) . '/System32/curl.exe'
            : '';
        if (!str_starts_with($url, 'https://') || !is_file($curl)) {
            throw new \RuntimeException("secure Windows downloader is unavailable: {$url}");
        }
        $errorPath = $destination . '.curl-stderr-' . bin2hex(random_bytes(6));
        $process = proc_open(
            [
                $curl,
                '-q',
                '--fail',
                '--location',
                '--no-progress-meter',
                '--show-error',
                '--retry', '5',
                '--retry-delay', '2',
                '--retry-all-errors',
                '--connect-timeout', '15',
                '--speed-limit', '1024',
                '--speed-time', '120',
                '--proto', '=https',
                '--proto-redir', '=https',
                '--output', $destination,
                $url,
            ],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'w'],
                2 => ['file', $errorPath, 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            if (is_file($errorPath)) {
                unlink($errorPath);
            }
            throw new \RuntimeException("unable to start secure Windows downloader: {$url}");
        }
        $errorOffset = 0;
        $pendingError = '';
        $reportErrors = static function (bool $final = false) use (
            $errorPath,
            $diagnostic,
            &$errorOffset,
            &$pendingError
        ): void {
            $chunk = @file_get_contents($errorPath, false, null, $errorOffset);
            if (is_string($chunk) && $chunk !== '') {
                $errorOffset += strlen($chunk);
                $lines = explode("\n", $pendingError . $chunk);
                $pendingError = array_pop($lines);
                foreach ($lines as $line) {
                    $line = rtrim($line, "\r");
                    if ($line !== '') {
                        if ($diagnostic !== null) {
                            $diagnostic($line);
                        } else {
                            fwrite(STDERR, $line . "\n");
                        }
                    }
                }
            }
            if ($final && $pendingError !== '') {
                if ($diagnostic !== null) {
                    $diagnostic($pendingError);
                } else {
                    fwrite(STDERR, $pendingError . "\n");
                }
            }
        };
        try {
            $exit = null;
            $nextReport = microtime(true) + 5;
            while (true) {
                $status = proc_get_status($process);
                $reportErrors();
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $nextReport) {
                    clearstatcache(true, $destination);
                    $bytes = is_file($destination) ? filesize($destination) : 0;
                    $downloaded = is_int($bytes) ? $bytes : 0;
                    if ($progress !== null) {
                        $progress($downloaded);
                    } else {
                        fwrite(STDERR, sprintf(
                            "[download] %.1f MiB received; still downloading\n",
                            $downloaded / 1048576
                        ));
                    }
                    $nextReport = microtime(true) + 5;
                }
                usleep(200000);
            }
            $closed = proc_close($process);
            $reportErrors(true);
            $exitCode = $exit >= 0 ? $exit : $closed;
            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "unable to download locked component: {$url} (curl exit code {$exitCode})"
                );
            }
        } finally {
            if (is_file($errorPath)) {
                unlink($errorPath);
            }
        }
    }
}
