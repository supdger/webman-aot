<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Doctor\Doctor;
use WebmanAot\Doctor\NativeSystemProbe;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\NativeDownloader;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainRepairer;
use WebmanAot\Update\NativeCliSelfChecker;
use WebmanAot\Update\UpdateManager;
use WebmanAot\Update\ZipPackageExtractor;
use WebmanAot\Version;

final class Application
{
    /** @var \Closure():Doctor */
    private readonly \Closure $doctorFactory;

    /** @var \Closure():ToolchainRepairer */
    private readonly \Closure $repairFactory;

    /** @var \Closure():UpdateManager */
    private readonly \Closure $updateFactory;

    /**
     * @param (\Closure():Doctor)|null $doctorFactory
     * @param (\Closure():ToolchainRepairer)|null $repairFactory
     * @param (\Closure():UpdateManager)|null $updateFactory
     */
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        ?\Closure $doctorFactory = null,
        ?\Closure $repairFactory = null,
        ?\Closure $updateFactory = null
    ) {
        $this->doctorFactory = $doctorFactory ?? function (): Doctor {
            $project = getcwd();
            if (!is_string($project) || $project === '') {
                throw new ConfigurationException('cannot resolve the current project directory');
            }

            $system = new NativeSystemProbe();
            $bundledLock = dirname(__DIR__, 2) . '/toolchain.lock.json';
            $locator = new ToolchainLocator($this->layout);

            return new Doctor(
                $locator->activeLock($system->hostId(), $bundledLock),
                $locator->activeArtifacts($system->hostId()),
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
        $this->updateFactory = $updateFactory ?? function (): UpdateManager {
            $system = new NativeSystemProbe();
            $downloader = new NativeDownloader();

            return new UpdateManager(
                $this->layout,
                dirname(__DIR__, 2),
                Version::VALUE,
                $system->hostId(),
                $downloader,
                new ZipPackageExtractor(),
                new NativeCliSelfChecker($this->layout)
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
        if ($command === 'self-update') {
            return 'self-update';
        }
        if ($command === 'toolchain' && ($arguments[2] ?? null) === 'update') {
            return 'toolchain-update';
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
        if ($command === 'self-update') {
            $this->runSelfUpdate(array_slice($arguments, 2));
            return;
        }
        if ($command === 'toolchain-update') {
            $this->runToolchainUpdate(array_slice($arguments, 3));
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
            '  self-update [--rollback]  Install or roll back a verified CLI generation',
            '  toolchain update [--rollback]  Install or roll back a verified toolchain',
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

    /**
     * @param list<string> $options
     */
    private function runSelfUpdate(array $options): void
    {
        $parsed = $this->parseUpdateOptions($options);
        $manager = ($this->updateFactory)();
        if ($parsed['rollback']) {
            fwrite(
                STDOUT,
                'CLI generation activated: ' . basename($manager->rollbackSelf()) . PHP_EOL
            );
            return;
        }
        $result = $manager->selfUpdate($parsed['manifest'], $parsed['trustedKeys']);
        fwrite(
            STDOUT,
            sprintf(
                "CLI generation activated: %s (%s)\n",
                $result['new'],
                $result['version']
            )
        );
    }

    /**
     * @param list<string> $options
     */
    private function runToolchainUpdate(array $options): void
    {
        $parsed = $this->parseUpdateOptions($options);
        $manager = ($this->updateFactory)();
        if ($parsed['rollback']) {
            fwrite(
                STDOUT,
                'Toolchain generation activated: '
                . basename($manager->rollbackToolchain())
                . PHP_EOL
            );
            return;
        }
        $result = $manager->updateToolchain($parsed['manifest'], $parsed['trustedKeys']);
        fwrite(
            STDOUT,
            sprintf(
                "Toolchain generation activated: %s (%s)\n",
                $result['generation'],
                $result['version']
            )
        );
    }

    /**
     * @param list<string> $options
     * @return array{rollback:bool,manifest:string,trustedKeys:string}
     */
    private function parseUpdateOptions(array $options): array
    {
        $rollback = false;
        $manifest = getenv('WEBMAN_AOT_UPDATE_MANIFEST_URL');
        $trustedKeys = dirname(__DIR__, 2) . '/update-trusted-keys.json';
        foreach ($options as $option) {
            if ($option === '--rollback') {
                $rollback = true;
                continue;
            }
            if (str_starts_with($option, '--manifest=')) {
                $manifest = substr($option, strlen('--manifest='));
                continue;
            }
            if (str_starts_with($option, '--trusted-keys=')) {
                $trustedKeys = substr($option, strlen('--trusted-keys='));
                continue;
            }
            throw new UsageException("Unknown update option: {$option}");
        }
        if (!$rollback && (!is_string($manifest) || $manifest === '')) {
            throw new ConfigurationException(
                'update manifest URL is not configured; use --manifest=<https-url>'
            );
        }
        if (!$rollback && (!is_string($trustedKeys) || $trustedKeys === '')) {
            throw new ConfigurationException('trusted update key store is not configured');
        }

        return [
            'rollback' => $rollback,
            'manifest' => is_string($manifest) ? $manifest : '',
            'trustedKeys' => $trustedKeys,
        ];
    }
}
