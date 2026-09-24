<?php

declare(strict_types=1);

use WebmanAot\Doctor\Doctor;
use WebmanAot\Doctor\SystemProbe;

final class DoctorTest
{
    public function run(string $root): void
    {
        foreach (['macos-arm64', 'windows-x86_64'] as $host) {
            $this->assertHealthyFixture($root, $host);
        }
        $this->assertMissingAndCorruptFixtures($root);
        $this->assertEnvironmentFailures($root);
    }

    private function assertHealthyFixture(string $root, string $host): void
    {
        $fixture = $this->createFixture($root);
        try {
            $before = $this->snapshot($fixture);
            $report = $this->doctor($fixture, new DoctorFakeSystemProbe($host))->inspect();
            $after = $this->snapshot($fixture);
            $this->assert($report->healthy(), "healthy {$host} fixture failed doctor");
            $this->assert($before === $after, 'doctor must not modify its project or toolchain inputs');
            $checks = $this->checksById($report->toArray()['checks']);
            $this->assert(
                ($checks['project']['details']['webmanVersion'] ?? null) === 'v2.2.4',
                'doctor did not report the locked Webman version'
            );
            $this->assert(
                ($checks['project']['details']['profile'] ?? null) === 'webman',
                'doctor did not report the detected Webman profile'
            );
            $hostComponent = $host === 'macos-arm64'
                ? 'component:typephp-macos-arm64'
                : 'component:typephp-windows-x64';
            $this->assert(isset($checks[$hostComponent]), "doctor omitted host component: {$hostComponent}");
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertMissingAndCorruptFixtures(string $root): void
    {
        $fixture = $this->createFixture($root);
        try {
            unlink($fixture . '/artifacts/php-source.tar');
            $missing = $this->doctor(
                $fixture,
                new DoctorFakeSystemProbe('macos-arm64')
            )->inspect()->toArray();
            $checks = $this->checksById($missing['checks']);
            $this->assert(!$missing['healthy'], 'missing component fixture must fail doctor');
            $this->assert(
                $checks['component:php-source']['message'] === 'locked component is missing',
                'missing component diagnosis drifted'
            );

            file_put_contents($fixture . '/artifacts/php-source.tar', "php-source\n");
            file_put_contents($fixture . '/artifacts/typephp-macos.tar', "corrupted\n");
            $corrupt = $this->doctor(
                $fixture,
                new DoctorFakeSystemProbe('macos-arm64')
            )->inspect()->toArray();
            $checks = $this->checksById($corrupt['checks']);
            $this->assert(!$corrupt['healthy'], 'corrupt component fixture must fail doctor');
            $this->assert(
                $checks['component:typephp-macos-arm64']['message']
                    === 'locked component digest mismatch',
                'corrupt component diagnosis drifted'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertEnvironmentFailures(string $root): void
    {
        $fixture = $this->createFixture($root);
        try {
            $probe = new DoctorFakeSystemProbe(
                'unsupported-x86_64',
                1024,
                false
            );
            unlink($fixture . '/project/composer.lock');
            $report = $this->doctor($fixture, $probe)->inspect()->toArray();
            $checks = $this->checksById($report['checks']);
            foreach (['platform', 'disk', 'network', 'project'] as $id) {
                $this->assert(
                    ($checks[$id]['status'] ?? null) === 'error',
                    "doctor did not fail the {$id} fixture"
                );
            }
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function doctor(string $fixture, SystemProbe $probe): Doctor
    {
        return new Doctor(
            $fixture . '/toolchain.lock.json',
            $fixture . '/artifacts',
            $fixture . '/project',
            $probe,
            10 * 1024 * 1024
        );
    }

    private function createFixture(string $root): string
    {
        $fixture = sys_get_temp_dir() . '/webman-aot-doctor-' . bin2hex(random_bytes(8));
        foreach ([
            $fixture,
            $fixture . '/artifacts',
            $fixture . '/project',
            $fixture . '/project/app',
        ] as $directory) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException("unable to create doctor fixture directory: {$directory}");
            }
        }
        foreach (['composer.json', 'composer.lock', 'start.php'] as $relativePath) {
            if (!copy(
                $root . '/tests/fixtures/minimal-webman/' . $relativePath,
                $fixture . '/project/' . $relativePath
            )) {
                throw new RuntimeException("unable to copy doctor project fixture: {$relativePath}");
            }
        }

        $artifacts = [
            'php-source.tar' => "php-source\n",
            'typephp-macos.tar' => "typephp-macos\n",
            'typephp-windows.zip' => "typephp-windows\n",
        ];
        foreach ($artifacts as $filename => $contents) {
            if (file_put_contents($fixture . '/artifacts/' . $filename, $contents) === false) {
                throw new RuntimeException("unable to write doctor artifact fixture: {$filename}");
            }
        }
        $lock = [
            'schema' => 'webman-aot-toolchain-lock-v1',
            'target' => [
                'os' => 'linux',
                'architecture' => 'x86_64',
                'libc' => 'musl',
                'minimumKernel' => '3.10',
            ],
            'hosts' => ['macos-arm64', 'windows-x86_64'],
            'components' => [
                $this->component('php-source', '8.4.25', 'php-source.tar', $artifacts),
                $this->component(
                    'typephp-macos-arm64',
                    '0.9.2',
                    'typephp-macos.tar',
                    $artifacts
                ),
                $this->component(
                    'typephp-windows-x64',
                    '0.9.2',
                    'typephp-windows.zip',
                    $artifacts
                ),
            ],
            'embeddedLibraries' => [],
            'requiredExtensions' => [
                'pdo' => ['php-source'],
            ],
        ];
        $encoded = json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($fixture . '/toolchain.lock.json', $encoded . "\n") === false) {
            throw new RuntimeException('unable to write doctor lock fixture');
        }

        return $fixture;
    }

    /**
     * @param array<string, string> $artifacts
     * @return array{id:string,kind:string,version:string,sourceUrl:string,sha256:string}
     */
    private function component(
        string $id,
        string $version,
        string $filename,
        array $artifacts
    ): array {
        return [
            'id' => $id,
            'kind' => 'fixture',
            'version' => $version,
            'sourceUrl' => 'https://example.com/' . $filename,
            'sha256' => hash('sha256', $artifacts[$filename]),
        ];
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     * @return array<string, array{id:string,status:string,message:string,details:array<string,mixed>}>
     */
    private function checksById(array $checks): array
    {
        $indexed = [];
        foreach ($checks as $check) {
            $indexed[$check['id']] = $check;
        }

        return $indexed;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $directory): array
    {
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($directory) + 1);
            $digest = hash_file('sha256', $item->getPathname());
            if (!is_string($digest)) {
                throw new RuntimeException("unable to hash doctor fixture: {$relative}");
            }
            $snapshot[str_replace('\\', '/', $relative)] = $digest;
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
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

final class DoctorFakeSystemProbe implements SystemProbe
{
    public function __construct(
        private readonly string $host,
        private readonly int $freeBytes = 20 * 1024 * 1024,
        private readonly bool $networkReachable = true
    ) {
    }

    public function hostId(): string
    {
        return $this->host;
    }

    public function freeBytes(string $path): int
    {
        return $this->freeBytes;
    }

    public function canReach(string $url): bool
    {
        return $this->networkReachable;
    }
}
