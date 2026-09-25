<?php

declare(strict_types=1);

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\Downloader;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainPreparer;
use WebmanAot\Toolchain\ToolchainRepairer;

final class ToolchainRepairerTest
{
    public function run(): void
    {
        $home = sys_get_temp_dir() . '/webman-aot-repair-' . bin2hex(random_bytes(8));
        $previousHome = getenv('WEBMAN_AOT_HOME');
        try {
            $fixture = $this->createFixture($home);
            putenv('WEBMAN_AOT_HOME=' . $home);
            $layout = UserDirectoryLayout::detect();
            $good = new RepairFakeDownloader([
                $fixture['macUrl'] => $fixture['macContents'],
            ]);
            $repairer = new ToolchainRepairer(
                $fixture['lockPath'],
                $layout,
                'macos-arm64',
                $good
            );
            $first = $repairer->repair();
            $this->assert($first['changed'], 'first repair must promote a generation');
            $this->assert($first['copied'] === ['php-source'], 'valid legacy component was not reused');
            $this->assert(
                $first['downloaded'] === ['typephp-macos-arm64'],
                'missing host component was not downloaded'
            );
            $locator = new ToolchainLocator($layout);
            $firstGeneration = $locator->activeGeneration('macos-arm64');
            $this->assert(is_string($firstGeneration), 'first repair did not activate a generation');

            $second = $repairer->repair();
            $this->assert(!$second['changed'], 'healthy repair must keep the current generation');
            $this->assert(
                $second['generation'] === basename($firstGeneration),
                'healthy repair switched generations'
            );
            $this->assert(count($good->downloads()) === 1, 'healthy repair downloaded components again');

            $activeMac = $firstGeneration . '/artifacts/typephp-macos.tar';
            file_put_contents($activeMac, "corrupted\n");
            $beforeFailure = $this->snapshot($firstGeneration);
            $bad = new RepairFakeDownloader([
                $fixture['macUrl'] => "wrong digest\n",
            ]);
            try {
                (new ToolchainRepairer(
                    $fixture['lockPath'],
                    $layout,
                    'macos-arm64',
                    $bad
                ))->repair();
                throw new RuntimeException('digest mismatch repair unexpectedly succeeded');
            } catch (UnavailableException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'digest mismatch'),
                    'digest mismatch failure was not explicit'
                );
            }
            $this->assert(
                $locator->activeGeneration('macos-arm64') === $firstGeneration,
                'failed repair replaced the current generation'
            );
            $this->assert(
                $this->snapshot($firstGeneration) === $beforeFailure,
                'failed repair modified the current generation'
            );
            $candidates = glob($home . '/toolchains/candidates/*');
            $this->assert(is_array($candidates) && $candidates === [], 'failed candidate was not removed');

            $replacementDownloader = new RepairFakeDownloader([
                $fixture['macUrl'] => $fixture['macContents'],
            ]);
            $replacement = (new ToolchainRepairer(
                $fixture['lockPath'],
                $layout,
                'macos-arm64',
                $replacementDownloader
            ))->repair();
            $this->assert($replacement['changed'], 'corrupt current generation was not replaced');
            $this->assert(
                $replacement['downloaded'] === ['typephp-macos-arm64'],
                'repair did not limit download to the corrupt component'
            );
            $this->assert(
                $replacement['copied'] === ['php-source'],
                'repair did not reuse the valid component'
            );
            $secondGeneration = $locator->activeGeneration('macos-arm64');
            $this->assert(
                is_string($secondGeneration) && $secondGeneration !== $firstGeneration,
                'successful repair did not atomically activate a new generation'
            );
            $this->assert(
                hash_file('sha256', $secondGeneration . '/artifacts/typephp-macos.tar')
                    === hash('sha256', $fixture['macContents']),
                'promoted generation failed its digest contract'
            );
            $preparer = new RepairFakePreparer();
            $preparedRepairer = new ToolchainRepairer(
                $fixture['lockPath'],
                $layout,
                'macos-arm64',
                $replacementDownloader,
                $preparer
            );
            $prepared = $preparedRepairer->repair();
            $this->assert($prepared['changed'], 'archive-only generation was mistaken for build-ready');
            $preparedGeneration = $locator->activeGeneration('macos-arm64');
            $this->assert(is_string($preparedGeneration), 'prepared generation was not promoted');
            $preparer->assertReady($preparedGeneration);
            $this->assert(!$preparedRepairer->repair()['changed'], 'ready generation was needlessly replaced');

            file_put_contents($fixture['lockPath'], " \n", FILE_APPEND);
            $beforePreparationFailure = $this->snapshot($preparedGeneration);
            $preparer->fail = true;
            try {
                $preparedRepairer->repair();
                throw new RuntimeException('failed tool preparation unexpectedly promoted a generation');
            } catch (RuntimeException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'injected preparation failure'),
                    'tool preparation failure was not propagated'
                );
            }
            $this->assert(
                $locator->activeGeneration('macos-arm64') === $preparedGeneration
                && $this->snapshot($preparedGeneration) === $beforePreparationFailure,
                'failed preparation replaced or modified the current generation'
            );
            $candidates = glob($home . '/toolchains/candidates/*');
            $this->assert(is_array($candidates) && $candidates === [], 'failed preparation candidate survived');
        } finally {
            if (is_string($previousHome)) {
                putenv('WEBMAN_AOT_HOME=' . $previousHome);
            } else {
                putenv('WEBMAN_AOT_HOME');
            }
            $this->removeDirectory($home);
        }
    }

    /**
     * @return array{lockPath:string,macUrl:string,macContents:string}
     */
    private function createFixture(string $home): array
    {
        $artifacts = $home . '/artifacts';
        if (!mkdir($artifacts, 0700, true) && !is_dir($artifacts)) {
            throw new RuntimeException('unable to create repair fixture');
        }
        $commonContents = "php-source\n";
        $macContents = "typephp-macos\n";
        $windowsContents = "typephp-windows\n";
        file_put_contents($artifacts . '/php-source.tar', $commonContents);
        $macUrl = 'https://example.com/typephp-macos.tar';
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
                $this->component(
                    'php-source',
                    '8.4.25',
                    'https://example.com/php-source.tar',
                    $commonContents
                ),
                $this->component('typephp-macos-arm64', '0.9.2', $macUrl, $macContents),
                $this->component(
                    'typephp-windows-x64',
                    '0.9.2',
                    'https://example.com/typephp-windows.zip',
                    $windowsContents
                ),
            ],
            'embeddedLibraries' => [],
            'requiredExtensions' => [
                'pdo' => ['php-source'],
            ],
        ];
        $lockPath = $home . '/toolchain.lock.json';
        file_put_contents(
            $lockPath,
            json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"
        );

        return [
            'lockPath' => $lockPath,
            'macUrl' => $macUrl,
            'macContents' => $macContents,
        ];
    }

    /**
     * @return array{id:string,kind:string,version:string,sourceUrl:string,sha256:string}
     */
    private function component(
        string $id,
        string $version,
        string $url,
        string $contents
    ): array {
        return [
            'id' => $id,
            'kind' => 'fixture',
            'version' => $version,
            'sourceUrl' => $url,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $directory): array
    {
        $snapshot = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace(
                '\\',
                '/',
                substr($item->getPathname(), strlen($directory) + 1)
            );
            $digest = hash_file('sha256', $item->getPathname());
            if (!is_string($digest)) {
                throw new RuntimeException("unable to hash repair fixture: {$relative}");
            }
            $snapshot[$relative] = $digest;
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

final class RepairFakeDownloader implements Downloader
{
    /** @var list<string> */
    private array $downloads = [];

    /**
     * @param array<string, string> $responses
     */
    public function __construct(private readonly array $responses)
    {
    }

    public function download(string $url, string $destination): void
    {
        $this->downloads[] = $url;
        if (!array_key_exists($url, $this->responses)) {
            throw new RuntimeException("unexpected repair download: {$url}");
        }
        if (file_put_contents($destination, $this->responses[$url]) === false) {
            throw new RuntimeException('unable to write repair download fixture');
        }
    }

    /**
     * @return list<string>
     */
    public function downloads(): array
    {
        return $this->downloads;
    }
}

final class RepairFakePreparer implements ToolchainPreparer
{
    public bool $fail = false;

    public function prepare(string $candidate): void
    {
        if ($this->fail) {
            throw new RuntimeException('injected preparation failure');
        }
        mkdir($candidate . '/prepared', 0700);
        file_put_contents($candidate . '/prepared/ready', 'prepared');
    }

    public function assertReady(string $generation): void
    {
        if (!is_file($generation . '/prepared/ready')) {
            throw new RuntimeException('prepared toolchain is missing');
        }
    }
}
