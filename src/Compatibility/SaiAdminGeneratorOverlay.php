<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminGeneratorOverlay
{
    /**
     * @return array{path:string,sha256:string}
     */
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $expectedStubSha256,
        string $privateCache,
        ?string $mirror = null,
        array $carbonPolicy = []
    ): array {
        $source = is_file($sourceFile) && !is_link($sourceFile)
            ? file_get_contents($sourceFile)
            : false;
        if (!is_string($source)
            || !hash_equals($expectedSourceSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator source drifted');
        }
        $before = "                \$sourceRel === 'plugin/saiadmin/exception/SystemException.php',\n";
        $groupEnd = <<<'PHP'
                $sourceRel === 'vendor/webman/console/src/Application.php',
                    => 1,
PHP;
        $after = <<<'PHP'
                $sourceRel === 'vendor/webman/console/src/Application.php',
                    => 1,
                $sourceRel === 'plugin/saiadmin/exception/SystemException.php'
                    => match (true) {
                        substr_count($content, ', Throwable $previous = null)') === 1
                            && substr_count($content, ', ?Throwable $previous = null)') === 0 => 1,
                        substr_count($content, ', Throwable $previous = null)') === 0
                            && substr_count($content, ', ?Throwable $previous = null)') === 1 => 0,
                        default => -1,
                    },
PHP;
        if (substr_count($source, $before) !== 1 || substr_count($source, $groupEnd) !== 1) {
            throw new ConfigurationException('locked SaiAdmin exception rule structure drifted');
        }
        $overlay = str_replace($groupEnd, $after, str_replace($before, '', $source));
        if ($mirror !== null) {
            $overlay = $this->applyCarbonVersionRules($overlay, $mirror, $carbonPolicy);
        }
        $digest = hash('sha256', $overlay);
        $stubSource = dirname($sourceFile, 2) . '/Stubs/main.php.stub';
        $stub = is_file($stubSource) && !is_link($stubSource)
            ? file_get_contents($stubSource)
            : false;
        if (!is_string($stub)
            || !hash_equals($expectedStubSha256, hash('sha256', $stub))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator stub drifted');
        }
        $directory = $privateCache . '/saiadmin-generator-overlays/' . $digest . '/src';
        $compilerDirectory = $directory . '/Compiler';
        $stubDirectory = $directory . '/Stubs';
        if ((!is_dir($compilerDirectory) && !mkdir($compilerDirectory, 0700, true)
                && !is_dir($compilerDirectory))
            || (!is_dir($stubDirectory) && !mkdir($stubDirectory, 0700, true)
                && !is_dir($stubDirectory))
        ) {
            throw new ConfigurationException('cannot create private SaiAdmin generator overlay directory');
        }
        $stubTarget = $stubDirectory . '/main.php.stub';
        if (is_file($stubTarget)) {
            if (is_link($stubTarget) || hash_file('sha256', $stubTarget) !== $expectedStubSha256) {
                throw new ConfigurationException('private SaiAdmin generator stub drifted');
            }
        } elseif (file_put_contents($stubTarget, $stub, LOCK_EX) !== strlen($stub)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator stub');
        }
        $target = $compilerDirectory . '/ProjectGenerator.php';
        if (is_file($target)) {
            if (is_link($target) || hash_file('sha256', $target) !== $digest) {
                throw new ConfigurationException('private SaiAdmin generator overlay drifted');
            }
        } elseif (file_put_contents($target, $overlay, LOCK_EX) !== strlen($overlay)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator overlay');
        }
        return ['path' => $target, 'sha256' => $digest];
    }
    /** @param array<string,mixed> $policy */
    private function applyCarbonVersionRules(string $generator, string $mirror, array $policy): string
    {
        $lockFile = $mirror . '/composer.lock';
        $contents = is_file($lockFile) && !is_link($lockFile) ? file_get_contents($lockFile) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('Carbon adaptation requires a project lock');
        }
        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $package = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === 'nesbot/carbon') {
                $package = $candidate;
                break;
            }
        }
        $version = $package['version'] ?? null;
        $rule = is_string($version) ? ($policy['versions'][$version] ?? null) : null;
        if ($rule === null) {
            return $generator;
        }
        $sourcePath = $rule['sourcePath'] ?? null;
        $sourceSha256 = $rule['sourceSha256'] ?? null;
        $replacements = $rule['replacements'] ?? null;
        if (!is_array($rule) || !is_string($sourcePath)
            || preg_match('~^vendor/nesbot/carbon/[A-Za-z0-9_/]+\.php$~D', $sourcePath) !== 1
            || !is_string($sourceSha256) || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || !is_array($replacements) || $replacements === []
            || !is_string($rule['reference'] ?? null)
            || ($package['source']['reference'] ?? $package['dist']['reference'] ?? null) !== $rule['reference']
        ) {
            throw new ConfigurationException('Carbon version adaptation policy or source reference drifted');
        }
        $sourceFile = $mirror . '/' . $sourcePath;
        $source = is_file($sourceFile) && !is_link($sourceFile) ? file_get_contents($sourceFile) : false;
        if (!is_string($source) || !hash_equals($sourceSha256, hash('sha256', $source))) {
            throw new ConfigurationException('Carbon version adaptation source drifted');
        }
        $anchor = "        '{$sourcePath}' => [\n";
        if (substr_count($generator, $anchor) !== 1) {
            throw new ConfigurationException('Carbon generator mapping structure drifted');
        }
        $addition = '';
        foreach ($replacements as $before => $after) {
            if (!is_string($before) || $before === '' || !is_string($after) || $before === $after
                || substr_count($source, $before) !== 1
            ) {
                throw new ConfigurationException('Carbon version adaptation replacement structure drifted');
            }
            $addition .= '            ' . var_export($before, true) . ' => ' . var_export($after, true) . ",\n";
        }
        return str_replace($anchor, $anchor . $addition, $generator);
    }

}
