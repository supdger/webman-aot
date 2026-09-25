<?php

declare(strict_types=1);

namespace AotFixture;

function invokeHelper(): int
{
    return aot_test_global_helper(41);
}
