<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;

final class CliVersionStore
{
    public function __construct(private readonly UserDirectoryLayout $layout)
    {
    }

    public function activeGeneration(): ?string
    {
        $versions = $this->layout->path('versions');
        if (!is_dir($versions)) {
            return null;
        }
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return null;
        }
        rsort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $manifest = $this->manifest($directory);
            if (($manifest['schema'] ?? null) !== 'webman-aot-cli-generation-v1'
                || !is_file($directory . '/app/bin/webman-aot.php')
                || !is_file($directory . '/app/src/Version.php')
            ) {
                continue;
            }

            return $directory;
        }

        return null;
    }

    public function activeEntry(): ?string
    {
        $generation = $this->activeGeneration();

        return $generation === null ? null : $generation . '/app/bin/webman-aot.php';
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function promote(string $candidate, string $version, array $metadata): string
    {
        foreach (['app/bin/webman-aot.php', 'app/src/Version.php'] as $relativePath) {
            if (!is_file($candidate . '/' . $relativePath)) {
                throw new UnavailableException("CLI candidate is incomplete: {$relativePath}");
            }
        }
        $versions = $this->layout->path('versions');
        $this->createDirectory($versions);
        $name = $this->nextGenerationName($versions, $version);
        $manifest = [
            'schema' => 'webman-aot-cli-generation-v1',
            'generation' => $name,
            'version' => $version,
            'metadata' => $metadata,
        ];
        $encoded = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (file_put_contents($candidate . '/manifest.json', $encoded . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('unable to write CLI generation manifest');
        }
        $destination = $versions . '/' . $name;
        if (!rename($candidate, $destination)) {
            throw new \RuntimeException('unable to atomically promote CLI generation');
        }

        return $destination;
    }

    public function rollback(): string
    {
        $versions = $this->layout->path('versions');
        $active = $this->activeGeneration();
        if ($active === null) {
            throw new UnavailableException('no active CLI generation can be rolled back');
        }
        $all = $this->validGenerations();
        if (count($all) < 2) {
            throw new UnavailableException('no previous CLI generation is available');
        }
        $rolledBack = $versions . '/rolled-back';
        $this->createDirectory($rolledBack);
        $destination = $rolledBack . '/' . basename($active) . '-' . bin2hex(random_bytes(4));
        if (!rename($active, $destination)) {
            throw new \RuntimeException('unable to roll back the active CLI generation');
        }
        $previous = $this->activeGeneration();
        if ($previous === null) {
            throw new \RuntimeException('CLI rollback did not reveal a previous generation');
        }

        return $previous;
    }

    /**
     * @return list<string>
     */
    private function validGenerations(): array
    {
        $versions = $this->layout->path('versions');
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return [];
        }
        $valid = [];
        foreach ($directories as $directory) {
            if (basename($directory) === 'rolled-back') {
                continue;
            }
            $manifest = $this->manifest($directory);
            if (($manifest['schema'] ?? null) === 'webman-aot-cli-generation-v1'
                && is_file($directory . '/app/bin/webman-aot.php')
                && is_file($directory . '/app/src/Version.php')
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
    private function manifest(string $directory): array
    {
        $contents = @file_get_contents($directory . '/manifest.json');
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

    private function nextGenerationName(string $versions, string $version): string
    {
        $maximum = 0;
        $directories = glob($versions . '/*', GLOB_ONLYDIR);
        if (is_array($directories)) {
            foreach ($directories as $directory) {
                if (preg_match('/^([0-9]{20})-/', basename($directory), $matches) === 1) {
                    $maximum = max($maximum, (int) $matches[1]);
                }
            }
        }
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);
        if (!is_string($slug) || $slug === '') {
            $slug = 'unknown';
        }

        return sprintf('%020d-%s', $maximum + 1, $slug);
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException("unable to create CLI version directory: {$directory}");
        }
    }
}
