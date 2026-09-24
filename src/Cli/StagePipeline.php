<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

final class StagePipeline
{
    public function __construct(
        private readonly RunLogger $logger,
        private readonly DiagnosticBundleWriter $diagnostics
    ) {
    }

    /**
     * @param array<string, callable():void> $stages
     */
    public function run(string $command, array $stages): int
    {
        $tracker = new StageTracker(array_keys($stages));
        $this->logger->event('info', 'run.started', null, 'CLI run started', [
            'command' => $command,
        ]);

        foreach ($stages as $stage => $operation) {
            $tracker->start($stage);
            $this->logger->event('info', 'stage.started', $stage, 'Stage started');
            try {
                $operation();
                $tracker->succeed($stage);
                $this->logger->event('info', 'stage.succeeded', $stage, 'Stage succeeded');
            } catch (\Throwable $throwable) {
                $tracker->fail($stage, $throwable);
                $exitCode = ExitCode::forFailure($throwable);
                $this->logger->event('error', 'stage.failed', $stage, $throwable->getMessage(), [
                    'failureType' => $throwable::class,
                    'exitCode' => $exitCode,
                ]);
                $diagnostic = $this->diagnostics->write(
                    $command,
                    $stage,
                    $exitCode,
                    $throwable,
                    $tracker
                );
                fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
                fwrite(STDERR, 'Diagnostic bundle: ' . $diagnostic . PHP_EOL);

                return $exitCode;
            }
        }

        $this->logger->event('info', 'run.succeeded', null, 'CLI run succeeded', [
            'exitCode' => ExitCode::SUCCESS,
        ]);

        return ExitCode::SUCCESS;
    }
}
