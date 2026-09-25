<?php

declare(strict_types=1);

class FixtureServerReference
{
    public array $data;

    public function __construct()
    {
        $this->data = &$GLOBALS['_SERVER'];
    }
}

function main(): void
{
    $_SERVER['AOT_FIXTURE_REFERENCE'] = 'before';
    $capture = new FixtureServerReference();
    $_SERVER['AOT_FIXTURE_REFERENCE'] = 'after';
    if ($capture->data['AOT_FIXTURE_REFERENCE'] !== 'after') {
        throw new RuntimeException('GLOBALS static slot reference detached');
    }
}
