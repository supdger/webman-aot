<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Toolchain\Downloader;

final class VerifiedDownloader
{
    public function __construct(private readonly Downloader $downloader)
    {
    }

    public function fetch(
        string $url,
        string $sha256,
        string $destination,
        ?\Closure $progress = null
    ): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('verified download digest is invalid');
        }
        if (file_exists($destination)) {
            throw new \RuntimeException('verified download destination already exists');
        }
        $partial = $destination . '.partial';
        if (file_exists($partial)) {
            unlink($partial);
        }
        try {
            $this->downloader->download($url, $partial, $progress);
            $actual = is_file($partial) ? hash_file('sha256', $partial) : false;
            if (!is_string($actual) || !hash_equals($sha256, $actual)) {
                throw new UnavailableException('downloaded update digest mismatch');
            }
            if (!rename($partial, $destination)) {
                throw new \RuntimeException('unable to promote verified update download');
            }
        } catch (\Throwable $throwable) {
            if (is_file($partial)) {
                unlink($partial);
            }
            throw $throwable;
        }
    }
}
