<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

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
