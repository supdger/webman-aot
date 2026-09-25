<?php

declare(strict_types=1);

use WebmanAot\Project\ProjectBuilder;
use WebmanAot\Project\SourceTreeSnapshot;

final class ProjectBuilderTest
{
    public function run(string $repository): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-builder-' . bin2hex(random_bytes(8));
        $project = $root . '/project';
        $cache = $root . '/private-cache';
        mkdir($project, 0700, true);
        mkdir($cache, 0700);
        $source = $repository . '/tests/fixtures/minimal-webman';
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
        ) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $destination = $project . '/' . $relative;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0700, true);
            }
            copy($entry->getPathname(), $destination);
        }
        mkdir($project . '/vendor/workerman/webman-framework/src/support', 0700, true);
        file_put_contents(
            $project . '/vendor/workerman/webman-framework/src/support/bootstrap.php',
            "<?php\n"
        );
        $compatibilityLock = $root . '/compatibility.lock.json';
        $toolchainLock = $root . '/toolchain.lock.json';
        $compilerPatchManifest = $root . '/compiler-patches.json';
        file_put_contents(
            $compatibilityLock,
            json_encode(['dynamicPhp' => []], JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $toolchainLock,
            json_encode(['requiredExtensions' => ['pdo' => ['php-source']]], JSON_THROW_ON_ERROR)
        );
        copy(
            $repository . '/toolchain/patches/typephp/0.9.2/manifest.json',
            $compilerPatchManifest
        );
        mkdir($project . '/dist-aot', 0700);
        file_put_contents($project . '/dist-aot/server', 'old verified distribution');
        $before = (new SourceTreeSnapshot($project))->capture();
        try {
            foreach (['profile', 'discovery', 'workspace', 'mirror', 'generate'] as $stage) {
                $seen = [];
                try {
                    (new ProjectBuilder())->build(
                        $project,
                        $root . '/unused-generator.zip',
                        $cache,
                        $compatibilityLock,
                        $toolchainLock,
                        $compilerPatchManifest,
                        [],
                        'macos-arm64',
                        static function (string $current) use ($stage, &$seen): void {
                            $seen[] = $current;
                            if ($current === $stage) {
                                throw new RuntimeException("injected {$stage} failure");
                            }
                        }
                    );
                    throw new RuntimeException("builder did not fail at {$stage}");
                } catch (RuntimeException $exception) {
                    $this->assert(
                        $exception->getMessage() === "injected {$stage} failure",
                        "builder stage {$stage} failed unexpectedly: {$exception->getMessage()}"
                    );
                }
                $this->assert(
                    end($seen) === $stage
                    && file_get_contents($project . '/dist-aot/server')
                        === 'old verified distribution'
                    && (new SourceTreeSnapshot($project))->capture() === $before,
                    "builder changed the source or published after {$stage} failure"
                );
            }
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
