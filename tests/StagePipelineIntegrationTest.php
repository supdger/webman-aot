<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\ExitCode;
use WebmanAot\Cli\UnavailableException;
use WebmanAot\Cli\UsageException;

final class StagePipelineIntegrationTest
{
    public function run(string $root): void
    {
        $this->assertExitCodeMapping();
        foreach ([
            'bootstrap' => ExitCode::CONFIGURATION,
            'dispatch' => ExitCode::USAGE,
            'execute' => ExitCode::SOFTWARE,
        ] as $stage => $expectedExitCode) {
            $this->assertStageFailure($root, $stage, $expectedExitCode);
        }
        $this->assertProductionDispatchFailure($root);
    }

    private function assertExitCodeMapping(): void
    {
        $this->assert(
            ExitCode::forFailure(new UsageException('test')) === ExitCode::USAGE,
            'usage exit code drifted'
        );
        $this->assert(
            ExitCode::forFailure(new UnavailableException('test')) === ExitCode::UNAVAILABLE,
            'unavailable exit code drifted'
        );
        $this->assert(
            ExitCode::forFailure(new ConfigurationException('test')) === ExitCode::CONFIGURATION,
            'configuration exit code drifted'
        );
        $this->assert(
            ExitCode::forFailure(new RuntimeException('test')) === ExitCode::SOFTWARE,
            'software exit code drifted'
        );
    }

    private function assertStageFailure(string $root, string $stage, int $expectedExitCode): void
    {
        $home = sys_get_temp_dir() . '/webman-aot-stage-' . $stage . '-' . bin2hex(random_bytes(6));
        try {
            $result = $this->runProcess(
                [PHP_BINARY, $root . '/tests/fixtures/stage-failure-cli.php', $stage],
                $root,
                $this->environmentWithHome($home)
            );
            $this->assert(
                $result['exitCode'] === $expectedExitCode,
                "{$stage} failure returned an unstable exit code"
            );
            $this->assert(
                str_contains($result['stderr'], 'Diagnostic bundle:'),
                "{$stage} failure did not expose its diagnostic bundle"
            );
            $diagnostic = $this->readSingleDiagnostic($home);
            $this->assert($diagnostic['failedStage'] === $stage, "{$stage} diagnostic stage drifted");
            $this->assert(
                $diagnostic['exitCode'] === $expectedExitCode,
                "{$stage} diagnostic exit code drifted"
            );
            $this->assertStageStates($diagnostic['stages'], $stage);
            $this->assertLogs($home);
        } finally {
            $this->removeDirectory($home);
        }
    }

    private function assertProductionDispatchFailure(string $root): void
    {
        $home = sys_get_temp_dir() . '/webman-aot-unknown-' . bin2hex(random_bytes(6));
        try {
            $result = $this->runProcess(
                [PHP_BINARY, $root . '/bin/webman-aot.php', 'unknown-command'],
                $root . '/tests/fixtures/minimal-webman',
                $this->environmentWithHome($home)
            );
            $this->assert(
                $result['exitCode'] === ExitCode::USAGE,
                'production unknown command must return the stable usage exit code'
            );
            $diagnostic = $this->readSingleDiagnostic($home);
            $this->assert(
                $diagnostic['failedStage'] === 'dispatch',
                'production unknown command must fail during dispatch'
            );
        } finally {
            $this->removeDirectory($home);
        }
    }

    /**
     * @param list<array{name:string,status:string,startedAt:?string,finishedAt:?string,error:?string}> $stages
     */
    private function assertStageStates(array $stages, string $failedStage): void
    {
        $failedIndex = array_search($failedStage, array_column($stages, 'name'), true);
        $this->assert(is_int($failedIndex), "failed stage is missing: {$failedStage}");
        foreach ($stages as $index => $stage) {
            $expected = match (true) {
                $index < $failedIndex => 'succeeded',
                $index === $failedIndex => 'failed',
                default => 'pending',
            };
            $this->assert(
                $stage['status'] === $expected,
                sprintf(
                    'stage %s should be %s after %s failure',
                    $stage['name'],
                    $expected,
                    $failedStage
                )
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readSingleDiagnostic(string $home): array
    {
        $paths = glob($home . '/logs/*/diagnostic.json');
        $this->assert(is_array($paths) && count($paths) === 1, 'expected exactly one diagnostic bundle');
        $contents = file_get_contents($paths[0]);
        $this->assert(is_string($contents), 'unable to read diagnostic bundle');

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertLogs(string $home): void
    {
        $eventPaths = glob($home . '/logs/*/events.jsonl');
        $humanPaths = glob($home . '/logs/*/run.log');
        $this->assert(is_array($eventPaths) && count($eventPaths) === 1, 'structured log is missing');
        $this->assert(is_array($humanPaths) && count($humanPaths) === 1, 'human log is missing');

        $lines = file($eventPaths[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assert(is_array($lines) && count($lines) >= 3, 'structured log is incomplete');
        $events = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines
        );
        $this->assert(
            in_array('stage.failed', array_column($events, 'event'), true),
            'structured log lacks the failure event'
        );
        $human = file_get_contents($humanPaths[0]);
        $this->assert(is_string($human) && str_contains($human, 'ERROR'), 'human log lacks the failure');
    }

    /**
     * @return array<string, string>
     */
    private function environmentWithHome(string $home): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['WEBMAN_AOT_HOME'] = $home;

        return $environment;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $directory, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $directory,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('unable to start stage integration process');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
