<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;

final class SelfUpdater
{
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        private readonly string $currentApp,
        private readonly string $currentVersion,
        private readonly VerifiedDownloader $downloader,
        private readonly PackageExtractor $extractor,
        private readonly CliSelfChecker $selfChecker
    ) {
    }

    /**
     * @param array{version:string,url:string,sha256:string} $target
     * @return array{previous:string,new:string,version:string}
     */
    public function update(array $target, string $payloadSha256, string $verifiedKeyId): array
    {
        $store = new CliVersionStore($this->layout);
        $this->ensureBaseline($store);
        $previous = $store->activeGeneration();
        if ($previous === null) {
            throw new \RuntimeException('CLI baseline generation is unavailable');
        }

        $candidateRoot = $this->candidateRoot();
        $payload = $candidateRoot . '/payload';
        $archive = $candidateRoot . '/package.zip';
        $this->createDirectory($candidateRoot);
        try {
            $this->downloader->fetch($target['url'], $target['sha256'], $archive);
            $this->createDirectory($payload);
            $this->extractor->extract($archive, $payload);
            if (!$this->selfChecker->check($payload . '/app', $target['version'])) {
                throw new UnavailableException('CLI update candidate self-check failed');
            }
            unlink($archive);
            $new = $store->promote($payload, $target['version'], [
                'archiveSha256' => $target['sha256'],
                'manifestPayloadSha256' => $payloadSha256,
                'verifiedKeyId' => $verifiedKeyId,
            ]);
            $this->removeDirectory($candidateRoot);

            return [
                'previous' => basename($previous),
                'new' => basename($new),
                'version' => $target['version'],
            ];
        } catch (\Throwable $throwable) {
            $this->removeDirectory($candidateRoot);
            throw $throwable;
        }
    }

    private function ensureBaseline(CliVersionStore $store): void
    {
        if ($store->activeGeneration() !== null) {
            return;
        }
        $candidate = $this->candidateRoot() . '/baseline';
        $this->createDirectory($candidate);
        try {
            $this->copyDirectory($this->currentApp, $candidate . '/app');
            if (!$this->selfChecker->check($candidate . '/app', $this->currentVersion)) {
                throw new UnavailableException('current CLI baseline self-check failed');
            }
            $store->promote($candidate, $this->currentVersion, [
                'baseline' => true,
            ]);
        } catch (\Throwable $throwable) {
            $this->removeDirectory(dirname($candidate));
            throw $throwable;
        }
        $this->removeDirectory(dirname($candidate));
    }

    private function candidateRoot(): string
    {
        return $this->layout->path('versions')
            . '/.candidates/candidate-'
            . bin2hex(random_bytes(8));
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->createDirectory($destination);
        $items = new \DirectoryIterator($source);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }
            $target = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("unable to copy CLI baseline file: {$target}");
            }
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException("unable to create CLI candidate directory: {$directory}");
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
