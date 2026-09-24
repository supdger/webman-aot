<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Platform\UserDirectoryLayout;

final class Runtime
{
    public function __construct(private readonly UserDirectoryLayout $layout)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $logger = RunLogger::open($this->layout);
        $pipeline = new StagePipeline($logger, new DiagnosticBundleWriter($logger));
        $application = new Application($this->layout);
        $command = $arguments[1] ?? 'help';
        $resolved = '';

        return $pipeline->run($command, [
            'bootstrap' => static function (): void {
                if (PHP_INT_SIZE !== 8) {
                    throw new ConfigurationException('webman-aot requires a 64-bit PHP runtime');
                }
            },
            'dispatch' => static function () use ($application, $arguments, &$resolved): void {
                $resolved = $application->resolve($arguments);
            },
            'execute' => static function () use ($application, $arguments, &$resolved): void {
                $application->execute($resolved, $arguments);
            },
        ]);
    }
}
