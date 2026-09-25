<?php

declare(strict_types=1);

function main(): void
{
    if (basename(__FILE__) !== 'main.php') {
        throw new RuntimeException('AOT __FILE__ did not resolve to the runtime file path');
    }
    $contents = file_get_contents(__DIR__ . '/payload.txt');
    if ($contents !== "portable-dir-ok\n") {
        throw new RuntimeException('AOT __DIR__ did not resolve to the runtime directory');
    }
    echo $contents;
}
