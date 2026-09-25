<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\UnavailableException;

final class PreparedToolchain
{
    /**
     * @return array{php:string,typephp:string,phpx:string,compiler:string,objcopy:string,sysroot:string,phprc:string,sdkSha256:string}
     */
    public function load(
        string $manifestPath,
        string $privateRoot,
        string $lockFile,
        string $host
    ): array {
        $root = realpath($privateRoot);
        $manifest = realpath($manifestPath);
        if (!is_string($root) || !is_string($manifest)
            || !is_file($manifest) || is_link($manifestPath)
            || !$this->inside($manifest, $root)
        ) {
            throw new UnavailableException('prepared toolchain manifest is missing or outside the private directory');
        }
        $contents = file_get_contents($manifest);
        if (!is_string($contents)) {
            throw new ConfigurationException('prepared toolchain manifest cannot be read');
        }
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'prepared toolchain manifest is invalid',
                previous: $exception
            );
        }
        $lockDigest = is_file($lockFile) && !is_link($lockFile)
            ? hash_file('sha256', $lockFile)
            : false;
        if (!is_array($data)
            || ($data['schema'] ?? null) !== 'webman-aot-prepared-toolchain-v1'
            || ($data['host'] ?? null) !== $host
            || !is_string($lockDigest)
            || ($data['lockSha256'] ?? null) !== $lockDigest
        ) {
            throw new ConfigurationException('prepared toolchain host or lock differs from the current build');
        }
        $workRoot = dirname($manifest);
        $tools = [];
        foreach (['php', 'typephp', 'phpx', 'compiler', 'objcopy', 'sysroot', 'phprc'] as $name) {
            $path = $data[$name] ?? null;
            $resolved = is_string($path)
                ? $this->resolveToolPath($path, $workRoot)
                : null;
            $actual = is_string($resolved) ? realpath($resolved) : false;
            if (!is_string($actual)
                || !is_string($resolved)
                || !$this->inside($actual, $workRoot)
                || (in_array($name, ['php', 'compiler', 'objcopy'], true)
                    ? !is_file($actual) || !is_executable($actual)
                    : !is_dir($actual))
            ) {
                throw new ConfigurationException("prepared toolchain {$name} is missing or unsafe");
            }
            $tools[$name] = $name === 'compiler'
                ? str_replace('\\', '/', $resolved)
                : $actual;
        }
        if (!$this->inside($tools['phpx'], $tools['typephp'])
            || !is_file($tools['typephp'] . '/bin/tpc.php')
            || is_link($tools['typephp'] . '/bin/tpc.php')
        ) {
            throw new ConfigurationException('prepared TypePHP and PHPX tree is incomplete');
        }
        $sdk = $tools['phpx'] . '/full-static/sdk';
        $expected = $data['sdkSha256'] ?? null;
        if (!is_string($expected)
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || !is_dir($sdk)
            || is_link($sdk)
            || (new StaticSdkFingerprint())->digest($sdk) !== $expected
        ) {
            throw new ConfigurationException('prepared static SDK fingerprint differs');
        }
        $tools['sdkSha256'] = $expected;

        /** @var array{php:string,typephp:string,phpx:string,compiler:string,objcopy:string,sysroot:string,phprc:string,sdkSha256:string} $tools */
        return $tools;
    }

    private function resolveToolPath(string $path, string $workRoot): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        if (str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\')
        ) {
            return $path;
        }
        if (str_contains($path, '\\')
            || in_array('', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
        ) {
            return null;
        }
        return $workRoot . '/' . $path;
    }

    private function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return str_starts_with($path, $root . '/');
    }
}
