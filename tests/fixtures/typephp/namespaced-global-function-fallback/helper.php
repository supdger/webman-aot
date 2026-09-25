<?php

declare(strict_types=1);

function aot_test_global_helper(int $value): int
{
    return $value + 1;
}
