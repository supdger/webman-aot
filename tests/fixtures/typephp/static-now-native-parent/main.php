<?php

declare(strict_types=1);

trait StaticNowTrait
{
    private static function translationDate(): object
    {
        return static::now();
    }
}

class PeriodWithTrait extends DatePeriod
{
    use StaticNowTrait;
}

function main(): void
{
}
