<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Update;

interface CliSelfChecker
{
    public function check(string $appRoot, string $expectedVersion): bool;
}
