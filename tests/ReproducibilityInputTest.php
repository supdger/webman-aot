<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Toolchain/ReproducibilityInput.php';

use WebmanAot\Toolchain\ReproducibilityInput;

final class ReproducibilityInputTest
{
    public function run(string $root): void
    {
        $first = (new ReproducibilityInput())->describe($root);
        $second = (new ReproducibilityInput())->describe($root);
        $this->assert($first === $second, 'normalized reproducibility input must be deterministic');
        $this->assert(
            preg_match('/^[a-f0-9]{64}$/D', $first['sha256']) === 1,
            'normalized reproducibility input digest is invalid'
        );
        $this->assert(
            ($first['input']['build']['targetTriple'] ?? null) === 'x86_64-unknown-linux-musl',
            'normalized reproducibility target drifted'
        );
        $this->assert(
            count($first['input']['patches'] ?? []) === 5,
            'normalized reproducibility input must include all TypePHP patches'
        );
        $this->assertLineEndingsDoNotChangeInput($root, $first);
    }

    /**
     * @param array{schema:string,input:array<string, mixed>,sha256:string} $expected
     */
    private function assertLineEndingsDoNotChangeInput(string $root, array $expected): void
    {
        $temporaryRoot = sys_get_temp_dir() . '/webman-aot-reproducibility-' . bin2hex(random_bytes(8));
        $patchRelativeDirectory = 'toolchain/patches/typephp/0.9.2';
        $fixtureRelativePath = 'tests/fixtures/full-static-smoke/main.php';
        $textPaths = [
            $patchRelativeDirectory . '/0001-full-static-sdk-target.patch',
            $patchRelativeDirectory . '/0002-static-extension-registry.patch',
            $patchRelativeDirectory . '/0003-reproducible-source-identities.patch',
            $patchRelativeDirectory . '/0004-full-static-host-target-separation.patch',
            $patchRelativeDirectory . '/0005-windows-clang-response-paths.patch',
            $fixtureRelativePath,
        ];

        try {
            foreach ([
                $temporaryRoot,
                $temporaryRoot . '/' . $patchRelativeDirectory,
                dirname($temporaryRoot . '/' . $fixtureRelativePath),
            ] as $directory) {
                if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException("unable to create test directory: {$directory}");
                }
            }
            if (!copy($root . '/toolchain.lock.json', $temporaryRoot . '/toolchain.lock.json')) {
                throw new RuntimeException('unable to copy toolchain lock for line-ending test');
            }
            foreach ($textPaths as $relativePath) {
                $contents = file_get_contents($root . '/' . $relativePath);
                if (!is_string($contents)) {
                    throw new RuntimeException("unable to read line-ending fixture: {$relativePath}");
                }
                $lf = str_replace(["\r\n", "\r"], "\n", $contents);
                $written = file_put_contents(
                    $temporaryRoot . '/' . $relativePath,
                    str_replace("\n", "\r\n", $lf)
                );
                if ($written === false) {
                    throw new RuntimeException("unable to write line-ending fixture: {$relativePath}");
                }
            }

            $actual = (new ReproducibilityInput())->describe($temporaryRoot);
            $this->assert(
                $actual === $expected,
                'normalized reproducibility input must ignore host checkout line endings'
            );
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
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
