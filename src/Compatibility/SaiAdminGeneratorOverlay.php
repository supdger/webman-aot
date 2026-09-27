<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;

final class SaiAdminGeneratorOverlay
{
    /**
     * @return array{path:string,sha256:string}
     */
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $expectedStubSha256,
        string $privateCache
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
}
