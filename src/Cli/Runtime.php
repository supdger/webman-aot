<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Cli;

use WebmanAotBuilder\Platform\UserDirectoryLayout;

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
        $pipeline = new StagePipeline($logger, new DiagnosticBundleWriter($logger, $this->layout));
        $application = new Application($this->layout, logger: $logger);
        $command = $arguments[1] ?? 'help';
        $resolved = '';

        return $pipeline->run($command, [
            'bootstrap' => static function (): void {
                if (PHP_INT_SIZE !== 8) {
                    throw new ConfigurationException('webman-aot-builder requires a 64-bit PHP runtime');
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
