<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\UnavailableException;

final class UpstreamGeneratorArchive
{
    private const FILES = [
        'src/Compiler/ProjectGenerator.php' => 'sourceSha256',
        'src/Compiler/Profile/SaiAdminProfile.php' => 'profileSha256',
        'src/Stubs/main.php.stub' => 'mainStubSha256',
    ];

    /**
     * @param array<string,mixed> $generatorLock
     */
    public function materialize(
        string $archive,
        array $generatorLock,
        string $privateCache
    ): string {
        $root = realpath($privateCache);
        if (!is_string($root) || !is_dir($root) || is_link($privateCache)) {
            throw new ConfigurationException('upstream generator private cache is missing or unsafe');
        }
        $revision = $generatorLock['revision'] ?? null;
        $archiveSha256 = $generatorLock['archiveSha256'] ?? null;
        if (!is_string($revision)
            || preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1
            || !is_string($archiveSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $archiveSha256) !== 1
        ) {
            throw new ConfigurationException('upstream generator lock is invalid');
        }
        foreach (self::FILES as $field) {
            $digest = $generatorLock[$field] ?? null;
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new ConfigurationException("upstream generator lock lacks {$field}");
            }
        }
        $actualArchiveDigest = is_file($archive) && !is_link($archive)
            ? hash_file('sha256', $archive)
            : false;
        if (!is_string($actualArchiveDigest)
            || !hash_equals($archiveSha256, $actualArchiveDigest)
        ) {
            throw new UnavailableException('upstream generator archive digest mismatch');
        }

        $destination = $root . '/upstream-generator-' . $revision;
        if (file_exists($destination) || is_link($destination)) {
            $this->assertInstalled($destination, $generatorLock);
            return $destination;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new UnavailableException('ZIP extraction is unavailable');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UnavailableException('unable to open upstream generator archive');
        }
        $candidate = $root . '/.upstream-generator-' . bin2hex(random_bytes(8));
        try {
            if (!mkdir($candidate, 0700)) {
                throw new ConfigurationException('unable to create upstream generator candidate');
            }
            $prefix = 'webman-typephp-' . $revision . '/';
            foreach (self::FILES as $relative => $field) {
                $contents = $zip->getFromName($prefix . $relative);
                if (!is_string($contents)
                    || !hash_equals($generatorLock[$field], hash('sha256', $contents))
                ) {
                    throw new UnavailableException(
                        "upstream generator archive file drift: {$relative}"
                    );
                }
                $target = $candidate . '/' . $relative;
                if (!is_dir(dirname($target))
                    && !mkdir(dirname($target), 0700, true)
                    && !is_dir(dirname($target))
                ) {
                    throw new ConfigurationException(
                        "unable to prepare upstream generator file: {$relative}"
                    );
                }
                if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new ConfigurationException(
                        "unable to write upstream generator file: {$relative}"
                    );
                }
            }
            $this->assertInstalled($candidate, $generatorLock);
            if (!rename($candidate, $destination)) {
                throw new ConfigurationException('unable to activate upstream generator');
            }
        } finally {
            $zip->close();
            if (is_dir($candidate)) {
                $this->removeCandidate($candidate);
            }
        }
        return $destination;
    }

    /**
     * @param array<string,mixed> $generatorLock
     */
    private function assertInstalled(string $directory, array $generatorLock): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException('upstream generator installation is unsafe');
        }
        foreach (self::FILES as $relative => $field) {
            $path = $directory . '/' . $relative;
            $digest = is_file($path) && !is_link($path)
                ? hash_file('sha256', $path)
                : false;
            if (!is_string($digest)
                || !hash_equals($generatorLock[$field], $digest)
            ) {
                throw new ConfigurationException(
                    "upstream generator installed file drift: {$relative}"
                );
            }
        }
    }

    private function removeCandidate(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
