<?php

declare(strict_types=1);

use WebmanAot\Cli\Application;
use WebmanAot\Platform\UserDirectoryLayout;

require dirname(__DIR__) . '/src/Version.php';
require dirname(__DIR__) . '/src/Platform/UserDirectoryLayout.php';
require dirname(__DIR__) . '/src/Cli/Application.php';

try {
    exit((new Application(UserDirectoryLayout::detect()))->run($argv));
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
