<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\UnavailableException;

final class ZipPackageExtractor implements PackageExtractor
{
    public function extract(string $archive, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UnavailableException('ZIP extraction is unavailable');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UnavailableException('unable to open CLI update package');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name)) {
                    throw new UnavailableException('CLI update package contains an unnamed entry');
                }
                $normalized = str_replace('\\', '/', $name);
                $segments = explode('/', $normalized);
                if ($normalized === ''
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:/', $normalized) === 1
                    || str_contains($normalized, "\0")
                    || in_array('..', $segments, true)
                    || ($segments[0] ?? null) !== 'app'
                ) {
                    throw new UnavailableException("unsafe CLI update package entry: {$name}");
                }
                $target = $destination . '/' . $normalized;
                if (str_ends_with($normalized, '/')) {
                    $this->createDirectory($target);
                    continue;
                }
                $this->createDirectory(dirname($target));
                $source = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if (!is_resource($source) || !is_resource($output)) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new UnavailableException("unable to extract CLI update entry: {$name}");
                }
                try {
                    if (stream_copy_to_stream($source, $output) === false) {
                        throw new UnavailableException("unable to extract CLI update entry: {$name}");
                    }
                } finally {
                    fclose($source);
                    fclose($output);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("unable to create CLI update directory: {$directory}");
        }
    }
}
