<?php

declare(strict_types=1);

final class InstallerContractTest
{
    public function run(string $root): void
    {
        $lock = json_decode(
            (string) file_get_contents($root . '/installer/runtime.lock.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assert(
            ($lock['schema'] ?? null) === 'webman-aot-installer-runtime-lock-v1',
            'installer runtime lock schema drifted'
        );
        foreach (['macos-arm64', 'windows-x86_64'] as $platform) {
            $runtime = $lock['runtimes'][$platform] ?? null;
            $this->assert(is_array($runtime), "installer runtime is missing: {$platform}");
            foreach (['phpVersion', 'provider', 'binarySha256', 'extensions'] as $field) {
                $this->assert(
                    isset($runtime[$field]),
                    "installer runtime field is missing: {$platform}.{$field}"
                );
            }
            $this->assert(
                is_string($runtime['binarySha256'])
                && preg_match('/^[a-f0-9]{64}$/D', $runtime['binarySha256']) === 1,
                "installer runtime digest is invalid: {$platform}"
            );
            foreach (['openssl', 'zip'] as $extension) {
                $this->assert(
                    in_array($extension, $runtime['extensions'], true),
                    "installer runtime lacks required extension: {$platform}.{$extension}"
                );
            }
        }
        $macRuntime = $lock['runtimes']['macos-arm64'];
        $this->assert(
            is_string($macRuntime['upstreamBinarySha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $macRuntime['upstreamBinarySha256']) === 1
            && $macRuntime['upstreamBinarySha256'] !== $macRuntime['binarySha256'],
            'macOS CLI PHP normalization lock is missing or ineffective'
        );
        $normalizer = (string) file_get_contents($root . '/tools/sanitize-macos-cli-runtime.php');
        $this->assert(
            str_contains($normalizer, "substr_count(\$binary, \$buildPrefix) !== 17")
            && str_contains($normalizer, "'org.webman-aot.cli-php'")
            && str_contains($normalizer, "'/usr/bin/codesign'"),
            'macOS CLI PHP normalization must guard source drift and deterministic signing'
        );

        $scripts = [
            'installer/macos/install.sh',
            'installer/macos/uninstall.sh',
            'installer/windows/install.ps1',
            'installer/windows/uninstall.ps1',
        ];
        foreach ($scripts as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            $this->assert(is_string($contents), "unable to read installer: {$relative}");
            foreach ([
                'brew install',
                'winget ',
                'docker ',
                'sudo ',
                'Start-Process -Verb RunAs',
            ] as $forbidden) {
                $this->assert(
                    !str_contains(strtolower($contents), strtolower($forbidden)),
                    "installer invokes forbidden global dependency path: {$relative}"
                );
            }
        }

        $mac = (string) file_get_contents($root . '/installer/macos/install.sh');
        $windows = (string) file_get_contents($root . '/installer/windows/install.ps1');
        $packager = (string) file_get_contents($root . '/tools/package-installers.php');
        $packageInstructions = (string) file_get_contents($root . '/installer/README.md');
        foreach ([
            '--mac-runtime=',
            '--mac-compiler-driver=',
            '--mac-runtime-license-dir=',
            '--windows-runtime-archive=',
            '--output=',
        ] as $requiredOption) {
            $this->assert(
                str_contains($packageInstructions, $requiredOption),
                "installer packaging instructions omit {$requiredOption}"
            );
        }
        $this->assert(
            str_contains($mac, 'shasum -a 256 -c payload-manifest.sha256'),
            'macOS installer does not verify its payload'
        );
        $this->assert(
            str_contains($windows, 'Get-FileHash -Algorithm SHA256'),
            'Windows installer does not verify its payload'
        );
        $this->assert(
            str_contains($mac, '.install-candidates')
            && str_contains($windows, '.install-candidates'),
            'installer candidate activation contract is missing'
        );
        $this->assert(
            str_contains($packager, "requiredOption('mac-runtime-license-dir')")
            && str_contains($packager, "/payload/runtime/licenses"),
            'macOS installer package omits private runtime licenses'
        );
        $this->assert(
            str_contains($packager, "requiredOption('mac-compiler-driver')")
            && str_contains($packager, "/payload/runtime/bin/php-compiler")
            && str_contains($packager, "/installer/runtime.lock.json"),
            'macOS installer package omits the locked compiler PHP driver'
        );
        foreach ([
            "'windows-replay.ps1'",
            "'macos-prepare.php'",
            "'apply-typephp-patches.php'",
            "'strip-sdk-debug.php'",
            "'assemble-sysroot.php'",
            "'/toolchain/patches/typephp/0.9.2'",
            "'/compatibility/locks/webman-workerman-2026-09-25.json'",
        ] as $required) {
            $this->assert(
                str_contains($packager, $required),
                "installer package omits a required build resource: {$required}"
            );
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
