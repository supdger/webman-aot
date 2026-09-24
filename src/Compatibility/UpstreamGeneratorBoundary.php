<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;

final class UpstreamGeneratorBoundary
{
    /**
     * @param array<string,array{version:string,reference:string}> $packages
     * @param array<string,array{shadow:string,sourceSha256:string,shadowSha256:string}> $expected
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function run(
        string $mirrorDirectory,
        string $generatorFile,
        string $generatorSha256,
        array $packages,
        array $expected,
        \Closure $generate
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || !$this->isBuildMirror($mirror) || $expected === []) {
            throw new ConfigurationException('upstream generator requires a nonempty isolated build mirror');
        }
        $this->assertPackages($mirror, $packages);
        $this->assertDigest($generatorSha256);
        if (!is_file($generatorFile) || is_link($generatorFile)
            || hash_file('sha256', $generatorFile) !== $generatorSha256
        ) {
            throw new ConfigurationException('upstream generator source digest mismatch');
        }
        $generatedRoot = $mirror . DIRECTORY_SEPARATOR . '.typephp'
            . DIRECTORY_SEPARATOR . 'build';
        if (file_exists($generatedRoot) || is_link($generatedRoot)) {
            throw new ConfigurationException('upstream generator output is not a clean mirror');
        }

        $targets = [];
        foreach ($expected as $source => $entry) {
            $shadow = $entry['shadow'] ?? '';
            $this->assertRelativePhp($source, 'source');
            $this->assertRelativePhp($shadow, 'shadow');
            if (!str_starts_with($source, 'vendor/')
                || !str_starts_with($shadow, '.typephp/build/')
                || isset($targets[$shadow])
            ) {
                throw new ConfigurationException("invalid upstream generator mapping: {$source}");
            }
            $targets[$shadow] = true;
            $this->assertDigest($entry['sourceSha256'] ?? '');
            $this->assertDigest($entry['shadowSha256'] ?? '');
            $path = $this->inside($mirror, $source);
            if ($this->digestFile($path, $source) !== $entry['sourceSha256']) {
                throw new ConfigurationException("upstream generator source drift: {$source}");
            }
        }

        $generate();

        if (!is_dir($generatedRoot)
            || is_link($generatedRoot)
            || is_link($mirror . DIRECTORY_SEPARATOR . '.typephp')
        ) {
            throw new ConfigurationException('upstream generator did not create a safe output directory');
        }
        if (hash_file('sha256', $generatorFile) !== $generatorSha256) {
            throw new ConfigurationException('upstream generator changed during execution');
        }
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($generatedRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new ConfigurationException('upstream generator output contains a symlink');
            }
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $relative = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($mirror) + 1)
                );
                $actual[$relative] = true;
            }
        }
        $extra = array_diff_key($actual, $targets);
        if ($extra !== []) {
            throw new ConfigurationException(
                'upstream generator emitted undeclared PHP: ' . implode(', ', array_keys($extra))
            );
        }
        $missing = array_diff_key($targets, $actual);
        if ($missing !== []) {
            throw new ConfigurationException(
                'upstream generator omitted declared PHP: ' . implode(', ', array_keys($missing))
            );
        }

        $compilerInputs = $this->readCompilerInputs($mirror);
        ksort($expected, SORT_STRING);
        $manifest = [];
        foreach ($expected as $source => $entry) {
            if ($this->digestFile($this->inside($mirror, $source), $source)
                !== $entry['sourceSha256']
            ) {
                throw new ConfigurationException("upstream generator changed source: {$source}");
            }
            $shadow = $entry['shadow'];
            if (!isset($compilerInputs['sources'][$shadow])
                || !isset($compilerInputs['ignore'][$source])
            ) {
                throw new ConfigurationException(
                    "upstream generator compiler coverage is open: {$source} => {$shadow}"
                );
            }
            $shadowPath = $this->inside($mirror, $shadow);
            if ($this->digestFile($shadowPath, $shadow) !== $entry['shadowSha256']) {
                throw new ConfigurationException("upstream generator shadow drift: {$shadow}");
            }
            try {
                token_get_all((string) file_get_contents($shadowPath), TOKEN_PARSE);
            } catch (\ParseError $exception) {
                throw new ConfigurationException(
                    "upstream generator emitted invalid PHP: {$shadow}",
                    previous: $exception
                );
            }
            $manifest[] = [
                'path' => $source,
                'shadow' => $shadow,
                'sourceSha256' => $entry['sourceSha256'],
                'shadowSha256' => $entry['shadowSha256'],
            ];
        }
        return $manifest;
    }

    /**
     * @return array{sources:array<string,true>,ignore:array<string,true>}
     */
    private function readCompilerInputs(string $mirror): array
    {
        $path = $mirror . DIRECTORY_SEPARATOR . 'project.linux.yml';
        $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('upstream generator compiler manifest is missing');
        }
        $lists = ['sources' => [], 'ignore' => []];
        $section = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $contents)) as $line) {
            if ($line === 'sources:' || $line === 'ignore:') {
                $section = substr($line, 0, -1);
                continue;
            }
            if ($line !== '' && $line[0] !== ' ') {
                $section = null;
            }
            if ($section !== null && str_starts_with($line, '  - ')) {
                $item = substr($line, 4);
                if ($item === '' || isset($lists[$section][$item])) {
                    throw new ConfigurationException(
                        "upstream generator compiler manifest has invalid {$section} entries"
                    );
                }
                $lists[$section][$item] = true;
            }
        }
        return $lists;
    }

    private function isBuildMirror(string $path): bool
    {
        return str_contains(
            str_replace('\\', '/', $path),
            '/.webman-aot/build/'
        );
    }

    /**
     * @param array<string,array{version:string,reference:string}> $expected
     */
    private function assertPackages(string $mirror, array $expected): void
    {
        $lockPath = $mirror . DIRECTORY_SEPARATOR . 'composer.lock';
        $contents = is_file($lockPath) && !is_link($lockPath)
            ? file_get_contents($lockPath)
            : false;
        if (!is_string($contents) || $expected === []) {
            throw new ConfigurationException('upstream generator dependency lock is missing');
        }
        try {
            $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'upstream generator dependency lock is invalid',
                previous: $exception
            );
        }
        if (!is_array($lock)
            || !is_array($lock['packages'] ?? null)
            || !is_array($lock['packages-dev'] ?? [])
        ) {
            throw new ConfigurationException('upstream generator dependency lock is invalid');
        }
        $installed = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $installed[$package['name']] = $package;
            }
        }
        foreach ($expected as $name => $package) {
            $actual = $installed[$name] ?? null;
            if (!is_array($actual)
                || ($actual['version'] ?? null) !== $package['version']
                || ($actual['source']['reference'] ?? null) !== $package['reference']
            ) {
                throw new ConfigurationException("upstream generator dependency lock drift: {$name}");
            }
        }
    }

    private function assertRelativePhp(string $path, string $kind): void
    {
        if (preg_match('~^[A-Za-z0-9_./-]+\.php$~D', $path) !== 1
            || str_starts_with($path, '/')
            || str_contains($path, '//')
            || in_array('..', explode('/', $path), true)
        ) {
            throw new ConfigurationException("unsafe upstream generator {$kind} path: {$path}");
        }
    }

    private function assertDigest(string $sha256): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new ConfigurationException('invalid upstream generator digest');
        }
    }

    private function inside(string $mirror, string $relative): string
    {
        $path = $mirror . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($path);
        if (!is_string($real) || $real !== $path) {
            throw new ConfigurationException("upstream generator path missing or unsafe: {$relative}");
        }
        return $path;
    }

    private function digestFile(string $path, string $relative): string
    {
        $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
        if (!is_string($digest)) {
            throw new ConfigurationException("upstream generator file missing or unsafe: {$relative}");
        }
        return $digest;
    }
}
