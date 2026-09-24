<?php

declare(strict_types=1);

final class StructureTest
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function run(): void
    {
        $this->assertRequiredFiles();
        $this->assertNoSymlinks();
        $this->assertComposerIsIndependent();
        $this->assertNoSiblingProjectReferences();
    }

    private function assertRequiredFiles(): void
    {
        $required = [
            '.gitignore',
            'LICENSE',
            'README.md',
            'bin/webman-aot',
            'bin/webman-aot.cmd',
            'bin/webman-aot.php',
            'composer.json',
            'src/Cli/Application.php',
            'src/Cli/DiagnosticBundleWriter.php',
            'src/Cli/ExitCode.php',
            'src/Cli/RunLogger.php',
            'src/Cli/Runtime.php',
            'src/Cli/StagePipeline.php',
            'src/Cli/StageStatus.php',
            'src/Cli/StageTracker.php',
            'src/Doctor/Doctor.php',
            'src/Doctor/DoctorReport.php',
            'src/Doctor/NativeSystemProbe.php',
            'src/Doctor/SystemProbe.php',
            'src/Platform/UserDirectoryLayout.php',
            'src/README.md',
            'src/Version.php',
            'toolchain/README.md',
            'toolchain/recipes/README.md',
            'tools/toolchain.php',
        ];

        foreach ($required as $relativePath) {
            $this->assert(
                is_file($this->root . '/' . $relativePath),
                "missing required file: {$relativePath}"
            );
        }
    }

    private function assertNoSymlinks(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $this->assert(
                !$entry->isLink(),
                'repository skeleton must not depend on symlink: ' . $entry->getPathname()
            );
        }
    }

    private function assertComposerIsIndependent(): void
    {
        $contents = file_get_contents($this->root . '/composer.json');
        $this->assert($contents !== false, 'unable to read composer.json');

        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $dependencies = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );

        $this->assert(
            !array_key_exists('tinywan/webman-typephp', $dependencies),
            'target-project Composer plugin dependency is forbidden'
        );
    }

    private function assertNoSiblingProjectReferences(): void
    {
        $forbidden = [
            '../webman-' . 'typephp',
            '/work/webman-' . 'typephp',
            '\\work\\webman-' . 'typephp',
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->root,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getSize() > 1024 * 1024) {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());
            if ($contents === false) {
                throw new RuntimeException('unable to read: ' . $entry->getPathname());
            }

            foreach ($forbidden as $needle) {
                $this->assert(
                    !str_contains($contents, $needle),
                    sprintf('forbidden sibling project reference in %s', $entry->getPathname())
                );
            }
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
