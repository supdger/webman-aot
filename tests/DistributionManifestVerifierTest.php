<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\CoverageLedger;
use WebmanAot\Project\DistributionManifestWriter;
use WebmanAot\Project\DistributionVerifier;
use WebmanAot\Project\ProjectDiscovery;
use WebmanAot\Project\RuntimeLauncherWriter;
use WebmanAot\Project\RuntimeResourceManifest;

final class DistributionManifestVerifierTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-manifest-' . bin2hex(random_bytes(8));
        $cliHome = sys_get_temp_dir() . '/webman-aot-verify-home-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0700, true);
        mkdir($root . '/runtime/logs', 0700, true);
        file_put_contents($root . '/server', $this->staticElf());
        file_put_contents($root . '/config/app.php', "<?php return [];\n");
        chmod($root . '/server', 0755);
        chmod($root . '/config/app.php', 0644);
        $resources = new RuntimeResourceManifest([
            [
                'path' => 'config/app.php',
                'role' => 'configuration',
                'kind' => 'file',
                'sourceSha256' => (string) hash_file('sha256', $root . '/config/app.php'),
                'mutable' => true,
            ],
            [
                'path' => '.env',
                'role' => 'environment',
                'kind' => 'external-file',
                'sourceSha256' => null,
                'mutable' => true,
            ],
            [
                'path' => 'runtime/logs',
                'role' => 'runtime-output',
                'kind' => 'writable-directory',
                'sourceSha256' => null,
                'mutable' => true,
            ],
        ]);
        $coverage = new CoverageLedger([[
            'path' => 'app/controller/Index.php',
            'category' => ProjectDiscovery::BUSINESS_PHP,
            'status' => CoverageLedger::COMPILED_DIRECT,
            'sourceSha256' => str_repeat('a', 64),
            'replacement' => null,
            'replacementSha256' => null,
        ]]);
        try {
            (new RuntimeLauncherWriter())->write($root);
            (new DistributionManifestWriter())->write($root, $coverage, $resources, [
                'sourceTreeSha256' => str_repeat('a', 64),
                'composerLockSha256' => str_repeat('b', 64),
                'profile' => 'webman',
                'compatibilityLockSha256' => str_repeat('c', 64),
                'toolchainLockSha256' => str_repeat('d', 64),
                'normalizedInputSha256' => str_repeat('e', 64),
                'extensions' => ['pdo', 'pcntl'],
                'arguments' => ['--full-static'],
            ], 'macos-arm64');
            $verifier = new DistributionVerifier(static fn (string $path): string => 'static');
            $result = $verifier->verify($root);
            $this->assert($result['files'] === 6, 'distribution file count is wrong');
            $this->assert($result['compiledDirect'] === 1, 'business coverage was lost');
            if (PHP_OS_FAMILY !== 'Linux') {
                $cli = $this->runCliVerify($root, $cliHome, false);
                $this->assert(
                    $cli['exitCode'] === 0
                    && ($cli['report']['staticStructure'] ?? null) === 'pass'
                    && ($cli['report']['scope'] ?? null)
                        === 'build-host-structure-and-integrity'
                    && ($cli['report']['targetLdd'] ?? null) === 'not-run-on-build-host',
                    'independent CLI verify did not report the build-host boundary'
                );
            }

            file_put_contents($root . '/config/app.php', "<?php return ['debug' => true];\n");
            $this->fails($verifier, $root, true, 'digest mismatch');
            $verifier->verify($root, strictMutable: false);
            if (PHP_OS_FAMILY !== 'Linux') {
                $this->assert(
                    $this->runCliVerify($root, $cliHome, false)['exitCode'] !== 0
                    && $this->runCliVerify($root, $cliHome, true)['exitCode'] === 0,
                    'CLI verify did not distinguish package and deployed resources'
                );
            }
            file_put_contents($root . '/.env', "APP_DEBUG=false\n");
            file_put_contents($root . '/runtime/logs/app.log', "started\n");
            $verifier->verify($root, strictMutable: false);

            file_put_contents($root . '/app.php', "<?php echo 'leak';\n");
            $this->fails($verifier, $root, false, 'unmanaged files');
            unlink($root . '/app.php');

            file_put_contents($root . '/server', 'tampered');
            $this->fails($verifier, $root, false, 'digest mismatch');
            file_put_contents($root . '/server', $this->staticElf());
            chmod($root . '/server', 0755);

            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($root . '/server', 0644);
                $this->fails($verifier, $root, false, 'mode mismatch');
                chmod($root . '/server', 0755);
            }

            $manifestPath = $root . '/manifest.json';
            $originalManifest = (string) file_get_contents($manifestPath);
            $manifest = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
            $manifest['inputSha256'] = str_repeat('0', 64);
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->fails($verifier, $root, false, 'input digest drifted');
            file_put_contents($manifestPath, $originalManifest);

            $dynamicElf = substr_replace($this->staticElf(), pack('V', 3), 64, 4);
            file_put_contents($root . '/server', $dynamicElf);
            $manifest = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
            $manifest['files']['server']['sha256'] = hash('sha256', $dynamicElf);
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->fails($verifier, $root, false, 'PT_INTERP');
            if (PHP_OS_FAMILY !== 'Linux') {
                $this->assert(
                    $this->runCliVerify($root, $cliHome, true)['exitCode'] !== 0,
                    'CLI verify accepted an interpreter-bearing ELF with a matching manifest digest'
                );
            }
            file_put_contents($root . '/server', $this->staticElf());
            file_put_contents($manifestPath, $originalManifest);

            unlink($root . '/config/app.php');
            $this->fails($verifier, $root, false, 'missing managed files');
            if (PHP_OS_FAMILY !== 'Linux') {
                $this->assert(
                    $this->runCliVerify($root, $cliHome, true)['exitCode'] !== 0,
                    'CLI verify accepted a missing managed resource'
                );
            }
            echo "[PASS] distribution manifest verifies static ELF, coverage, resources and mutable deployment boundaries\n";
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($root);
            if (is_dir($cliHome)) {
                $logs = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cliHome, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($logs as $entry) {
                    $entry->isDir() && !$entry->isLink()
                        ? rmdir($entry->getPathname())
                        : unlink($entry->getPathname());
                }
                rmdir($cliHome);
            }
        }
    }

    /** @return array{exitCode:int,report:array<string,mixed>} */
    private function runCliVerify(string $root, string $home, bool $deployed): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['WEBMAN_AOT_HOME'] = $home;
        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/webman-aot.php',
            'verify',
            '--path=' . $root,
            '--json',
        ];
        if ($deployed) {
            $command[] = '--deployed';
        }
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('unable to start independent CLI verify');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $report = $exitCode === 0
            ? json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)
            : [];
        return [
            'exitCode' => $exitCode,
            'report' => is_array($report) ? $report : [],
        ];
    }

    private function fails(
        DistributionVerifier $verifier,
        string $root,
        bool $strictMutable,
        string $expected
    ): void {
        try {
            $verifier->verify($root, strictMutable: $strictMutable);
        } catch (Throwable $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $expected),
                "unexpected verifier failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("distribution verifier accepted {$expected}");
    }

    private function staticElf(): string
    {
        $header = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9);
        $header .= pack('v', 2) . pack('v', 62) . pack('V', 1);
        $header .= pack('V2', 0, 0) . pack('V2', 64, 0) . pack('V2', 0, 0);
        $header .= pack('V', 0);
        $header .= pack('v', 64) . pack('v', 56) . pack('v', 1);
        $header .= pack('v', 0) . pack('v', 0) . pack('v', 0);
        return $header . pack('V', 1) . pack('V', 0) . str_repeat("\0", 48);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
