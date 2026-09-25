<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\ProjectDiscovery;
use WebmanAot\Project\ProjectProfile;

final class PluginSourceCompletion
{
    /**
     * Add only discovered plugin business PHP omitted by the upstream source list.
     * Existing source and ignore decisions remain untouched.
     *
     * @param array<string,string> $dynamicPhp
     */
    public function apply(string $mirrorDirectory, string $profile, array $dynamicPhp): ?string
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !str_ends_with(str_replace('\\', '/', $mirror), '/.webman-aot/build/project')
            || !in_array($profile, [ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
        ) {
            throw new ConfigurationException('plugin source completion requires an isolated project mirror');
        }
        $projectFile = $mirror . '/project.linux.yml';
        $project = is_file($projectFile) && !is_link($projectFile)
            ? file_get_contents($projectFile)
            : false;
        if (!is_string($project) || str_contains($project, "\r")
            || substr_count($project, "\nsources:\n") !== 1
            || substr_count($project, "\nignore:\n") !== 1
            || substr_count($project, "\noutput:") !== 1
        ) {
            throw new ConfigurationException('generated plugin source list structure drifted');
        }
        $sources = $this->section($project, 'sources', 'ignore');
        $ignore = $this->section($project, 'ignore', 'output');
        $discovery = (new ProjectDiscovery($mirror, $dynamicPhp))
            ->discover(new ProjectProfile($profile, [], []));
        $missing = [];
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            if ($file['category'] !== ProjectDiscovery::BUSINESS_PHP
                || !str_starts_with($file['owner'], 'plugin:')
                || $this->covered($path, $sources)
            ) {
                continue;
            }
            if (preg_match('~^plugin/[A-Za-z0-9_-]+/[A-Za-z0-9_./-]+\.php$~D', $path) !== 1
                || $this->covered($path, $ignore)
                || !is_file($mirror . '/' . $path)
                || is_link($mirror . '/' . $path)
            ) {
                throw new ConfigurationException(
                    "discovered plugin PHP cannot be added to compiler sources: {$path}"
                );
            }
            $missing[] = $path;
        }
        if ($missing === []) {
            return null;
        }
        sort($missing, SORT_STRING);
        $lines = '';
        foreach ($missing as $path) {
            $lines .= "  - {$path}\n";
        }
        $adapted = str_replace("\nignore:\n", "\n{$lines}ignore:\n", $project);
        if ($adapted === $project
            || file_put_contents($projectFile, $adapted, LOCK_EX) !== strlen($adapted)
        ) {
            throw new ConfigurationException('unable to complete plugin compiler sources');
        }
        return hash(
            'sha256',
            json_encode($missing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return list<string> */
    private function section(string $project, string $name, string $next): array
    {
        $startMarker = "\n{$name}:\n";
        $endMarker = "\n{$next}:";
        $start = strpos($project, $startMarker);
        $end = strpos($project, $endMarker);
        if ($start === false || $end === false || $start >= $end) {
            throw new ConfigurationException("generated {$name} section order drifted");
        }
        $block = substr($project, $start + strlen($startMarker), $end - $start - strlen($startMarker));
        $entries = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('~^  - ([A-Za-z0-9_./-]+)$~D', $line, $matches) !== 1
                || str_contains('/' . $matches[1] . '/', '/../')
                || str_contains('/' . $matches[1] . '/', '/./')
            ) {
                throw new ConfigurationException("generated {$name} entry drifted");
            }
            $entries[] = rtrim($matches[1], '/');
        }
        if ($entries === [] || count($entries) !== count(array_unique($entries))) {
            throw new ConfigurationException("generated {$name} entries are empty or duplicated");
        }
        return $entries;
    }

    /** @param list<string> $entries */
    private function covered(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }
        return false;
    }
}
