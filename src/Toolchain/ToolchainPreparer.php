<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

interface ToolchainPreparer
{
    public function prepare(string $candidate): void;

    public function assertReady(string $generation): void;
}
