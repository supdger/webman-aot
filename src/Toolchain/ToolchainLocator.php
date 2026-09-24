<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;

final class ToolchainLocator
{
    public function __construct(private readonly UserDirectoryLayout $layout)
    {
    }

    public function activeGeneration(string $host): ?string
    {
        $versions = $this->layout->path('toolchains') . '/versions';
        if (!is_dir($versions)) {
            return null;
        }
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return null;
        }
        rsort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $manifest = $this->readManifest($directory . '/manifest.json');
            if (($manifest['schema'] ?? null) !== 'webman-aot-toolchain-generation-v1'
                || ($manifest['host'] ?? null) !== $host
                || !is_dir($directory . '/artifacts')
                || !is_file($directory . '/toolchain.lock.json')
            ) {
                continue;
            }

            return $directory;
        }

        return null;
    }

    public function activeArtifacts(string $host): string
    {
        $generation = $this->activeGeneration($host);
        if ($generation !== null) {
            return $generation . '/artifacts';
        }

        return $this->layout->path('artifacts');
    }

    public function activeLock(string $host, string $fallback): string
    {
        $generation = $this->activeGeneration($host);

        return $generation === null ? $fallback : $generation . '/toolchain.lock.json';
    }

    public function rollback(string $host): string
    {
        $active = $this->activeGeneration($host);
        if ($active === null) {
            throw new UnavailableException('no active toolchain generation can be rolled back');
        }
        if (count($this->validGenerations($host)) < 2) {
            throw new UnavailableException('no previous toolchain generation is available');
        }
        $rolledBack = $this->layout->path('toolchains') . '/versions/rolled-back';
        if (!is_dir($rolledBack)
            && !mkdir($rolledBack, 0700, true)
            && !is_dir($rolledBack)
        ) {
            throw new \RuntimeException('unable to create toolchain rollback directory');
        }
        $destination = $rolledBack . '/' . basename($active) . '-' . bin2hex(random_bytes(4));
        if (!rename($active, $destination)) {
            throw new \RuntimeException('unable to roll back the active toolchain generation');
        }
        $previous = $this->activeGeneration($host);
        if ($previous === null) {
            throw new \RuntimeException('toolchain rollback did not reveal a previous generation');
        }

        return $previous;
    }

    /**
     * @return list<string>
     */
    private function validGenerations(string $host): array
    {
        $versions = $this->layout->path('toolchains') . '/versions';
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return [];
        }
        $valid = [];
        foreach ($directories as $directory) {
            if (basename($directory) === 'rolled-back') {
                continue;
            }
            $manifest = $this->readManifest($directory . '/manifest.json');
            if (($manifest['schema'] ?? null) === 'webman-aot-toolchain-generation-v1'
                && ($manifest['host'] ?? null) === $host
                && is_dir($directory . '/artifacts')
                && is_file($directory . '/toolchain.lock.json')
            ) {
                $valid[] = $directory;
            }
        }
        rsort($valid, SORT_STRING);

        return $valid;
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
}
