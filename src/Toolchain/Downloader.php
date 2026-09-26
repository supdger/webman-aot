<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

interface Downloader
{
    /**
     * @param (\Closure(int,?int):void)|null $progress Receives downloaded and total byte counts.
     */
    public function download(string $url, string $destination, ?\Closure $progress = null): void;
}
