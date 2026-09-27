<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Doctor;

interface SystemProbe
{
    public function hostId(): string;

    public function freeBytes(string $path): int;

    public function canReach(string $url): bool;
}
