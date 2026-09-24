<?php

declare(strict_types=1);

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\Downloader;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainRepairer;
use WebmanAot\Update\CliSelfChecker;
use WebmanAot\Update\PackageExtractor;
use WebmanAot\Update\UpdateManager;

final class ToolchainUpdateTest
{
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEA/MyIfrNnkH7OZzAgck25E5nQjU+WvlhMcmqmrGNGVEc=
-----END PUBLIC KEY-----
PEM;

    private const SIGNATURE =
        'Ido1Q8BRfVbP13lIBpMPjtP7B7XYW940R5ykefLi9IbVexvO56vLrs08QZKkK9lrwXJhpSxzc6/HE9+X/qduCw==';

    public function run(string $root): void
    {
        $home = sys_get_temp_dir() . '/webman-aot-toolchain-update-' . bin2hex(random_bytes(8));
        $previousHome = getenv('WEBMAN_AOT_HOME');
        try {
            putenv('WEBMAN_AOT_HOME=' . $home);
            $layout = UserDirectoryLayout::detect();
            $baselineComponent = "toolchain-v1\n";
            $baselineLock = $this->lock('1', 'php-source-v1.tar', $baselineComponent);
            $baselinePath = $home . '/baseline.lock.json';
            $this->writeJson($baselinePath, $baselineLock);
            (new ToolchainRepairer(
                $baselinePath,
                $layout,
                'macos-arm64',
                new ToolchainUpdateDownloader([
                    'https://example.com/php-source-v1.tar' => $baselineComponent,
                ])
            ))->repair();
            $locator = new ToolchainLocator($layout);
            $baseline = $locator->activeGeneration('macos-arm64');
            $this->assert(is_string($baseline), 'toolchain baseline was not activated');

            $component = "toolchain-v2\n";
            $lock = $this->lock('2', 'php-source-v2.tar', $component);
            $lockContents = json_encode(
                $lock,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n";
            $this->assert(
                hash('sha256', $lockContents)
                    === '358112d65d4b5fd4476b96b55a733e7a301de34c0d7b8f9c6332d65646214294',
                'signed toolchain lock fixture drifted'
            );
            $manifestUrl = 'https://example.com/update-manifest.json';
            $lockUrl = 'https://example.com/toolchain-v2.lock.json';
            $manifest = $this->manifest($lockContents, $lockUrl);
            $keysPath = $home . '/trusted-keys.json';
            $this->writeJson($keysPath, [
                'schema' => 'webman-aot-trusted-update-keys-v1',
                'keys' => [[
                    'id' => 'toolchain-fixture',
                    'algorithm' => 'ed25519',
                    'publicKeyPem' => self::PUBLIC_KEY,
                ]],
            ]);

            $badManager = $this->manager($layout, $root, [
                $manifestUrl => $manifest,
                $lockUrl => $lockContents,
                'https://example.com/php-source-v2.tar' => "bad component\n",
            ]);
            try {
                $badManager->updateToolchain($manifestUrl, $keysPath);
                throw new RuntimeException('failed toolchain self-check unexpectedly activated');
            } catch (UnavailableException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'digest mismatch'),
                    'failed toolchain self-check was not explicit'
                );
            }
            $this->assert(
                $locator->activeGeneration('macos-arm64') === $baseline,
                'failed toolchain update changed the active generation'
            );

            $manager = $this->manager($layout, $root, [
                $manifestUrl => $manifest,
                $lockUrl => $lockContents,
                'https://example.com/php-source-v2.tar' => $component,
            ]);
            $result = $manager->updateToolchain($manifestUrl, $keysPath);
            $updated = $locator->activeGeneration('macos-arm64');
            $this->assert(
                is_string($updated) && $updated !== $baseline,
                'verified toolchain update was not activated'
            );
            $this->assert($result['version'] === '2', 'toolchain update version drifted');
            $this->assert(
                hash_file('sha256', $updated . '/toolchain.lock.json')
                    === hash('sha256', $lockContents),
                'active toolchain did not preserve its verified lock'
            );
            $this->assert(
                $locator->activeLock('macos-arm64', '/fallback') === $updated . '/toolchain.lock.json',
                'doctor lock selection did not follow the active toolchain'
            );

            $rolledBack = $manager->rollbackToolchain();
            $this->assert($rolledBack === $baseline, 'toolchain rollback did not reveal the baseline');
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
     * @param array<string, string> $responses
     */
    private function manager(
        UserDirectoryLayout $layout,
        string $root,
        array $responses
    ): UpdateManager {
        return new UpdateManager(
            $layout,
            $root,
            '0.1.0-dev',
            'macos-arm64',
            new ToolchainUpdateDownloader($responses),
            new ToolchainUpdateUnusedExtractor(),
            new ToolchainUpdateUnusedChecker()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lock(string $version, string $filename, string $contents): array
    {
        return [
            'schema' => 'webman-aot-toolchain-lock-v1',
            'target' => [
                'os' => 'linux',
                'architecture' => 'x86_64',
                'libc' => 'musl',
                'minimumKernel' => '3.10',
            ],
            'hosts' => ['macos-arm64', 'windows-x86_64'],
            'components' => [[
                'id' => 'php-source',
                'kind' => 'fixture',
                'version' => $version,
                'sourceUrl' => 'https://example.com/' . $filename,
                'sha256' => hash('sha256', $contents),
            ]],
            'embeddedLibraries' => [],
            'requiredExtensions' => [
                'pdo' => ['php-source'],
            ],
        ];
    }

    private function manifest(string $lockContents, string $lockUrl): string
    {
        return json_encode([
            'schema' => 'webman-aot-update-manifest-v1',
            'payload' => [
                'channel' => 'stable',
                'targets' => [
                    'cli' => [
                        'version' => '0.2.0',
                        'url' => 'https://example.com/webman-aot.zip',
                        'sha256' => hash('sha256', "cli archive\n"),
                    ],
                    'toolchain' => [
                        'version' => '2',
                        'url' => $lockUrl,
                        'sha256' => hash('sha256', $lockContents),
                    ],
                ],
            ],
            'signatures' => [[
                'keyId' => 'toolchain-fixture',
                'algorithm' => 'ed25519',
                'signature' => self::SIGNATURE,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeJson(string $path, array $document): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException("unable to create fixture directory: {$directory}");
        }
        file_put_contents(
            $path,
            json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                . "\n"
        );
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

final class ToolchainUpdateDownloader implements Downloader
{
    /**
     * @param array<string, string> $responses
     */
    public function __construct(private readonly array $responses)
    {
    }

    public function download(string $url, string $destination): void
    {
        if (!array_key_exists($url, $this->responses)) {
            throw new RuntimeException("unexpected toolchain update download: {$url}");
        }
        file_put_contents($destination, $this->responses[$url]);
    }
}

final class ToolchainUpdateUnusedExtractor implements PackageExtractor
{
    public function extract(string $archive, string $destination): void
    {
        throw new LogicException('CLI extractor must not run during toolchain update');
    }
}

final class ToolchainUpdateUnusedChecker implements CliSelfChecker
{
    public function check(string $appRoot, string $expectedVersion): bool
    {
        throw new LogicException('CLI checker must not run during toolchain update');
    }
}
