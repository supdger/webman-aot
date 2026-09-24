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
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
