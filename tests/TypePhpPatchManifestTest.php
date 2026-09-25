<?php

declare(strict_types=1);

final class TypePhpPatchManifestTest
{
    public function run(string $root): void
    {
        $directory = $root . '/toolchain/patches/typephp/0.9.2';
        $contents = file_get_contents($directory . '/manifest.json');
        if ($contents === false) {
            throw new RuntimeException('unable to read TypePHP patch manifest');
        }

        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assert(
            is_array($manifest)
                && ($manifest['component'] ?? null) === 'typephp-source'
                && ($manifest['version'] ?? null) === '0.9.2',
            'unexpected TypePHP patch manifest identity'
        );

        $rules = $manifest['rules'] ?? null;
        $this->assert(is_array($rules) && $rules !== [], 'TypePHP patch rules must not be empty');
        $paths = [];
        foreach ($rules as $rule) {
            $this->assert(is_array($rule), 'TypePHP patch rule must be an object');
            $path = $rule['path'] ?? null;
            $before = $rule['beforeSha256'] ?? null;
            $after = $rule['afterSha256'] ?? null;
            $this->assert(
                is_string($path) && $path !== '' && !isset($paths[$path]),
                'TypePHP patch paths must be non-empty and unique'
            );
            $this->assert(
                is_string($before) && preg_match('/^[a-f0-9]{64}$/D', $before) === 1,
                "invalid before digest for {$path}"
            );
            $this->assert(
                is_string($after) && preg_match('/^[a-f0-9]{64}$/D', $after) === 1,
                "invalid after digest for {$path}"
            );
            $this->assert($before !== $after, "TypePHP patch rule does not change {$path}");
            $paths[$path] = true;
        }

        $applier = file_get_contents($root . '/tools/apply-typephp-patches.php');
        $this->assert(is_string($applier), 'unable to read TypePHP patch applier');
        foreach ([
            '0001-full-static-sdk-target.patch',
            '0002-static-extension-registry.patch',
            '0003-reproducible-source-identities.patch',
            '0004-full-static-host-target-separation.patch',
            '0005-windows-clang-response-paths.patch',
            '0006-pcntl-target-reference-metadata.patch',
            '0007-full-static-skip-host-php-import-libs.patch',
            '0008-full-static-linux-internal-metadata.patch',
            '0009-full-static-linux-builtin-lifetime.patch',
            '0010-reproducible-arginfo-source-identity.patch',
            '0011-native-inheritance-and-target-reference-calls.patch',
            '0012-loop-probe-defers-unknown-foreach-vars.patch',
            '0013-namespaced-global-function-dependencies.patch',
            '0014-default-helpers-and-globals-reference.patch',
            '0015-portable-full-static-magic-dir.patch',
            '0016-embedded-anonymous-class-valid-php.patch',
            '0017-full-static-anonymous-source-embedding.patch',
            '0018-full-static-target-php-eol.patch',
            '0019-full-static-target-internal-functions.patch',
            '0020-full-static-target-constants-and-host-functions.patch',
            '0021-portable-source-scan-order.patch',
            '0022-full-static-hide-host-only-reflection.patch',
            '0023-full-static-select-target-reflection.patch',
        ] as $patch) {
            $this->assert(is_file($directory . '/' . $patch), "missing TypePHP patch: {$patch}");
            $this->assert(
                str_contains($applier, "'{$patch}'"),
                "TypePHP patch is not applied: {$patch}"
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
