<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

use WebmanAot\Cli\ConfigurationException;

final class FullStaticProjectOverlay
{
    private const BASE_KEYS = [
        'name',
        'sources',
        'ignore',
        'output',
        'mode',
        'optimize',
        'job',
        'debug',
    ];

    public function apply(string $projectFile, string $sysroot, ?string $profile = null): void
    {
        if (!is_file($projectFile) || is_link($projectFile)) {
            throw new ConfigurationException('generated TypePHP project file is missing or unsafe');
        }
        $source = file_get_contents($projectFile);
        if (!is_string($source) || str_contains($source, "\r")) {
            throw new ConfigurationException('generated TypePHP project file is unreadable or not LF-normalized');
        }
        $keys = [];
        preg_match_all('/^([a-z][a-z-]*):(?:[ \t]|$)/m', $source, $matches);
        foreach ($matches[1] as $key) {
            if (isset($keys[$key])) {
                throw new ConfigurationException("duplicate TypePHP project key: {$key}");
            }
            $keys[$key] = true;
        }
        if (array_keys($keys) !== self::BASE_KEYS
            || !str_contains($source, "\nsources:\n  - main.php\n")
            || !str_contains($source, "\nignore:\n  - ")
            || preg_match('/^output: build\/[A-Za-z0-9._-]+$/m', $source) !== 1
            || !str_contains($source, "\nmode: bin\noptimize: 2\n")
            || preg_match('/\njob: [1-9][0-9]*\ndebug: false\n$/D', $source) !== 1
        ) {
            throw new ConfigurationException('generated TypePHP project structure drifted');
        }
        if ($profile === 'saiadmin') {
            $source = $this->ignoreLockedCrontabExample($source, dirname($projectFile));
            $source = $this->ignoreLockedOptionalDoctrineBridge($source, dirname($projectFile));
        }
        $target = realpath($sysroot);
        if (!is_string($target) || !is_dir($target) || is_link($sysroot)) {
            throw new ConfigurationException('locked musl sysroot is missing or unsafe');
        }
        $target = str_replace('\\', '/', $target);
        if (preg_match('/["\r\n\x00-\x1f]/', $target) === 1) {
            throw new ConfigurationException('locked musl sysroot path cannot be quoted safely');
        }
        foreach ([
            '/usr/include/c++/12.2.1/vector',
            '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl/bits/c++config.h',
            '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/crtbegin.o',
            '/usr/lib/libstdc++.a',
        ] as $required) {
            if (!is_file($target . $required)) {
                throw new ConfigurationException("locked musl sysroot is incomplete: {$required}");
            }
        }

        $gcc = $target . '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1';
        $suffix = <<<YAML
build-dir: build
php-version: "8.4"
target-platform: x86_64-unknown-linux-musl
reproducible-source-prefix: /usr/src/webman-aot
cxx-std: c++17
cxx-flags: >-
  --sysroot="{$target}"
  -isystem "{$target}/usr/include/c++/12.2.1"
  -isystem "{$target}/usr/include/c++/12.2.1/x86_64-alpine-linux-musl"
c-flags: --sysroot="{$target}"
asm-flags: --sysroot="{$target}"
ld-flags: >-
  --sysroot="{$target}"
  -fuse-ld=lld
  -Wl,--allow-multiple-definition
  -Wl,--strip-debug
  -B"{$gcc}"
  -L"{$target}/usr/lib"
  -L"{$gcc}"
YAML;
        if (file_put_contents($projectFile, $source . $suffix . "\n", LOCK_EX) === false) {
            throw new ConfigurationException('unable to write full-static TypePHP project overlay');
        }
    }

    private function ignoreLockedCrontabExample(string $source, string $project): string
    {
        $relative = 'vendor/workerman/crontab/example/test.php';
        $example = $project . '/' . $relative;
        if (!is_file($example)) {
            return $source;
        }
        $composer = $project . '/composer.lock';
        $lock = is_file($composer)
            ? json_decode((string) file_get_contents($composer), true)
            : null;
        $package = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $item) {
            if (is_array($item) && ($item['name'] ?? null) === 'workerman/crontab') {
                $package = $item;
                break;
            }
        }
        if (($package['version'] ?? null) !== 'v1.0.7'
            || ($package['source']['reference'] ?? null)
                !== '74f51ca8204e8eb628e57bc0e640561d570da2cb'
            || hash_file('sha256', $example)
                !== '13f145e9289437c40d7dab886ff925f20f61a3630b3fb293a6ceb98855398b92'
            || substr_count($source, "\noutput:") !== 1
        ) {
            throw new ConfigurationException('workerman/crontab example exclusion drifted');
        }
        return str_replace(
            "\noutput:",
            "  - {$relative}\n\noutput:",
            $source
        );
    }

    private function ignoreLockedOptionalDoctrineBridge(string $source, string $project): string
    {
        $composer = $project . '/composer.lock';
        $lock = is_file($composer)
            ? json_decode((string) file_get_contents($composer), true)
            : null;
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        $bridge = null;
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            if (($package['name'] ?? null) === 'doctrine/dbal') {
                return $source;
            }
            if (($package['name'] ?? null) === 'carbonphp/carbon-doctrine-types') {
                $bridge = $package;
            }
        }
        if ($bridge === null) {
            return $source;
        }
        if (($bridge['version'] ?? null) !== '3.2.1'
            || ($bridge['source']['reference'] ?? null)
                !== '5fa5eacafd9ef47c8c6ab9143fc901ba0194d3dc'
        ) {
            throw new ConfigurationException('optional Carbon Doctrine bridge version drifted');
        }
        $prefix = 'vendor/carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/';
        $digests = [
            'CarbonDoctrineType.php' => '17f266a752e281fab45a9d8b7e867fb579a78f718922a3cf34dccde3494a649e',
            'CarbonImmutableType.php' => '3dd7360c838af41c862e3f3fc3e2f5a9e1f0bc90a1668bf71acd5b765c411897',
            'CarbonType.php' => '1d73b7b5cbb6a5c332e21fdb93b84ede922901152a17af885b3a3e3e3d099c74',
            'CarbonTypeConverter.php' => '0d28fa73ff575370743b3d50f5b355253f377a4665df7ac3a3814b6b2527208e',
            'DateTimeDefaultPrecision.php' => '18ddf052751eef5568d959fce27883399ab5e46a9490a70344ce503f642fb52a',
            'DateTimeImmutableType.php' => 'eddb0dcd82ded9e7c3025e2b41fe654161dbbc013ac5f9386f80fd8623273f12',
            'DateTimeType.php' => '30e19093fff903cbf357fbc777ee120dcf344d310a486442b3e77700dc478e7f',
        ];
        $directory = $project . '/' . $prefix;
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException('optional Carbon Doctrine bridge source is missing or unsafe');
        }
        $actual = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || !$entry->isFile() || $entry->getExtension() !== 'php') {
                throw new ConfigurationException('optional Carbon Doctrine bridge source structure drifted');
            }
            $actual[$entry->getFilename()] = true;
        }
        if (array_keys($actual) !== array_keys($digests)) {
            $names = array_keys($actual);
            $expected = array_keys($digests);
            sort($names, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($names !== $expected) {
                throw new ConfigurationException('optional Carbon Doctrine bridge file set drifted');
            }
        }
        $excluded = [];
        foreach ($digests as $name => $digest) {
            $relative = $prefix . $name;
            $file = $project . '/' . $relative;
            if (!is_file($file) || is_link($file)) {
                throw new ConfigurationException("optional Carbon Doctrine bridge source is missing or unsafe: {$relative}");
            }
            if (hash_file('sha256', $file) !== $digest) {
                throw new ConfigurationException("optional Carbon Doctrine bridge source digest drifted: {$relative}");
            }
            if (str_contains($source, "\n  - {$relative}\n")) {
                throw new ConfigurationException("optional Carbon Doctrine bridge source is already listed: {$relative}");
            }
            $excluded[] = "  - {$relative}";
        }
        if (substr_count($source, "\noutput:") !== 1) {
            throw new ConfigurationException('optional Carbon Doctrine bridge overlay structure drifted');
        }
        return str_replace("\noutput:", implode("\n", $excluded) . "\n\noutput:", $source);
    }
}
