<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\UpstreamGeneratorBoundary;

final class UpstreamGeneratorBoundaryTest
{
    public function run(string $root): void
    {
        $lock = json_decode(
            (string) file_get_contents($root . '/compatibility/locks/webman-workerman-2026-09-25.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assert(
            ($lock['schema'] ?? null) === 'webman-aot-upstream-generator-lock-v1'
            && count($lock['mappings'] ?? []) === 26
            && ($lock['status'] ?? null) === 'prototype-only',
            'upstream generator prototype lock is incomplete'
        );
        $this->assertVerifiedOutput();
        foreach ([
            'missing' => 'omitted declared PHP',
            'extra' => 'undeclared PHP',
            'source-change' => 'changed source',
            'shadow-drift' => 'shadow drift',
            'generator-drift' => 'source digest mismatch',
            'generator-change' => 'changed during execution',
            'source-drift' => 'source drift',
            'package-drift' => 'dependency lock drift',
            'invalid-php' => 'emitted invalid PHP',
            'compiler-open' => 'compiler coverage is open',
        ] as $mode => $expected) {
            $this->assertFailure($mode, $expected);
        }
    }

    private function assertVerifiedOutput(): void
    {
        $fixture = $this->fixture();
        try {
            $result = (new UpstreamGeneratorBoundary())->run(
                $fixture['mirror'],
                $fixture['generator'],
                $fixture['generatorSha256'],
                $fixture['packages'],
                $fixture['expected'],
                fn () => $this->writeShadow($fixture, '<?php function compiled(): int { return 2; }')
            );
            $this->assert(
                count($result) === 1
                && $result[0]['path'] === 'vendor/workerman/webman-framework/src/App.php'
                && $result[0]['shadow'] === '.typephp/build/webman-app.php',
                'upstream generator manifest lost the source-to-shadow mapping'
            );
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    private function assertFailure(string $mode, string $message): void
    {
        $fixture = $this->fixture();
        try {
            if ($mode === 'generator-drift') {
                file_put_contents($fixture['generator'], "<?php // changed\n");
            }
            if ($mode === 'source-drift') {
                file_put_contents($fixture['source'], "<?php // changed\n");
            }
            if ($mode === 'package-drift') {
                file_put_contents(
                    $fixture['mirror'] . '/composer.lock',
                    '{"packages":[{"name":"workerman/webman-framework","version":"v2.2.5","source":{"reference":"fixture-ref"}}]}'
                );
            }
            $expectedMappings = $fixture['expected'];
            if ($mode === 'invalid-php') {
                $expectedMappings['vendor/workerman/webman-framework/src/App.php']['shadowSha256']
                    = hash('sha256', '<?php function {');
            }
            $action = function () use ($fixture, $mode): void {
                if ($mode === 'missing') {
                    mkdir($fixture['mirror'] . '/.typephp/build', 0700, true);
                    return;
                }
                $shadow = $mode === 'shadow-drift'
                    ? '<?php function compiled(): int { return 3; }'
                    : ($mode === 'invalid-php'
                        ? '<?php function {'
                        : '<?php function compiled(): int { return 2; }');
                $this->writeShadow($fixture, $shadow);
                if ($mode === 'extra') {
                    file_put_contents($fixture['mirror'] . '/.typephp/build/extra.php', '<?php');
                }
                if ($mode === 'source-change') {
                    file_put_contents($fixture['source'], "<?php // modified by generator\n");
                }
                if ($mode === 'compiler-open') {
                    file_put_contents(
                        $fixture['mirror'] . '/project.linux.yml',
                        "sources:\n  - .typephp/build/webman-app.php\nignore:\n"
                    );
                }
                if ($mode === 'generator-change') {
                    file_put_contents($fixture['generator'], "<?php // modified by generator\n");
                }
            };
            try {
                (new UpstreamGeneratorBoundary())->run(
                    $fixture['mirror'],
                    $fixture['generator'],
                    $fixture['generatorSha256'],
                    $fixture['packages'],
                    $expectedMappings,
                    $action
                );
            } catch (ConfigurationException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), $message),
                    "unexpected upstream generator failure: {$exception->getMessage()}"
                );
                return;
            }
            throw new RuntimeException("upstream generator did not reject {$mode}");
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    /**
     * @return array{
     *   root:string,
     *   mirror:string,
     *   generator:string,
     *   generatorSha256:string,
     *   source:string,
     *   packages:array<string,array{version:string,reference:string}>,
     *   expected:array<string,array{shadow:string,sourceSha256:string,shadowSha256:string}>
     * }
     */
    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/webman-aot-upstream-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        $source = $mirror . '/vendor/workerman/webman-framework/src/App.php';
        mkdir(dirname($source), 0700, true);
        file_put_contents($source, '<?php function original(): int { return 1; }');
        $generator = $root . '/pinned-generator.php';
        file_put_contents($generator, "<?php // pinned generator fixture\n");
        file_put_contents(
            $mirror . '/composer.lock',
            '{"packages":[{"name":"workerman/webman-framework","version":"v2.2.4","source":{"reference":"fixture-ref"}}]}'
        );
        return [
            'root' => $root,
            'mirror' => $mirror,
            'generator' => $generator,
            'generatorSha256' => (string) hash_file('sha256', $generator),
            'source' => $source,
            'packages' => [
                'workerman/webman-framework' => [
                    'version' => 'v2.2.4',
                    'reference' => 'fixture-ref',
                ],
            ],
            'expected' => [
                'vendor/workerman/webman-framework/src/App.php' => [
                    'shadow' => '.typephp/build/webman-app.php',
                    'sourceSha256' => (string) hash_file('sha256', $source),
                    'shadowSha256' => hash('sha256', '<?php function compiled(): int { return 2; }'),
                ],
            ],
        ];
    }

    /**
     * @param array{mirror:string} $fixture
     */
    private function writeShadow(array $fixture, string $contents): void
    {
        $directory = $fixture['mirror'] . '/.typephp/build';
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/webman-app.php', $contents);
        file_put_contents(
            $fixture['mirror'] . '/project.linux.yml',
            "sources:\n  - .typephp/build/webman-app.php\n"
            . "ignore:\n  - vendor/workerman/webman-framework/src/App.php\n"
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
