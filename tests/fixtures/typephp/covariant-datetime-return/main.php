<?php

declare(strict_types=1);

interface ClockLike
{
    public function now(): DateTimeImmutable;
}

class DatePointLike extends DateTimeImmutable
{
}

class ClockLikeImpl implements ClockLike
{
    public function now(): DatePointLike
    {
        return new DatePointLike();
    }
}

function main(): void
{
}
