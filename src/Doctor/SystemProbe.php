<?php

declare(strict_types=1);

namespace WebmanAot\Doctor;

interface SystemProbe
{
    public function hostId(): string;

    public function freeBytes(string $path): int;

    public function canReach(string $url): bool;
}
