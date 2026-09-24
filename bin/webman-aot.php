<?php

declare(strict_types=1);

use WebmanAot\Cli\ExitCode;
use WebmanAot\Cli\Runtime;
use WebmanAot\Platform\UserDirectoryLayout;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'WebmanAot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $root . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

try {
    exit((new Runtime(UserDirectoryLayout::detect()))->run($argv));
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(ExitCode::forFailure($throwable));
}
