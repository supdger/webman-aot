<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

interface Downloader
{
    public function download(string $url, string $destination): void;
}
