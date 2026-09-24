<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class NativeDownloader implements Downloader
{
    public function download(string $url, string $destination): void
    {
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
}
