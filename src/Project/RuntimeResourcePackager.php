<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class RuntimeResourcePackager
{
    /**
     * @return array{copiedFiles:int,writableDirectories:int,externalFiles:int}
     */
    public function package(
        string $sourceDirectory,
        string $candidateDirectory,
        RuntimeResourceManifest $manifest
    ): array {
        $source = realpath($sourceDirectory);
        $candidate = realpath($candidateDirectory);
        if (!is_string($source)
            || !is_string($candidate)
            || $source === DIRECTORY_SEPARATOR
            || $candidate === DIRECTORY_SEPARATOR
            || $source === $candidate
            || str_starts_with($candidate, $source . DIRECTORY_SEPARATOR)
            || is_link($sourceDirectory)
            || is_link($candidateDirectory)
        ) {
            throw new ConfigurationException('resource packaging requires distinct source and candidate directories');
        }
        $counts = ['copiedFiles' => 0, 'writableDirectories' => 0, 'externalFiles' => 0];
        foreach ($manifest->entries() as $entry) {
            $path = $this->validatePath($entry['path']);
            if ($entry['kind'] === 'external-file') {
                if (file_exists($candidate . '/' . $path)
                    || is_link($candidate . '/' . $path)
                ) {
                    throw new ConfigurationException(
                        "external runtime file was copied into candidate: {$path}"
                    );
                }
                $counts['externalFiles']++;
                continue;
            }
            $destination = $candidate . '/' . $path;
            $this->makeParent($candidate, dirname($path));
            if ($entry['kind'] === 'writable-directory') {
                if (file_exists($destination) || is_link($destination)
                    || !mkdir($destination, 0700)
                ) {
                    throw new ConfigurationException(
                        "writable runtime directory is unsafe: {$path}"
                    );
                }
                $counts['writableDirectories']++;
                continue;
            }
            if ($entry['kind'] !== 'file'
                || !is_string($entry['sourceSha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $entry['sourceSha256']) !== 1
            ) {
                throw new ConfigurationException(
                    "runtime resource manifest entry is invalid: {$path}"
                );
            }
            $original = $source . '/' . $path;
            if ($this->hasSymlinkComponent($source, $path)
                || !is_file($original)
                || file_exists($destination)
                || is_link($destination)
                || hash_file('sha256', $original) !== $entry['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "runtime resource is missing, changed, or conflicts: {$path}"
                );
            }
            if (!copy($original, $destination)
                || !chmod($destination, 0644)
                || hash_file('sha256', $destination) !== $entry['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "runtime resource copy failed verification: {$path}"
                );
            }
            $counts['copiedFiles']++;
        }
        return $counts;
    }

    private function makeParent(string $candidate, string $parent): void
    {
        if ($parent === '.') {
            return;
        }
        $relative = '';
        foreach (explode('/', $parent) as $part) {
            $relative = $relative === '' ? $part : $relative . '/' . $part;
            $absolute = $candidate . '/' . $relative;
            if (is_link($absolute)
                || (file_exists($absolute) && !is_dir($absolute))
            ) {
                throw new ConfigurationException(
                    "runtime resource parent is unsafe: {$relative}"
                );
            }
            if (!is_dir($absolute) && !mkdir($absolute, 0700)) {
                throw new ConfigurationException(
                    "runtime resource parent cannot be created: {$relative}"
                );
            }
        }
    }

    private function hasSymlinkComponent(string $root, string $path): bool
    {
        $relative = '';
        foreach (explode('/', $path) as $part) {
            $relative = $relative === '' ? $part : $relative . '/' . $part;
            if (is_link($root . '/' . $relative)) {
                return true;
            }
        }
        return false;
    }

    private function validatePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || $normalized !== $path
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || in_array('', explode('/', $normalized), true)
            || in_array('.', explode('/', $normalized), true)
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw new ConfigurationException("unsafe runtime resource path: {$path}");
        }
        return $path;
    }
}
