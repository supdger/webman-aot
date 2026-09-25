<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

use WebmanAot\Cli\ConfigurationException;

final class StaticSdkFingerprint
{
    public function digest(string $directory): string
    {
        $root = realpath($directory);
        if (!is_string($root) || !is_dir($root)) {
            throw new ConfigurationException('full-static SDK directory is missing');
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!in_array(strtolower($entry->getExtension()), ['a', 'o'], true)) {
                continue;
            }
            if (!$entry->isFile() || $entry->isLink()) {
                throw new ConfigurationException('full-static SDK contains an invalid static file');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $files[$relative] = $entry->getPathname();
        }
        ksort($files, SORT_STRING);
        if ($files === []) {
            throw new ConfigurationException('full-static SDK contains no static files');
        }

        $input = '';
        foreach ($files as $relative => $file) {
            $digest = hash_file('sha256', $file);
            if (!is_string($digest)) {
                throw new ConfigurationException("unable to hash SDK file: {$relative}");
            }
            $input .= $relative . "\0" . $digest . "\n";
        }
        return hash('sha256', $input);
    }
}
