<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

interface CompatibilityRule
{
    public function id(): string;

    public function dependency(): string;

    public function sourcePath(): string;

    public function transform(string $source, string $version): string;
}
