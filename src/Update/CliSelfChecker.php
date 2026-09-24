<?php

declare(strict_types=1);

namespace WebmanAot\Update;

interface CliSelfChecker
{
    public function check(string $appRoot, string $expectedVersion): bool;
}
