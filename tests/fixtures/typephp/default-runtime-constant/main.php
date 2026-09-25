<?php

declare(strict_types=1);

if (!defined('AOT_FIXTURE_DEFAULT')) {
    define('AOT_FIXTURE_DEFAULT', 42);
}

function fixtureDefaultConstant(int $value = \AOT_FIXTURE_DEFAULT): int
{
    return $value;
}

function main(): void
{
    if (fixtureDefaultConstant() !== 42) {
        throw new RuntimeException('runtime constant default changed');
    }
}
