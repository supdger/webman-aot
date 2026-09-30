<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminProfileOverlay
{
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $projectLockFile,
        string $privateCache,
        array $policy
    ): string {
        $lock = json_decode(
            (string) file_get_contents($projectLockFile),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $version = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'nesbot/carbon') {
                $version = $package['version'] ?? null;
                break;
            }
        }
        $supported = $policy['supportedVersions'] ?? null;
        $baseVersion = $policy['baseVersion'] ?? null;
        if (!is_array($supported) || !is_string($baseVersion)
            || !in_array($baseVersion, $supported, true)
        ) {
            throw new ConfigurationException('Carbon profile version policy is missing');
        }
        foreach ($supported as $candidate) {
            if (!is_string($candidate) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $candidate) !== 1) {
                throw new ConfigurationException('Carbon profile version policy is invalid');
            }
        }
        if ($version === $baseVersion || !in_array($version, $supported, true)) {
            return $sourceFile;
        }
        $source = file_get_contents($sourceFile);
        if (!is_string($source)
            || !hash_equals($expectedSourceSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException('locked SaiAdmin profile source drifted');
        }
        $before = "'nesbot/carbon' => ['{$baseVersion}'],";
        $after = "'nesbot/carbon' => ['" . implode("', '", $supported) . "'],";
        if (substr_count($source, $before) !== 1) {
            throw new ConfigurationException('locked Carbon version gate structure drifted');
        }
        $shadow = str_replace($before, $after, $source);
        if (substr_count($shadow, $after) !== 1 || str_contains($shadow, $before)) {
            throw new ConfigurationException('Carbon version gate overlay postcondition failed');
        }
        $directory = $privateCache . '/saiadmin-profile-overlays';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException('cannot create private SaiAdmin profile overlay directory');
        }
        $target = $directory . '/carbon-' . $version . '-' . hash('sha256', $shadow) . '.php';
        if (is_file($target)) {
            if (hash_file('sha256', $target) !== hash('sha256', $shadow)) {
                throw new ConfigurationException('private SaiAdmin profile overlay drifted');
            }
        } elseif (file_put_contents($target, $shadow, LOCK_EX) !== strlen($shadow)) {
            throw new ConfigurationException('cannot write private SaiAdmin profile overlay');
        }
        return $target;
    }
}
