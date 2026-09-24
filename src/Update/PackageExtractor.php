<?php

declare(strict_types=1);

namespace WebmanAot\Update;

interface PackageExtractor
{
    public function extract(string $archive, string $destination): void;
}
