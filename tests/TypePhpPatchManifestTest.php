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

        foreach ([
            '0001-full-static-sdk-target.patch',
            '0002-static-extension-registry.patch',
            '0003-reproducible-source-identities.patch',
            '0004-full-static-host-target-separation.patch',
        ] as $patch) {
            $this->assert(is_file($directory . '/' . $patch), "missing TypePHP patch: {$patch}");
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
