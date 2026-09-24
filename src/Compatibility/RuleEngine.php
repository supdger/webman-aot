<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;

final class RuleEngine
{
    /**
     * @param list<CompatibilityRule> $rules
     * @param array<string,string> $versions
     * @return list<array{path:string,shadowPath:string,sourceSha256:string,shadowSha256:string,rules:list<string>}>
     */
    public function apply(
        string $projectDirectory,
        string $buildDirectory,
        array $rules,
        array $versions
    ): array {
        $projectRoot = realpath($projectDirectory);
        $buildRoot = realpath($buildDirectory);
        if (!is_string($projectRoot) || !is_string($buildRoot) || is_link($buildDirectory)
            || !str_starts_with($buildRoot, $projectRoot . DIRECTORY_SEPARATOR . '.webman-aot' . DIRECTORY_SEPARATOR)
        ) {
            throw new ConfigurationException('compatibility build directory is not an owned workspace');
        }
        $planned = [];
        $ids = [];
        foreach ($rules as $rule) {
            if (isset($ids[$rule->id()])) {
                throw new ConfigurationException("duplicate compatibility rule: {$rule->id()}");
            }
            $ids[$rule->id()] = true;
            $relative = $rule->sourcePath();
            if (!$this->safePath($relative)) {
                throw new ConfigurationException("unsafe compatibility source path: {$relative}");
            }
            $sourcePath = $projectRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $realSource = realpath($sourcePath);
            if (!is_string($realSource) || $realSource !== $sourcePath
                || !is_file($sourcePath) || is_link($sourcePath)
            ) {
                throw new ConfigurationException("compatibility source missing or unsafe: {$relative}");
            }
            $version = $versions[$rule->dependency()] ?? null;
            if (!is_string($version)) {
                throw new ConfigurationException(
                    "compatibility rule {$rule->id()}: missing dependency {$rule->dependency()}"
                );
            }
            if (!isset($planned[$relative])) {
                $source = file_get_contents($sourcePath);
                if (!is_string($source)) {
                    throw new ConfigurationException("unable to read compatibility source: {$relative}");
                }
                $planned[$relative] = [
                    'source' => $source,
                    'output' => $source,
                    'rules' => [],
                ];
            }
            $planned[$relative]['output'] = $rule->transform(
                $planned[$relative]['output'],
                $version
            );
            $planned[$relative]['rules'][] = $rule->id();
        }

        ksort($planned, SORT_STRING);
        $manifest = [];
        foreach ($planned as $relative => $entry) {
            $shadowPath = $buildRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($shadowPath);
            $this->prepareParent($buildRoot, $parent);
            if (file_exists($shadowPath) || is_link($shadowPath)) {
                if (is_link($shadowPath) || !is_file($shadowPath)) {
                    throw new ConfigurationException("unsafe compatibility shadow path: {$relative}");
                }
            }
            if (file_put_contents($shadowPath, $entry['output']) === false) {
                throw new ConfigurationException("unable to write compatibility shadow: {$relative}");
            }
            $manifest[] = [
                'path' => $relative,
                'shadowPath' => $shadowPath,
                'sourceSha256' => hash('sha256', $entry['source']),
                'shadowSha256' => hash('sha256', $entry['output']),
                'rules' => $entry['rules'],
            ];
        }
        return $manifest;
    }

    private function safePath(string $path): bool
    {
        return preg_match('~^(?!/)(?![A-Za-z]:)(?!.*(?:^|/)\.\.(?:/|$))(?!.*\\\\)[A-Za-z0-9_./-]+\.php$~D', $path) === 1
            && !str_contains($path, '//')
            && !str_starts_with($path, '.webman-aot/');
    }

    private function prepareParent(string $buildRoot, string $parent): void
    {
        $relative = substr($parent, strlen($buildRoot));
        $current = $buildRoot;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative)) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new ConfigurationException("unsafe compatibility shadow parent: {$current}");
            }
            if (!is_dir($current) && !mkdir($current, 0700)) {
                throw new ConfigurationException("unable to create compatibility shadow parent: {$current}");
            }
        }
    }
}
