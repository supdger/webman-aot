<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Update;

interface PackageExtractor
{
    public function extract(string $archive, string $destination): void;
}
