<?php

declare(strict_types=1);

function main(): void
{
    if (\AotFixture\invokeHelper() !== 42) {
        throw new \RuntimeException('global function fallback changed');
    }
}
