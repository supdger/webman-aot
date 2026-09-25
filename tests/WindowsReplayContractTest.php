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
            "Join-Path \$env:SystemRoot 'System32\\tar.exe'",
            "Get-Command 'curl.exe'",
            'Select-Object -First 1',
            "'--continue-at' '-'",
            "'--retry-all-errors'",
            'TypePHP Windows archive extraction',
            'PHPX SDK xz extraction',
            'PHPX SDK tar extraction',
            'LLVM xz extraction',
            'LLVM tar extraction',
            "Join-Path \$llvmRoot 'bin\\clang++.exe'",
            "Join-Path \$llvmRoot 'bin\\llvm-nm.exe'",
            "Join-Path \$llvmRoot 'bin\\llvm-objcopy.exe'",
            "\$compilerVersion -notmatch '^clang version 19\\.1\\.7(?:\\s|\$)'",
            'strip-sdk-debug.php',
            'private SDK debug stripping',
            'apply-typephp-patches.php',
            'assemble-sysroot.php',
            'Get-WorkRelativePath $WorkRoot $compiler',
            'locked archive is missing in offline mode',
            '$LockFile = [IO.Path]::GetFullPath($LockFile)',
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

        require_once $root . '/src/Toolchain/ReproducibilityInput.php';
        $this->assert(
            preg_match(
                "/expectedNormalizedInputSha256 = '([0-9a-f]{64})'/",
                $contents,
                $matches
            ) === 1 &&
            $matches[1] === (new \WebmanAot\Toolchain\ReproducibilityInput())
                ->describe($root)['sha256'],
            'Windows replay normalized input baseline must match the current repository input'
        );

        $this->assert(
            preg_match('/\\b(?:docker|podman|winget|choco|Start-Process)\\b/i', $contents) !== 1,
            'Windows replay must not use a container, package manager, or GUI installer'
        );
        $this->assert(
            preg_match('/Parameter\\(Mandatory\\s*=\\s*\\$true\\).*\\$(?:Artifacts|WorkRoot)/is', $contents) !== 1,
            'Windows replay paths must default to the current user private directory'
        );
        $this->assert(
            str_contains($contents, 'downloaded archive digest mismatch') &&
            str_contains($contents, 'Remove-Item -LiteralPath $partial -Force'),
            'Windows replay must delete a digest-mismatched download candidate'
        );
        $this->assert(
            !str_contains($contents, 'Expand-Archive') &&
            str_contains($contents, "& \$systemTar -xf \$archives['typephp-windows-x64']"),
            'Windows replay must avoid the observed Expand-Archive hang'
        );
        $this->assert(
            !str_contains($contents, "& tar.exe -xf \$archives['phpx-sdk-linux-x64']") &&
            str_contains($contents, "& \$sevenZip x '-y' \"-o\$sdkExtract\" \$archives['phpx-sdk-linux-x64']"),
            'Windows replay must avoid the observed tar.exe hang on the PHPX SDK tar.xz'
        );
        $this->assert(
            str_contains($contents, "& \$sevenZip x '-y' \"-o\$llvmExtract\" \$archives['llvm-windows-x64']") &&
            str_contains($contents, "& \$sevenZip x '-y' \"-o\$llvmPayload\" \$llvmTar.FullName") &&
            !str_contains($contents, "& \$archives['llvm-windows-x64'] '/S'"),
            'Windows replay must extract the locked portable LLVM archive without an installer'
        );
        $prepareStart = strpos($contents, 'if ($PrepareOnly) {');
        $smokeStart = strpos($contents, "Write-Host 'Calculating normalized reproducibility input ...'");
        $this->assert(
            $prepareStart !== false &&
            $smokeStart !== false &&
            $prepareStart < $smokeStart &&
            str_contains($contents, "'prepared-toolchain.json'") &&
            str_contains($contents, "'webman-aot-prepared-toolchain-v1'"),
            'Windows preparation must produce a private tool manifest without requiring smoke fixtures'
        );

        $workflowPath = $root . '/.github/workflows/windows-full-static-replay.yml';
        $workflow = file_get_contents($workflowPath);
        if ($workflow === false) {
            throw new RuntimeException('unable to read Windows replay workflow');
        }
        foreach ([
            'runs-on: windows-2022',
            "      - main\n",
            '.\\tools\\windows-replay.ps1',
            'windows-replay.json',
            'full_static_cross_smoke',
        ] as $required) {
            $this->assert(
                str_contains($workflow, $required),
                "Windows replay workflow is missing {$required}"
            );
        }
        $this->assert(
            preg_match('/\\b(?:docker|podman|winget|choco|setup-php)\\b/i', $workflow) !== 1,
            'Windows replay workflow must use the repository-owned private toolchain'
        );
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
