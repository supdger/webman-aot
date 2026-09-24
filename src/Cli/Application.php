<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Doctor\Doctor;
use WebmanAot\Doctor\NativeSystemProbe;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\NativeDownloader;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainRepairer;
use WebmanAot\Version;

final class Application
{
    /** @var \Closure():Doctor */
    private readonly \Closure $doctorFactory;

    /** @var \Closure():ToolchainRepairer */
    private readonly \Closure $repairFactory;

    /**
     * @param (\Closure():Doctor)|null $doctorFactory
     * @param (\Closure():ToolchainRepairer)|null $repairFactory
     */
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        ?\Closure $doctorFactory = null,
        ?\Closure $repairFactory = null
    ) {
        $this->doctorFactory = $doctorFactory ?? function (): Doctor {
            $project = getcwd();
            if (!is_string($project) || $project === '') {
                throw new ConfigurationException('cannot resolve the current project directory');
            }

            $system = new NativeSystemProbe();

            return new Doctor(
                dirname(__DIR__, 2) . '/toolchain.lock.json',
                (new ToolchainLocator($this->layout))->activeArtifacts($system->hostId()),
                $project,
                $system
            );
        };
        $this->repairFactory = $repairFactory ?? function (): ToolchainRepairer {
            $system = new NativeSystemProbe();

            return new ToolchainRepairer(
                dirname(__DIR__, 2) . '/toolchain.lock.json',
                $this->layout,
                $system->hostId(),
                new NativeDownloader()
            );
        };
    }

    /**
     * @param list<string> $arguments
     */
    public function resolve(array $arguments): string
    {
        $command = $arguments[1] ?? 'help';

        if (in_array($command, ['help', '-h', '--help'], true)) {
            return 'help';
        }

        if (in_array($command, ['version', '-V', '--version'], true)) {
            return 'version';
        }
        if ($command === 'doctor') {
            return 'doctor';
        }

        throw new UsageException(
            "Unknown command: {$command}. Run 'webman-aot help' to see available commands."
        );
    }

    /**
     * @param list<string> $arguments
     */
    public function execute(string $command, array $arguments): void
    {
        if ($command === 'help') {
            $this->writeHelp();
            return;
        }
        if ($command === 'version') {
            fwrite(STDOUT, 'webman-aot ' . Version::VALUE . PHP_EOL);
            return;
        }
        if ($command === 'doctor') {
            $this->runDoctor(array_slice($arguments, 2));
            return;
        }

        throw new \LogicException("unsupported resolved command: {$command}");
    }

    private function writeHelp(): void
    {
        $lines = [
            'Webman AOT ' . Version::VALUE,
            '',
            'Usage:',
            '  webman-aot <command>',
            '',
            'Commands:',
            '  help       Show this help',
            '  version    Show the CLI version',
            '  doctor     Check the host, project, and locked toolchain',
            '',
            'User data:',
            '  ' . $this->layout->root(),
            '',
            'The target Webman project does not need an AOT Composer plugin.',
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param list<string> $options
     */
    private function runDoctor(array $options): void
    {
        $json = false;
        $repair = false;
        foreach ($options as $option) {
            if ($option === '--json' || $option === '--format=json') {
                $json = true;
                continue;
            }
            if ($option === '--repair') {
                $repair = true;
                continue;
            }
            throw new UsageException("Unknown doctor option: {$option}");
        }

        $repairResult = $repair ? ($this->repairFactory)()->repair() : null;
        $report = ($this->doctorFactory)()->inspect();
        if ($json) {
            $payload = $repair
                ? [
                    'schema' => 'webman-aot-doctor-repair-v1',
                    'repair' => $repairResult,
                    'doctor' => $report->toArray(),
                ]
                : $report->toArray();
            fwrite(STDOUT, json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL);
        } else {
            if (is_array($repairResult)) {
                fwrite(
                    STDOUT,
                    'Toolchain generation activated: ' . $repairResult['generation'] . PHP_EOL . PHP_EOL
                );
            }
            fwrite(STDOUT, $report->toHuman());
        }
        if (!$report->healthy()) {
            throw new UnavailableException('doctor found one or more failed checks');
        }
    }
}
