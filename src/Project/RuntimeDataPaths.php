<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class RuntimeDataPaths
{
    private const SOURCE_EXCLUDED_DIRECTORIES = [
        'public/storage',
        'plugin/saiadmin/public/export',
    ];

    public static function isSourceExcluded(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        foreach (self::SOURCE_EXCLUDED_DIRECTORIES as $directory) {
            if ($path === $directory || str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function assertNoExcludedPhp(string $projectDirectory): void
    {
        $root = rtrim($projectDirectory, '/\\');
        foreach (self::SOURCE_EXCLUDED_DIRECTORIES as $directory) {
            $absolute = $root . '/' . $directory;
            if (is_link($absolute)) {
                throw new ConfigurationException(
                    "writable runtime directory is a symlink: {$directory}"
                );
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $relative = $directory . substr(
                    str_replace('\\', '/', $file->getPathname()),
                    strlen(str_replace('\\', '/', $absolute))
                );
                if ($file->isLink()) {
                    throw new ConfigurationException(
                        "writable runtime directory contains symlink: {$relative}"
                    );
                }
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    throw new ConfigurationException(
                        "PHP in writable runtime directory cannot be silently excluded: {$relative}"
                    );
                }
            }
        }
    }

    /**
     * @return list<array{path:string,role:string}>
     */
    public static function writableDirectories(string $projectDirectory): array
    {
        $directories = [
            ['path' => 'public/storage', 'role' => 'uploads'],
            ['path' => 'runtime/logs', 'role' => 'logs'],
        ];
        if (is_dir(rtrim($projectDirectory, '/\\') . '/plugin/saiadmin')) {
            $directories[] = [
                'path' => 'plugin/saiadmin/public/export',
                'role' => 'uploads',
            ];
        }

        return $directories;
    }
}
