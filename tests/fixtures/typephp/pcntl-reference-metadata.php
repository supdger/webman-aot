<?php

declare(strict_types=1);

// Compile-only fixture: calls are intentionally not invoked during acceptance.
function main(): void
{
    $waitStatus = 0;
    $waitUsage = [];
    pcntl_wait($waitStatus, 1, $waitUsage);

    $pidStatus = 0;
    $pidUsage = [];
    pcntl_waitpid(-1, $pidStatus, 1, $pidUsage);

    $info = [];
    pcntl_waitid(0, 0, $info, 1);

    $oldSignals = [];
    pcntl_sigprocmask(0, [], $oldSignals);

    $namedStatus = 0;
    pcntl_wait(status: $namedStatus, flags: 1);
}
