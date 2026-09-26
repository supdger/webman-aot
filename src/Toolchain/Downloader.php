<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

interface Downloader
{
    /**
     * @param (\Closure(int):void)|null $progress Receives downloaded byte counts.
     */
    public function download(string $url, string $destination, ?\Closure $progress = null): void;
}
