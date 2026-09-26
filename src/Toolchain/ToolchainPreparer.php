<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

interface ToolchainPreparer
{
    /**
     * @param (\Closure(string):void)|null $progress
     */
    public function prepare(string $candidate, ?\Closure $progress = null): void;

    public function assertReady(string $generation): void;
}
