<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;

final class ToolchainRepairer
{
    public function __construct(
        private readonly string $lockPath,
        private readonly UserDirectoryLayout $layout,
        private readonly string $host,
        private readonly Downloader $downloader
    ) {
    }

    /**
     * @return array{
     *     generation:string,
     *     copied:list<string>,
     *     downloaded:list<string>,
     *     lockSha256:string,
     *     changed:bool
     * }
     */
    public function repair(): array
    {
        $toolchains = $this->layout->path('toolchains');
        $candidates = $toolchains . '/candidates';
        $versions = $toolchains . '/versions';
        $this->createDirectory($candidates);
        $this->createDirectory($versions);
        $lockHandle = fopen($toolchains . '/repair.lock', 'c+');
        if (!is_resource($lockHandle)) {
            throw new ConfigurationException('unable to open toolchain repair lock');
        }
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new ConfigurationException('unable to acquire toolchain repair lock');
        }

        $candidate = $candidates . '/candidate-' . bin2hex(random_bytes(8));
        try {
            $result = $this->buildAndPromote($candidate, $versions);
        } catch (\Throwable $throwable) {
            $this->removeDirectory($candidate);
            throw $throwable;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $result;
    }

    /**
     * @return array{
     *     generation:string,
     *     copied:list<string>,
     *     downloaded:list<string>,
     *     lockSha256:string,
     *     changed:bool
     * }
     */
    private function buildAndPromote(string $candidate, string $versions): array
    {
        $lockContents = file_get_contents($this->lockPath);
        if (!is_string($lockContents)) {
            throw new ConfigurationException('toolchain lock is missing');
        }
        $lock = json_decode($lockContents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($lock)) {
            throw new ConfigurationException('toolchain lock root is invalid');
        }
        $errors = (new LockValidator())->validate($lock);
        if ($errors !== []) {
            throw new ConfigurationException('toolchain lock validation failed: ' . implode('; ', $errors));
        }
        $components = (new HostComponentSelector())->select(
            is_array($lock['components'] ?? null) ? $lock['components'] : [],
            $this->host
        );
        if ($components === []) {
            throw new ConfigurationException("toolchain lock has no components for {$this->host}");
        }

        $artifacts = $candidate . '/artifacts';
        $this->createDirectory($artifacts);
        $locator = new ToolchainLocator($this->layout);
        $activeGeneration = $locator->activeGeneration($this->host);
        $active = $locator->activeArtifacts($this->host);
        $lockSha256 = hash('sha256', $lockContents);
        $activeManifest = $activeGeneration === null
            ? []
            : $this->readManifest($activeGeneration . '/manifest.json');
        if ($activeGeneration !== null
            && ($activeManifest['lockSha256'] ?? null) === $lockSha256
            && $this->allComponentsMatch($components, $active)
        ) {
            $this->removeDirectory($candidate);

            return [
                'generation' => basename($activeGeneration),
                'copied' => [],
                'downloaded' => [],
                'lockSha256' => $lockSha256,
                'changed' => false,
            ];
        }
        $copied = [];
        $downloaded = [];
        $manifestComponents = [];
        $filenames = [];
        foreach ($components as $component) {
            $id = (string) $component['id'];
            $filename = $this->artifactFilename($component);
            if (isset($filenames[$filename])) {
                throw new ConfigurationException("toolchain artifact filename collision: {$filename}");
            }
            $filenames[$filename] = true;
            $expected = (string) $component['sha256'];
            $source = $active . '/' . $filename;
            $target = $artifacts . '/' . $filename;
            if ($this->matchesDigest($source, $expected)) {
                if (!copy($source, $target)) {
                    throw new \RuntimeException("unable to copy verified component: {$id}");
                }
                $copied[] = $id;
            } else {
                $partial = $target . '.partial';
                $this->downloader->download((string) $component['sourceUrl'], $partial);
                if (!$this->matchesDigest($partial, $expected)) {
                    throw new UnavailableException("downloaded component digest mismatch: {$id}");
                }
                if (!rename($partial, $target)) {
                    throw new \RuntimeException("unable to promote verified component: {$id}");
                }
                $downloaded[] = $id;
            }
            if (!$this->matchesDigest($target, $expected)) {
                throw new \RuntimeException("candidate component self-check failed: {$id}");
            }
            $manifestComponents[] = [
                'id' => $id,
                'version' => (string) $component['version'],
                'artifact' => $filename,
                'sha256' => $expected,
            ];
        }

        $generationName = $this->nextGenerationName($versions, $lockSha256);
        $manifest = [
            'schema' => 'webman-aot-toolchain-generation-v1',
            'generation' => $generationName,
            'host' => $this->host,
            'lockSha256' => $lockSha256,
            'components' => $manifestComponents,
        ];
        $encoded = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (file_put_contents($candidate . '/manifest.json', $encoded . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('unable to write candidate toolchain manifest');
        }
        if (!copy($this->lockPath, $candidate . '/toolchain.lock.json')
            || hash_file('sha256', $candidate . '/toolchain.lock.json') !== $lockSha256
        ) {
            throw new \RuntimeException('unable to preserve verified toolchain lock');
        }
        $destination = $versions . '/' . $generationName;
        if (!rename($candidate, $destination)) {
            throw new \RuntimeException('unable to atomically promote candidate toolchain');
        }

        return [
            'generation' => $generationName,
            'copied' => $copied,
            'downloaded' => $downloaded,
            'lockSha256' => $lockSha256,
            'changed' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    private function allComponentsMatch(array $components, string $artifacts): bool
    {
        foreach ($components as $component) {
            $filename = $this->artifactFilename($component);
            if (!$this->matchesDigest(
                $artifacts . '/' . $filename,
                (string) ($component['sha256'] ?? '')
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $component
     */
    private function artifactFilename(array $component): string
    {
        $path = parse_url((string) ($component['sourceUrl'] ?? ''), PHP_URL_PATH);
        $filename = is_string($path) ? basename($path) : '';
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new ConfigurationException(
                'locked component has no artifact filename: ' . (string) ($component['id'] ?? '')
            );
        }

        return $filename;
    }

    private function matchesDigest(string $path, string $expected): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $actual = hash_file('sha256', $path);

        return is_string($actual) && hash_equals($expected, $actual);
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $path): array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($manifest) ? $manifest : [];
    }

    private function nextGenerationName(string $versions, string $lockSha256): string
    {
        $maximum = 0;
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (is_array($directories)) {
            foreach ($directories as $directory) {
                $name = basename($directory);
                if (preg_match('/^([0-9]{20})-/', $name, $matches) === 1) {
                    $maximum = max($maximum, (int) $matches[1]);
                }
            }
        }

        return sprintf('%020d-%s', $maximum + 1, substr($lockSha256, 0, 12));
    }

    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException("unable to create toolchain directory: {$directory}");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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
}
