<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class GeneratedProjectCoveragePlanner
{
    /**
     * @return array{
     *   discovery:DiscoveryResult,
     *   coverage:CoverageLedger,
     *   resources:RuntimeResourceManifest,
     *   compiled:array{direct:int,shadow:int}
     * }
     */
    public function plan(
        string $mirrorDirectory,
        ProjectProfile $profile,
        string $compatibilityLockFile,
        array $generatedMappings = []
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || is_link($mirrorDirectory)
            || !str_ends_with(
                str_replace('\\', '/', $mirror),
                '/.webman-aot/build/project'
            )
        ) {
            throw new ConfigurationException(
                'generated coverage requires an isolated project mirror'
            );
        }
        $lockSource = is_file($compatibilityLockFile)
            && !is_link($compatibilityLockFile)
            ? file_get_contents($compatibilityLockFile)
            : false;
        if (!is_string($lockSource)) {
            throw new ConfigurationException('generated coverage compatibility lock is missing');
        }
        try {
            $lock = json_decode($lockSource, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'generated coverage compatibility lock is invalid',
                previous: $exception
            );
        }
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-upstream-generator-lock-v1'
            || !is_array($lock['mappings'] ?? null)
            || !is_array($lock['entrypointMapping'] ?? null)
            || !is_array($lock['dynamicPhp'] ?? null)
        ) {
            throw new ConfigurationException(
                'generated coverage compatibility lock shape drifted'
            );
        }

        $discovery = (new ProjectDiscovery($mirror, $lock['dynamicPhp']))
            ->discover($profile);
        $businessPaths = [];
        foreach ($discovery->files() as $file) {
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP) {
                $businessPaths[$file['path']] = true;
            }
        }

        $mappings = $lock['mappings'];
        if ($profile->name() === ProjectProfile::SAIADMIN) {
            foreach ($generatedMappings as $generated) {
                if (!is_array($generated)
                    || !is_string($generated['path'] ?? null)
                    || !is_string($generated['shadow'] ?? null)
                    || !is_string($generated['sourceSha256'] ?? null)
                    || !is_string($generated['shadowSha256'] ?? null)
                ) {
                    throw new ConfigurationException('SaiAdmin generated coverage mapping is invalid');
                }
                $source = $generated['path'];
                if (isset($mappings[$source])) {
                    if ($mappings[$source]['shadow'] !== $generated['shadow']
                        || $mappings[$source]['sourceSha256'] !== $generated['sourceSha256']
                        || $mappings[$source]['shadowSha256'] !== $generated['shadowSha256']
                    ) {
                        throw new ConfigurationException(
                            "SaiAdmin generated coverage conflicts with locked mapping: {$source}"
                        );
                    }
                    continue;
                }
                $mappings[$source] = $generated;
            }
        } elseif ($generatedMappings !== []) {
            foreach ($generatedMappings as $generated) {
                if (!is_array($generated)
                    || !is_string($generated['path'] ?? null)
                    || !isset($mappings[$generated['path']])
                ) {
                    throw new ConfigurationException('Webman generated coverage mapping is undeclared');
                }
            }
        }

        $decisions = [];
        foreach ($mappings as $source => $mapping) {
            if (!isset($businessPaths[$source])) {
                continue;
            }
            if (!is_array($mapping)
                || !is_string($mapping['shadow'] ?? null)
                || !is_string($mapping['sourceSha256'] ?? null)
                || !is_string($mapping['shadowSha256'] ?? null)
                || $this->digest($mirror, $source) !== $mapping['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "generated coverage source mapping drifted: {$source}"
                );
            }
            $expectedShadowSha256 = $mapping['adaptedShadowSha256']
                ?? $mapping['shadowSha256'];
            if (!is_string($expectedShadowSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedShadowSha256) !== 1
                || $this->digest($mirror, $mapping['shadow'])
                    !== $expectedShadowSha256
            ) {
                throw new ConfigurationException(
                    "generated coverage shadow mapping drifted: {$source}"
                );
            }
            $decisions[$source] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => 'upstream.typephp-generated-shadow.v1',
                'reason' => 'locked upstream AOT replacement is compiled instead of the original',
                'replacement' => $mapping['shadow'],
            ];
        }

        $entrypoint = $lock['entrypointMapping'];
        if (($entrypoint['source'] ?? null)
                !== 'vendor/workerman/webman-framework/src/support/bootstrap.php'
            || ($entrypoint['replacement'] ?? null) !== 'main.php'
            || ($entrypoint['policy'] ?? null)
                !== 'upstream.webman-bootstrap-entrypoint.v1'
            || !is_string($entrypoint['sourceSha256'] ?? null)
            || !is_string($entrypoint['replacementSha256'] ?? null)
            || !isset($businessPaths[$entrypoint['source']])
            || $this->digest($mirror, $entrypoint['source'])
                !== $entrypoint['sourceSha256']
            || $this->digest($mirror, $entrypoint['replacement'])
                !== $entrypoint['replacementSha256']
            || isset($decisions[$entrypoint['source']])
        ) {
            throw new ConfigurationException(
                'generated coverage entrypoint mapping drifted'
            );
        }
        $decisions[$entrypoint['source']] = [
            'status' => CoverageLedger::COMPILED_SHADOW,
            'policy' => $entrypoint['policy'],
            'reason' => 'locked Webman bootstrap is replaced by the compiled entrypoint',
            'replacement' => 'main.php',
        ];
        $projectBootstrap = 'support/bootstrap.php';
        if (isset($businessPaths[$projectBootstrap])) {
            if ($this->digest($mirror, $projectBootstrap)
                !== $entrypoint['sourceSha256']
            ) {
                throw new ConfigurationException(
                    'project bootstrap differs from the locked Webman startup source'
                );
            }
            $decisions[$projectBootstrap] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => $entrypoint['policy'],
                'reason' => 'the stock project bootstrap is replaced by the compiled entrypoint',
                'replacement' => 'main.php',
            ];
        }
        if ($profile->name() === ProjectProfile::SAIADMIN) {
            foreach (['Request', 'Response'] as $name) {
                $source = "vendor/workerman/webman-framework/src/support/{$name}.php";
                $replacement = "support/{$name}.php";
                if (!isset($businessPaths[$source])) {
                    continue;
                }
                if (!isset($businessPaths[$replacement])
                    || !$this->isSupportClass($mirror, $source, $name)
                    || !$this->isSupportClass($mirror, $replacement, $name)
                ) {
                    throw new ConfigurationException(
                        "Webman project support override is missing or structurally invalid: {$name}"
                    );
                }
                $decisions[$source] = [
                    'status' => CoverageLedger::COMPILED_SHADOW,
                    'policy' => 'webman.project-support-override.v1',
                    'reason' => 'compiled project support class supersedes the inactive framework fallback',
                    'replacement' => $replacement,
                ];
            }
        }

        $coverage = (new CoveragePlanner($mirror))->plan($discovery, $decisions);
        $compiled = (new CompilerCoverageAudit($entrypoint))->audit(
            $mirror,
            $coverage
        );
        $resources = (new RuntimeResourcePlanner($mirror))->plan(
            $discovery,
            $coverage,
            $this->requiredRuntimeFiles($mirror, $lock)
        );
        return [
            'discovery' => $discovery,
            'coverage' => $coverage,
            'resources' => $resources,
            'compiled' => $compiled,
        ];
    }

    /**
     * @param array<string,mixed> $lock
     * @return list<array{path:string,role:string}>
     */
    private function requiredRuntimeFiles(string $mirror, array $lock): array
    {
        $composerFile = $mirror . '/composer.lock';
        $composer = is_file($composerFile) && !is_link($composerFile)
            ? json_decode((string) file_get_contents($composerFile), true)
            : null;
        if (!is_array($composer)) {
            throw new ConfigurationException('runtime resource Composer lock is missing or invalid');
        }
        $captcha = null;
        foreach (array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'webman/captcha') {
                $captcha = $package;
                break;
            }
        }
        if ($captcha === null) {
            return [];
        }
        $policy = $lock['runtimeResources']['webman/captcha'] ?? null;
        $prefix = 'vendor/webman/captcha/src/';
        $builder = $mirror . '/' . $prefix . 'CaptchaBuilder.php';
        $fontDirectory = $mirror . '/' . $prefix . 'Font';
        if (!is_array($policy)
            || ($captcha['version'] ?? null) !== ($policy['version'] ?? null)
            || ($captcha['source']['reference'] ?? null) !== ($policy['reference'] ?? null)
            || !is_file($builder) || is_link($builder)
            || hash_file('sha256', $builder) !== ($policy['builderSha256'] ?? null)
            || !str_contains(
                (string) file_get_contents($builder),
                "__DIR__ . '/Font/captcha'.\$this->rand(0, 4).'.ttf'"
            )
            || !is_dir($fontDirectory) || is_link($fontDirectory)
            || !is_array($policy['fonts'] ?? null)
        ) {
            throw new ConfigurationException('webman/captcha runtime font policy drifted');
        }
        $expected = array_keys($policy['fonts']);
        $actual = [];
        foreach (new \DirectoryIterator($fontDirectory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || !$entry->isFile() || $entry->getExtension() !== 'ttf') {
                throw new ConfigurationException('webman/captcha runtime font directory drifted');
            }
            $actual[] = $entry->getFilename();
        }
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected || $expected !== [
            'captcha0.ttf', 'captcha1.ttf', 'captcha2.ttf', 'captcha3.ttf', 'captcha4.ttf',
        ]) {
            throw new ConfigurationException('webman/captcha runtime font set drifted');
        }
        $required = [];
        foreach ($expected as $name) {
            $path = $prefix . 'Font/' . $name;
            if (hash_file('sha256', $mirror . '/' . $path) !== $policy['fonts'][$name]) {
                throw new ConfigurationException("webman/captcha runtime font digest drifted: {$name}");
            }
            $required[] = ['path' => $path, 'role' => 'font'];
        }
        return $required;
    }

    private function digest(string $mirror, string $relative): ?string
    {
        if ($relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, '\\')
            || in_array('..', explode('/', $relative), true)
        ) {
            return null;
        }
        $path = $mirror . '/' . $relative;
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        return is_string($digest) ? $digest : null;
    }

    private function isSupportClass(string $mirror, string $relative, string $name): bool
    {
        $source = file_get_contents($mirror . '/' . $relative);
        return is_string($source)
            && preg_match('/\bnamespace\s+support\s*;/', $source) === 1
            && preg_match(
                '/\bclass\s+' . preg_quote($name, '/') . '\s+extends\s+\\\\Webman\\\\Http\\\\'
                    . preg_quote($name, '/') . '\b/',
                $source
            ) === 1;
    }
}
