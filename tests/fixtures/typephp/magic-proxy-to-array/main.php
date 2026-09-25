<?php

declare(strict_types=1);

final class MethodProxy
{
    public function __call(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

function main(): void
{
    $proxy = new MethodProxy();
    $result = $proxy->toArray('request');
    if ($result !== ['toArray', ['request']]) {
        throw new RuntimeException('dynamic proxy dispatch changed');
    }
}
