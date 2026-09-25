<?php

declare(strict_types=1);

class FixtureConverter
{
    public function __construct(public array $options)
    {
    }
}

class FixtureConsumer
{
    public function __construct(
        public FixtureConverter $converter = new FixtureConverter(['hard_break' => true]),
    ) {
    }
}

function main(): void
{
    if ((new FixtureConsumer())->converter->options['hard_break'] !== true) {
        throw new RuntimeException('new default array argument changed');
    }
}
