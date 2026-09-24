<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\DiagnosticBundleWriter;
use WebmanAot\Cli\RunLogger;
use WebmanAot\Cli\StagePipeline;
use WebmanAot\Cli\UsageException;
use WebmanAot\Platform\UserDirectoryLayout;

$root = dirname(__DIR__, 2);
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

$failureStage = $argv[1] ?? '';
if (!in_array($failureStage, ['bootstrap', 'dispatch', 'execute'], true)) {
    fwrite(STDERR, "Usage: php stage-failure-cli.php <bootstrap|dispatch|execute>\n");
    exit(64);
}

$logger = RunLogger::open(UserDirectoryLayout::detect());
$pipeline = new StagePipeline($logger, new DiagnosticBundleWriter($logger));
$stages = [];
foreach (['bootstrap', 'dispatch', 'execute'] as $stage) {
    $stages[$stage] = static function () use ($stage, $failureStage): void {
        if ($stage !== $failureStage) {
            return;
        }
        throw match ($stage) {
            'bootstrap' => new ConfigurationException('injected bootstrap failure'),
            'dispatch' => new UsageException('injected dispatch failure'),
            default => new RuntimeException('injected execute failure'),
        };
    };
}

exit($pipeline->run('failure-integration', $stages));
