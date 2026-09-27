<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Version;

final class MinimalComponentLock
{
    /**
     * @return array{archive:string,sha256:string,manifestSha256:string,toolchainLockSha256:string,url:string}|null
     */
    public function forHost(string $path, string $toolchainLock, string $host): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        if (is_link($path)) {
            throw new ConfigurationException('minimal component lock must not be a symbolic link');
        }
        $contents = file_get_contents($path);
        $lock = is_string($contents)
            ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
            : null;
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-builder-minimal-components-lock-v1'
            || ($lock['version'] ?? null) !== Version::VALUE
            || !is_array($lock['components'] ?? null)
        ) {
            throw new ConfigurationException('minimal component lock is invalid or belongs to another version');
        }
        $expectedToolchain = $lock['toolchainLockSha256'] ?? null;
        $actualToolchain = is_file($toolchainLock) && !is_link($toolchainLock)
            ? hash_file('sha256', $toolchainLock)
            : false;
        if (!is_string($expectedToolchain) || !is_string($actualToolchain)
            || !hash_equals($expectedToolchain, $actualToolchain)
        ) {
            throw new ConfigurationException('minimal component source toolchain lock differs');
        }
        $component = $lock['components'][$host] ?? null;
        if ($component === null) {
            return null;
        }
        $archive = is_array($component) ? ($component['archive'] ?? null) : null;
        $sha256 = is_array($component) ? ($component['sha256'] ?? null) : null;
        $manifestSha256 = is_array($component) ? ($component['manifestSha256'] ?? null) : null;
        if (!is_string($archive)
            || $archive !== 'webman-aot-builder-' . Version::VALUE . '-' . $host . '-components.zip'
            || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !is_string($manifestSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $manifestSha256) !== 1
        ) {
            throw new ConfigurationException("minimal component lock for {$host} is invalid");
        }
        return [
            'archive' => $archive,
            'sha256' => $sha256,
            'manifestSha256' => $manifestSha256,
            'toolchainLockSha256' => $expectedToolchain,
            'url' => 'https://github.com/supdger/webman-aot-builder/releases/download/v'
                . Version::VALUE . '/' . $archive,
        ];
    }
}
