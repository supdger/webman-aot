<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class ProjectDiscovery
{
    public const BUSINESS_PHP = 'business-php';
    public const CONFIG = 'config';
    public const TEMPLATE = 'template';
    public const STATIC_ASSET = 'static';
    public const INSTALL_ONLY = 'install-only';
    public const SOURCE_METADATA = 'source-metadata';
    public const UNCLASSIFIED = 'unclassified';

    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function discover(ProjectProfile $profile): DiscoveryResult
    {
        $roots = [
            ['path' => 'app', 'owner' => 'project', 'required' => true],
            ['path' => 'support', 'owner' => 'project', 'required' => false],
            ['path' => 'config', 'owner' => 'project', 'required' => false],
            ['path' => 'public', 'owner' => 'project', 'required' => false],
            [
                'path' => 'vendor/workerman/webman-framework/src',
                'owner' => 'webman-core',
                'required' => true,
            ],
        ];
        if ($profile->name() === ProjectProfile::SAIADMIN) {
            $roots[] = [
                'path' => 'vendor/saithink/saiadmin/src',
                'owner' => 'saiadmin-core',
                'required' => true,
            ];
        }

        $pluginRoot = $this->absolute('plugin');
        if (is_link($pluginRoot)) {
            throw new ConfigurationException('project discovery refuses symlink: plugin');
        }
        if (is_dir($pluginRoot)) {
            $plugins = [];
            foreach (new \DirectoryIterator($pluginRoot) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($entry->isLink()) {
                    throw new ConfigurationException(
                        "project discovery refuses symlink: plugin/{$entry->getFilename()}"
                    );
                }
                if (!$entry->isDir()) {
                    continue;
                }
                $name = $entry->getFilename();
                if (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
                    throw new ConfigurationException("invalid Webman plugin directory name: {$name}");
                }
                $plugins[] = $name;
            }
            sort($plugins, SORT_STRING);
            foreach ($plugins as $plugin) {
                $roots[] = [
                    'path' => "plugin/{$plugin}",
                    'owner' => "plugin:{$plugin}",
                    'required' => true,
                ];
            }
        }

        $files = [];
        foreach ($roots as $root) {
            $absolute = $this->absolute($root['path']);
            if (is_link($absolute)) {
                throw new ConfigurationException(
                    "project discovery refuses symlink: {$root['path']}"
                );
            }
            if (!is_dir($absolute)) {
                if ($root['required']) {
                    throw new ConfigurationException(
                        "required project source directory is missing: {$root['path']}"
                    );
                }
                continue;
            }
            $this->scanRoot($root['path'], $root['owner'], $files);
        }
        ksort($files, SORT_STRING);

        return new DiscoveryResult(array_values($files));
    }

    /**
     * @param array<string, array{path:string,category:string,owner:string}> $files
     */
    private function scanRoot(string $relativeRoot, string $owner, array &$files): void
    {
        $absoluteRoot = $this->absolute($relativeRoot);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $relative = $this->relative($entry->getPathname());
                throw new ConfigurationException("project discovery refuses symlink: {$relative}");
            }
            if (!$entry->isFile()) {
                continue;
            }
            $path = $this->relative($entry->getPathname());
            $category = $this->classify($path);
            if ($category === null) {
                continue;
            }
            $record = [
                'path' => $path,
                'category' => $category,
                'owner' => $owner,
            ];
            if (isset($files[$path]) && $files[$path] !== $record) {
                throw new ConfigurationException("project file has conflicting discovery owners: {$path}");
            }
            $files[$path] = $record;
        }
    }

    private function classify(string $path): string
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $basename = basename($normalized);
        if ($basename === '.ds_store' || $basename === 'readme.md') {
            return self::SOURCE_METADATA;
        }
        if (preg_match('#^plugin/[^/]+/(?:install\.php|db/)#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/(?:install\.php|db/)#D',
                $normalized
            ) === 1
        ) {
            return self::INSTALL_ONLY;
        }
        if (str_starts_with($normalized, 'config/')
            || preg_match('#^plugin/[^/]+/config/#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/config/#D',
                $normalized
            ) === 1
        ) {
            return self::CONFIG;
        }
        if (preg_match('#^app/(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match(
                '#^plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
        ) {
            return self::TEMPLATE;
        }
        if (str_starts_with($normalized, 'public/')
            || preg_match('#^plugin/[^/]+/public/#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/public/#D',
                $normalized
            ) === 1
        ) {
            return self::STATIC_ASSET;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            return self::BUSINESS_PHP;
        }

        return self::UNCLASSIFIED;
    }

    private function relative(string $path): string
    {
        $root = rtrim(realpath($this->projectDirectory) ?: $this->projectDirectory, '/\\');
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);
        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            throw new ConfigurationException("discovered path escaped project root: {$path}");
        }

        return substr($normalizedPath, strlen($prefix));
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
