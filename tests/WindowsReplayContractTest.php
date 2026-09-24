<?php

declare(strict_types=1);

final class WindowsReplayContractTest
{
    public function run(string $root): void
    {
        $path = $root . '/tools/windows-replay.ps1';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('unable to read Windows replay entry');
        }

        foreach ([
            'typephp-source',
            'typephp-windows-x64',
            'phpx-source',
            'phpx-sdk-linux-x64',
            'llvm-windows-x64',
            'alpine-musl-dev-x86-64',
            'alpine-linux-headers-x86-64',
            'alpine-libstdcpp-dev-x86-64',
            'alpine-fortify-headers-x86-64',
            'alpine-gcc-x86-64',
        ] as $component) {
            $this->assert(
                str_contains($contents, "'{$component}'"),
                "Windows replay omits locked component {$component}"
            );
        }

        foreach ([
            'Get-FileHash',
            "GetFolderPath('LocalApplicationData')",
            'apply-typephp-patches.php',
            'assemble-sysroot.php',
            'reproducibility-input.php',
            'build-full-static-smoke.php',
            'expectedNormalizedInputSha256',
            'matchesMacNormalizedInput',
            'expectedMacArtifactSha256',
        ] as $required) {
            $this->assert(
                str_contains($contents, $required),
                "Windows replay contract is missing {$required}"
            );
        }

        $this->assert(
            preg_match('/\\b(?:docker|podman|winget|choco|Start-Process)\\b/i', $contents) !== 1,
            'Windows replay must not use a container, package manager, or GUI installer'
        );
        $this->assert(
            preg_match('/Parameter\\(Mandatory\\s*=\\s*\\$true\\).*\\$(?:Artifacts|WorkRoot)/is', $contents) !== 1,
            'Windows replay paths must default to the current user private directory'
        );
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
