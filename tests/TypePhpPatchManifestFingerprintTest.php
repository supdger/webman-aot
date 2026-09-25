<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Toolchain\TypePhpPatchManifestFingerprint;

final class TypePhpPatchManifestFingerprintTest
{
    public function run(string $root): void
    {
        $source = $root . '/toolchain/patches/typephp/0.9.2/manifest.json';
        $manifest = json_decode(
            (string) file_get_contents($source),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fingerprint = new TypePhpPatchManifestFingerprint();
        $expected = $fingerprint->digest($source);
        $temporary = sys_get_temp_dir() . '/webman-aot-patch-fingerprint-' . bin2hex(random_bytes(8));
        try {
            $reordered = $manifest;
            $reordered['rules'] = array_reverse($reordered['rules']);
            file_put_contents($temporary, json_encode($reordered, JSON_THROW_ON_ERROR));
            $this->assert(
                $fingerprint->digest($temporary) === $expected,
                'patch rule order changed the semantic fingerprint'
            );

            $changed = $reordered;
            $changed['rules'][0]['afterSha256'] = str_repeat('a', 64);
            file_put_contents($temporary, json_encode($changed, JSON_THROW_ON_ERROR));
            $this->assert(
                $fingerprint->digest($temporary) !== $expected,
                'patch digest drift did not change the semantic fingerprint'
            );

            $duplicate = $manifest;
            $duplicate['rules'][] = $duplicate['rules'][0];
            file_put_contents($temporary, json_encode($duplicate, JSON_THROW_ON_ERROR));
            try {
                $fingerprint->digest($temporary);
                throw new RuntimeException('duplicate patch rule was accepted');
            } catch (ConfigurationException) {
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
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
