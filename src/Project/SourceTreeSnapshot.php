<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class SourceTreeSnapshot
{
    private const EXCLUDED_ROOTS = [
        '.git',
        '.webman-aot',
        'dist-aot',
        'runtime',
        'node_modules',
    ];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    /**
     * @return array{sha256:string,files:int}
     */
    public function capture(): array
    {
        RuntimeDataPaths::assertNoExcludedPhp($this->projectDirectory);
        $files = [];
        $directory = new \RecursiveDirectoryIterator(
            $this->projectDirectory,
            \FilesystemIterator::SKIP_DOTS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $entry): bool {
                $relative = $this->relative($entry->getPathname());
                $root = explode('/', $relative, 2)[0];
                if (in_array($root, self::EXCLUDED_ROOTS, true)
                    || RuntimeDataPaths::isSourceExcluded($relative)
                    || preg_match('/^\.env(?:\..+)?$/D', $entry->getFilename()) === 1
                ) {
                    return false;
                }
                if ($entry->isLink()) {
                    throw new ConfigurationException(
                        "source snapshot refuses symlink: {$relative}"
                    );
                }

                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $relative = $this->relative($entry->getPathname());
            $digest = hash_file('sha256', $entry->getPathname());
            if (!is_string($digest)) {
                throw new ConfigurationException("unable to hash project source: {$relative}");
            }
            $files[$relative] = $digest;
        }
        ksort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $path => $digest) {
            hash_update($context, $path . "\0" . $digest . "\n");
        }

        return [
            'sha256' => hash_final($context),
            'files' => count($files),
        ];
    }

    private function relative(string $path): string
    {
        $root = rtrim(realpath($this->projectDirectory) ?: $this->projectDirectory, '/\\');
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);
        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            throw new ConfigurationException("source snapshot escaped project root: {$path}");
        }

        return substr($normalizedPath, strlen($prefix));
    }
}
