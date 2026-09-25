<?php

declare(strict_types=1);

function main(): void
{
    $status = 0;
    $pid = pcntl_wait($status, WNOHANG);
    echo 'pid=', $pid, ' status=', $status, PHP_EOL;
}
