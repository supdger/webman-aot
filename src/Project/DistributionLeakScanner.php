<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class DistributionLeakScanner
{
    /**
     * @param list<string> $sensitiveMarkers Known private account, database, or path values.
     * @return array{files:int,approvedPhp:int}
     */
    public function scan(
        string $distributionDirectory,
        RuntimeResourceManifest $resources,
        array $sensitiveMarkers = [],
        bool $strictMutable = true
    ): array {
        $root = realpath($distributionDirectory);
        if (!is_string($root) || $root === DIRECTORY_SEPARATOR
            || !is_dir($root) || is_link($distributionDirectory)
        ) {
            throw new ConfigurationException('distribution scan requires a concrete directory');
        }
        foreach ($sensitiveMarkers as $marker) {
            if (!is_string($marker) || strlen($marker) < 4
                || str_contains($marker, "\n") || str_contains($marker, "\r")
            ) {
                throw new ConfigurationException('invalid sensitive marker for distribution scan');
            }
        }
        $approvedPhp = [];
        $externalFiles = [];
        $writableDirectories = [];
        foreach ($resources->entries() as $entry) {
            $path = $entry['path'];
            if ($entry['kind'] === 'external-file') {
                $externalFiles[$path] = true;
            } elseif ($entry['kind'] === 'writable-directory') {
                $writableDirectories[] = $path;
            }
            if ($entry['kind'] !== 'file'
                || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php'
            ) {
                continue;
            }
            if (!in_array(
                $entry['role'],
                ['configuration', 'template', 'third-party-dynamic-php'],
                true
            )) {
                throw new ConfigurationException(
                    "distribution PHP resource role is not approved: {$path}"
                );
            }
            $approvedPhp[$path] = $entry;
        }

        $files = 0;
        $seenPhp = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr(
                $file->getPathname(),
                strlen($root) + 1
            ));
            if ($file->isLink()) {
                throw new ConfigurationException("distribution contains symlink: {$path}");
            }
            if (!$strictMutable
                && (isset($externalFiles[$path])
                    || $this->insideWritableDirectory($path, $writableDirectories))
            ) {
                continue;
            }
            $this->assertSafePath($path);
            if (!$file->isFile()) {
                continue;
            }
            $files++;
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $approval = $approvedPhp[$path] ?? null;
                if ($approval === null) {
                    throw new ConfigurationException(
                        "distribution contains unapproved PHP source: {$path}"
                    );
                }
                $digest = hash_file('sha256', $file->getPathname());
                if (!is_string($digest)
                    || (($strictMutable || !$approval['mutable'])
                        && $digest !== $approval['sourceSha256'])
                ) {
                    throw new ConfigurationException(
                        "distribution PHP resource digest drifted: {$path}"
                    );
                }
                $seenPhp[$path] = true;
            }
            $this->scanContents($file->getPathname(), $path, $sensitiveMarkers);
        }
        foreach ($approvedPhp as $path => $approval) {
            if ($approval['role'] === 'third-party-dynamic-php' && !isset($seenPhp[$path])) {
                throw new ConfigurationException(
                    "distribution omits registered third-party dynamic PHP: {$path}"
                );
            }
        }
        return ['files' => $files, 'approvedPhp' => count($seenPhp)];
    }

    /**
     * @param list<string> $directories
     */
    private function insideWritableDirectory(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }
        return false;
    }

    private function assertSafePath(string $path): void
    {
        $parts = explode('/', strtolower($path));
        if ($parts[0] === 'build') {
            throw new ConfigurationException(
                "distribution contains build or source metadata: {$path}"
            );
        }
        foreach ($parts as $part) {
            if (in_array($part, ['.webman-aot', '.typephp', '.git'], true)) {
                throw new ConfigurationException(
                    "distribution contains build or source metadata: {$path}"
                );
            }
        }
        $basename = strtolower(basename($path));
        if ($basename === '.env'
            || preg_match('/\.(?:o|obj|a|bc|ll|cc|cpp)$/D', $basename) === 1
        ) {
            throw new ConfigurationException(
                "distribution contains private or intermediate file: {$path}"
            );
        }
    }

    /**
     * @param list<string> $sensitiveMarkers
     */
    private function scanContents(
        string $absolute,
        string $relative,
        array $sensitiveMarkers
    ): void {
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            throw new ConfigurationException("distribution file cannot be scanned: {$relative}");
        }
        $overlap = '';
        $maxMarkerLength = 512;
        foreach ($sensitiveMarkers as $marker) {
            $maxMarkerLength = max($maxMarkerLength, strlen($marker));
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new ConfigurationException(
                        "distribution file cannot be scanned: {$relative}"
                    );
                }
                $window = $overlap . $chunk;
                foreach ($sensitiveMarkers as $marker) {
                    if (str_contains($window, $marker)) {
                        throw new ConfigurationException(
                            "distribution contains sensitive marker: {$relative}"
                        );
                    }
                }
                if (preg_match(
                    '~(?:/Users/[^/\x00]{1,128}/|/home/[^/\x00]{1,128}/'
                    . '|[A-Za-z]:\\\\Users\\\\[^\\\\\x00]{1,128}\\\\)~',
                    $window
                ) === 1) {
                    throw new ConfigurationException(
                        "distribution contains a private host path: {$relative}"
                    );
                }
                $overlap = substr($window, -$maxMarkerLength);
            }
        } finally {
            fclose($handle);
        }
    }
}
