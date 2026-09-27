<?php

declare(strict_types=1);

use WebmanAotBuilder\Platform\UserDirectoryLayout;
use WebmanAotBuilder\Toolchain\MacosToolchainPreparer;
use WebmanAotBuilder\Toolchain\MinimalComponentLock;
use WebmanAotBuilder\Toolchain\MinimalComponentManager;
use WebmanAotBuilder\Toolchain\NativeDownloader;
use WebmanAotBuilder\Toolchain\WindowsToolchainPreparer;

$app = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'WebmanAotBuilder\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $app . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$started = microtime(true);
try {
    if (count($argv) !== 2 || !is_file($argv[1]) || is_link($argv[1])) {
        throw new RuntimeException('bundled minimal component ZIP is missing or unsafe');
    }
    $host = match (true) {
        PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64' => 'macos-arm64',
        PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8 => 'windows-x86_64',
        default => throw new RuntimeException('unsupported offline installation host'),
    };
    $layout = UserDirectoryLayout::detect();
    $preparer = $host === 'macos-arm64'
        ? new MacosToolchainPreparer($app . '/tools/macos-prepare.php', $layout->root())
        : new WindowsToolchainPreparer($app . '/tools/windows-replay.ps1', $layout->root());
    $component = (new MinimalComponentLock())->forHost(
        $app . '/toolchain/minimal-components.lock.json',
        $app . '/toolchain.lock.json',
        $host
    );
    if ($component === null) {
        throw new RuntimeException("this package has no locked minimal component for {$host}");
    }
    $manager = new MinimalComponentManager(
        $layout,
        $host,
        $component,
        $preparer,
        new NativeDownloader()
    );
    fwrite(STDERR, "[offline] Installing verified minimal {$host} toolchain from this package...\n");
    $generation = $manager->ensure(
        $argv[1],
        static fn (string $message): int => fwrite(STDERR, "[offline] {$message}\n")
    );
    fwrite(STDOUT, sprintf(
        "[OK] Offline toolchain ready: %s; %.1f seconds\n",
        basename($generation),
        microtime(true) - $started
    ));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf(
        "[ERROR] Offline toolchain preparation failed after %.1f seconds: %s\n",
        microtime(true) - $started,
        $error->getMessage()
    ));
    exit(1);
}
