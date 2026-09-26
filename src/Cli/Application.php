<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Doctor\Doctor;
use WebmanAot\Doctor\NativeSystemProbe;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Project\DistributionVerifier;
use WebmanAot\Project\ProjectBuilder;
use WebmanAot\Toolchain\NativeDownloader;
use WebmanAot\Toolchain\MacosToolchainPreparer;
use WebmanAot\Toolchain\PreparedToolchain;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainPreparer;
use WebmanAot\Toolchain\ToolchainRepairer;
use WebmanAot\Toolchain\WindowsToolchainPreparer;
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

    /** @var \Closure():DistributionVerifier */
    private readonly \Closure $verifierFactory;

    /**
     * @param (\Closure():Doctor)|null $doctorFactory
     * @param (\Closure():ToolchainRepairer)|null $repairFactory
     * @param (\Closure():UpdateManager)|null $updateFactory
     * @param (\Closure():DistributionVerifier)|null $verifierFactory
     */
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        ?\Closure $doctorFactory = null,
        ?\Closure $repairFactory = null,
        ?\Closure $updateFactory = null,
        ?\Closure $verifierFactory = null,
        private readonly ?RunLogger $logger = null
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
                $system,
                Doctor::MINIMUM_FREE_BYTES,
                $this->nativePreparer($system->hostId()),
                $locator->activeGeneration($system->hostId())
            );
        };
        $this->repairFactory = $repairFactory ?? function (): ToolchainRepairer {
            $system = new NativeSystemProbe();

            return new ToolchainRepairer(
                dirname(__DIR__, 2) . '/toolchain.lock.json',
                $this->layout,
                $system->hostId(),
                new NativeDownloader(),
                $this->nativePreparer($system->hostId())
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
                new NativeCliSelfChecker($this->layout),
                $this->nativePreparer($system->hostId())
            );
        };
        $this->verifierFactory = $verifierFactory
            ?? static fn (): DistributionVerifier => new DistributionVerifier();
    }

    private function nativePreparer(string $host): ?ToolchainPreparer
    {
        if ($host === 'macos-arm64') {
            return new MacosToolchainPreparer(
                dirname(__DIR__, 2) . '/tools/macos-prepare.php',
                $this->layout->root()
            );
        }
        if ($host !== 'windows-x86_64') {
            return null;
        }
        return new WindowsToolchainPreparer(
            dirname(__DIR__, 2) . '/tools/windows-replay.ps1',
            $this->layout->root()
        );
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
        if ($command === 'build') {
            return 'build';
        }
        if ($command === 'verify') {
            return 'verify';
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
        if ($command === 'build') {
            $this->runBuild(array_slice($arguments, 2));
            return;
        }
        if ($command === 'verify') {
            $this->runVerify(array_slice($arguments, 2));
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
            '  doctor     Check the host and project; prepare missing toolchain components',
            '  build      Compile and verify a Linux amd64 musl distribution',
            '  verify     Independently check dist-aot (use --deployed after editing external resources)',
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
    private function runBuild(array $options): void
    {
        $explicitProfile = null;
        $json = false;
        foreach ($options as $option) {
            if ($option === '--json' || $option === '--format=json') {
                $json = true;
                continue;
            }
            if (str_starts_with($option, '--profile=') && $explicitProfile === null) {
                $explicitProfile = substr($option, strlen('--profile='));
                continue;
            }
            throw new UsageException("Unknown build option: {$option}");
        }
        if ($explicitProfile === '') {
            throw new UsageException('build --profile requires webman or saiadmin');
        }
        $project = getcwd();
        if (!is_string($project) || $project === '') {
            throw new ConfigurationException('cannot resolve the current project directory');
        }
        $doctor = ($this->doctorFactory)()->inspect();
        if (!$doctor->healthy()) {
            throw new UnavailableException('build doctor failed; run webman-aot doctor for details');
        }
        $host = (new NativeSystemProbe())->hostId();
        $locator = new ToolchainLocator($this->layout);
        $generation = $locator->activeGeneration($host);
        if ($generation === null) {
            throw new UnavailableException('private toolchain is missing; run webman-aot doctor --repair');
        }
        $lockFile = $generation . '/toolchain.lock.json';
        $tools = (new PreparedToolchain())->load(
            $generation . '/prepared/prepared-toolchain.json',
            $this->layout->root(),
            $lockFile,
            $host
        );
        $archive = $this->generatorArchive($lockFile, $generation . '/artifacts');
        $cache = $this->layout->path('cache') . '/upstream-generator';
        if (is_link($cache)
            || (!is_dir($cache) && !mkdir($cache, 0700, true) && !is_dir($cache))
        ) {
            throw new ConfigurationException('private upstream generator cache is unsafe');
        }
        $result = (new ProjectBuilder())->build(
            $project,
            $archive,
            $cache,
            dirname(__DIR__, 2) . '/compatibility/locks/webman-workerman-2026-09-25.json',
            $lockFile,
            dirname(__DIR__, 2) . '/toolchain/patches/typephp/0.9.2/manifest.json',
            $tools,
            $host,
            function (string $stage): void {
                $this->logger?->event('info', 'build-stage', $stage, "build entered {$stage}");
                fwrite(STDERR, "[build] {$stage}\n");
            },
            $explicitProfile
        );
        if ($json) {
            fwrite(
                STDOUT,
                json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . PHP_EOL
            );
            return;
        }
        fwrite(
            STDOUT,
            sprintf(
                "Built %s: %s (%s)\n",
                $result['profile'],
                $result['distribution']['path'],
                $result['elfSha256']
            )
        );
    }

    private function generatorArchive(string $lockFile, string $artifacts): string
    {
        $contents = file_get_contents($lockFile);
        if (!is_string($contents)) {
            throw new ConfigurationException('active toolchain lock is missing');
        }
        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        foreach ($lock['components'] ?? [] as $component) {
            if (!is_array($component)
                || ($component['id'] ?? null) !== 'webman-typephp-generator-source'
            ) {
                continue;
            }
            $urlPath = parse_url((string) ($component['sourceUrl'] ?? ''), PHP_URL_PATH);
            $filename = is_string($urlPath) ? basename($urlPath) : '';
            $path = $artifacts . '/' . $filename;
            $actual = is_file($path) && !is_link($path)
                ? hash_file('sha256', $path)
                : false;
            if ($filename === '' || !is_string($actual)
                || !hash_equals((string) ($component['sha256'] ?? ''), $actual)
            ) {
                throw new UnavailableException('locked upstream generator archive is missing or corrupt');
            }
            return $path;
        }
        throw new ConfigurationException('toolchain lock lacks the upstream generator archive');
    }

    /**
     * @param list<string> $options
     */
    private function runDoctor(array $options): void
    {
        $json = false;
        $repair = false;
        $checkOnly = false;
        foreach ($options as $option) {
            if ($option === '--json' || $option === '--format=json') {
                $json = true;
                continue;
            }
            if ($option === '--repair') {
                $repair = true;
                continue;
            }
            if ($option === '--check') {
                $checkOnly = true;
                continue;
            }
            throw new UsageException("Unknown doctor option: {$option}");
        }
        if ($repair && $checkOnly) {
            throw new UsageException('doctor --repair and --check cannot be combined');
        }
        $report = ($this->doctorFactory)()->inspect();
        $checks = $report->toArray()['checks'];
        $repairable = false;
        $otherFailure = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'ok') {
                continue;
            }
            if (str_starts_with($check['id'], 'component:')
                || $check['id'] === 'prepared-toolchain'
            ) {
                $repairable = true;
            } elseif ($check['id'] === 'network-tcp') {
                // A TCP probe can fail behind a proxy even when curl can download.
                continue;
            } else {
                $otherFailure = true;
            }
        }
        $repair = $repair || (!$json && !$checkOnly && $repairable && !$otherFailure);
        $output = new ProgressOutput(STDERR);
        $progress = function (string $message) use ($output): void {
            $this->logger?->event('info', 'toolchain.progress', 'doctor', $message);
            $output->message('[repair] ' . $message);
        };
        $lastStep = '';
        $lastBytes = 0;
        $downloadProgress = function (string $step, int $bytes, ?int $total) use (
            $output,
            &$lastStep,
            &$lastBytes
        ): void {
            $status = $total === null
                ? sprintf('[repair] %s: %.1f MiB received', $step, $bytes / 1048576)
                : sprintf('[repair] %s: %.1f%%', $step, min(99.9, $bytes * 100 / $total));
            if ($step === $lastStep && $bytes < $lastBytes) {
                $status .= ' (retrying from start)';
            }
            $output->update($status);
            $lastStep = $step;
            $lastBytes = $bytes;
        };
        try {
            $repairResult = $repair
                ? ($this->repairFactory)()->repair($progress, $downloadProgress)
                : null;
        } finally {
            $output->finish();
        }
        if ($repair) {
            $report = ($this->doctorFactory)()->inspect();
        }
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
    private function runVerify(array $options): void
    {
        $path = null;
        $deployed = false;
        $json = false;
        foreach ($options as $option) {
            if ($option === '--deployed') {
                $deployed = true;
                continue;
            }
            if ($option === '--json' || $option === '--format=json') {
                $json = true;
                continue;
            }
            if (str_starts_with($option, '--path=') && $path === null) {
                $path = substr($option, strlen('--path='));
                if ($path === '') {
                    throw new UsageException('verify --path requires a directory');
                }
                continue;
            }
            throw new UsageException("Unknown verify option: {$option}");
        }
        if ($path === null) {
            $project = getcwd();
            if (!is_string($project) || $project === '') {
                throw new ConfigurationException('cannot resolve the current project directory');
            }
            $path = $project . '/dist-aot';
        }
        $verified = ($this->verifierFactory)()->verify(
            $path,
            strictMutable: !$deployed
        );
        $report = [
            'schema' => 'webman-aot-verify-report-v1',
            'path' => realpath($path),
            'mode' => $deployed ? 'deployed' : 'package',
            'scope' => $verified['ldd'] === 'static'
                ? 'target-static-and-integrity'
                : 'build-host-structure-and-integrity',
            'staticStructure' => 'pass',
            'targetLdd' => $verified['ldd'],
            'files' => $verified['files'],
            'compiledDirect' => $verified['compiledDirect'],
            'compiledShadow' => $verified['compiledShadow'],
        ];
        if ($json) {
            fwrite(
                STDOUT,
                json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . PHP_EOL
            );
            return;
        }
        fwrite(
            STDOUT,
            sprintf(
                "Checked %d managed files; business PHP: %d direct, %d AOT shadows; target ldd: %s\n",
                $verified['files'],
                $verified['compiledDirect'],
                $verified['compiledShadow'],
                $verified['ldd']
            )
        );
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
        $output = new ProgressOutput(STDERR);
        try {
            $result = $manager->selfUpdate(
                $parsed['manifest'],
                $parsed['trustedKeys'],
                static function (string $name, int $bytes, ?int $total) use ($output): void {
                    $output->update($total === null
                        ? sprintf('[update] %s: %.1f MiB received', $name, $bytes / 1048576)
                        : sprintf('[update] %s: %.1f%%', $name, min(99.9, $bytes * 100 / $total)));
                }
            );
        } finally {
            $output->finish();
        }
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
        $output = new ProgressOutput(STDERR);
        try {
            $result = $manager->updateToolchain(
                $parsed['manifest'],
                $parsed['trustedKeys'],
                static function (string $name, int $bytes, ?int $total) use ($output): void {
                    $output->update($total === null
                        ? sprintf('[update] %s: %.1f MiB received', $name, $bytes / 1048576)
                        : sprintf('[update] %s: %.1f%%', $name, min(99.9, $bytes * 100 / $total)));
                },
                static function (string $stage) use ($output): void {
                    $output->message('[update] ' . $stage);
                }
            );
        } finally {
            $output->finish();
        }
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
