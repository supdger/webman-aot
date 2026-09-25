<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Toolchain\ElfStaticVerifier;

final class DistributionVerifier
{
    /** @var \Closure(string):string */
    private readonly \Closure $ldd;

    /**
     * @param (\Closure(string):string)|null $ldd
     */
    public function __construct(?\Closure $ldd = null)
    {
        $this->ldd = $ldd ?? $this->inspectLdd(...);
    }

    /**
     * @param list<string> $sensitiveMarkers
     * @return array{files:int,compiledDirect:int,compiledShadow:int,ldd:string}
     */
    public function verify(
        string $distributionDirectory,
        array $sensitiveMarkers = [],
        bool $strictMutable = true
    ): array {
        $root = realpath($distributionDirectory);
        if (!is_string($root) || $root === DIRECTORY_SEPARATOR
            || !is_dir($root) || is_link($distributionDirectory)
        ) {
            throw new ConfigurationException('distribution verification requires a concrete directory');
        }
        $manifest = $this->readJson($root . '/manifest.json', 'manifest');
        if (($manifest['schema'] ?? null) !== 'webman-aot-distribution-v1'
            || ($manifest['target'] ?? null) !== [
                'os' => 'linux',
                'architecture' => 'x86_64',
                'libc' => 'musl',
            ]
            || !is_array($manifest['files'] ?? null)
            || !is_array($manifest['inputs'] ?? null)
            || !is_array($manifest['writableDirectories'] ?? null)
        ) {
            throw new ConfigurationException('distribution manifest shape is invalid');
        }
        $canonicalInputs = $this->sortRecursively($manifest['inputs']);
        $inputJson = json_encode(
            $canonicalInputs,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (($manifest['inputSha256'] ?? null) !== hash('sha256', $inputJson)) {
            throw new ConfigurationException('distribution normalized input digest drifted');
        }
        $expected = $manifest['files'];
        foreach (['server', 'start.sh', 'stop.sh', 'coverage.json', 'resources.json'] as $path) {
            if (!isset($expected[$path])) {
                throw new ConfigurationException("distribution manifest omits required file: {$path}");
            }
        }
        $resources = $this->readJson($root . '/resources.json', 'resources');
        if (($resources['schema'] ?? null) !== 'webman-aot-runtime-resources-v1'
            || !is_array($resources['entries'] ?? null)
        ) {
            throw new ConfigurationException('distribution resource manifest is invalid');
        }
        $resourceManifest = new RuntimeResourceManifest($resources['entries']);
        $externalFiles = [];
        $writableDirectories = [];
        foreach ($resourceManifest->entries() as $resource) {
            if (!is_array($resource) || !is_string($resource['path'] ?? null)) {
                throw new ConfigurationException('distribution resource path is invalid');
            }
            $this->assertRelativePath($resource['path']);
            if (($resource['kind'] ?? null) === 'external-file') {
                $externalFiles[$resource['path']] = true;
            } elseif (($resource['kind'] ?? null) === 'writable-directory') {
                $writableDirectories[] = $resource['path'];
            }
        }
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr(
                $file->getPathname(),
                strlen($root) + 1
            ));
            if ($file->isLink()) {
                throw new ConfigurationException("distribution contains symlink: {$path}");
            }
            if ($file->isFile()) {
                $actual[$path] = true;
            }
        }
        unset($actual['manifest.json']);
        $extra = array_diff_key($actual, $expected);
        if (!$strictMutable) {
            foreach (array_keys($extra) as $path) {
                if (isset($externalFiles[$path])
                    || $this->insideWritableDirectory($path, $writableDirectories)
                ) {
                    unset($extra[$path]);
                }
            }
        }
        if ($extra !== []) {
            throw new ConfigurationException(
                'distribution contains unmanaged files: '
                . implode(', ', array_keys($extra))
            );
        }
        if (array_diff_key($expected, $actual) !== []) {
            throw new ConfigurationException(
                'distribution is missing managed files: '
                . implode(', ', array_keys(array_diff_key($expected, $actual)))
            );
        }
        foreach ($expected as $path => $entry) {
            $this->assertRelativePath($path);
            if (!is_array($entry)
                || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1
                || !is_bool($entry['mutable'] ?? null)
                || !in_array($entry['mode'] ?? null, ['0644', '0755'], true)
            ) {
                throw new ConfigurationException("distribution file record is invalid: {$path}");
            }
            $absolute = $root . '/' . $path;
            if ($strictMutable || !$entry['mutable']) {
                if (hash_file('sha256', $absolute) !== $entry['sha256']) {
                    throw new ConfigurationException(
                        "distribution file digest mismatch: {$path}"
                    );
                }
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                $permissions = fileperms($absolute);
                $mode = is_int($permissions)
                    ? sprintf('%04o', $permissions & 0777)
                    : '';
                if ($mode !== $entry['mode']) {
                    throw new ConfigurationException(
                        "distribution file mode mismatch: {$path}"
                    );
                }
            }
            if (in_array($path, ['server', 'start.sh', 'stop.sh'], true)
                && PHP_OS_FAMILY !== 'Windows'
                && !is_executable($absolute)
            ) {
                throw new ConfigurationException(
                    "distribution launcher is not executable: {$path}"
                );
            }
        }
        foreach ($manifest['writableDirectories'] as $path) {
            if (!is_string($path)) {
                throw new ConfigurationException('distribution writable directory is invalid');
            }
            $this->assertRelativePath($path);
            if (!is_dir($root . '/' . $path)
                || is_link($root . '/' . $path)
            ) {
                throw new ConfigurationException(
                    "distribution writable directory is missing: {$path}"
                );
            }
        }

        (new ElfStaticVerifier())->assertFullyStaticX86_64($root . '/server');
        $coverage = $this->readJson($root . '/coverage.json', 'coverage');
        $counts = $this->verifyCoverage($coverage);
        (new DistributionLeakScanner())->scan(
            $root,
            $resourceManifest,
            $sensitiveMarkers,
            $strictMutable
        );
        return [
            'files' => count($actual),
            'compiledDirect' => $counts['direct'],
            'compiledShadow' => $counts['shadow'],
            'ldd' => ($this->ldd)($root . '/server'),
        ];
    }

    /**
     * @param list<string> $directories
     */
    private function insideWritableDirectory(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $coverage
     * @return array{direct:int,shadow:int}
     */
    private function verifyCoverage(array $coverage): array
    {
        if (($coverage['schema'] ?? null) !== 'webman-aot-coverage-ledger-v1'
            || !is_array($coverage['files'] ?? null)
            || $coverage['files'] === []
        ) {
            throw new ConfigurationException('distribution coverage ledger is invalid');
        }
        $counts = ['direct' => 0, 'shadow' => 0];
        $seen = [];
        foreach ($coverage['files'] as $record) {
            $path = is_array($record) ? ($record['path'] ?? null) : null;
            if (!is_string($path) || isset($seen[$path])) {
                throw new ConfigurationException('distribution coverage path is invalid or duplicate');
            }
            $this->assertRelativePath($path);
            $seen[$path] = true;
            if (($record['category'] ?? null) !== ProjectDiscovery::BUSINESS_PHP) {
                continue;
            }
            $status = $record['status'] ?? null;
            if ($status === CoverageLedger::COMPILED_DIRECT) {
                $counts['direct']++;
            } elseif ($status === CoverageLedger::COMPILED_SHADOW
                && is_string($record['replacement'] ?? null)
                && is_string($record['replacementSha256'] ?? null)
            ) {
                $counts['shadow']++;
            } else {
                throw new ConfigurationException(
                    "distribution business PHP is not AOT-covered: {$path}"
                );
            }
        }
        return $counts;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $description): array
    {
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException(
                "distribution {$description} is missing or unsafe"
            );
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                "distribution {$description} is invalid JSON",
                previous: $exception
            );
        }
        if (!is_array($decoded)) {
            throw new ConfigurationException("distribution {$description} is invalid");
        }
        return $decoded;
    }

    private function assertRelativePath(string $path): void
    {
        $parts = explode('/', $path);
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || in_array('', $parts, true)
            || in_array('.', $parts, true)
            || in_array('..', $parts, true)
        ) {
            throw new ConfigurationException("distribution path is unsafe: {$path}");
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortRecursively(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        return $value;
    }

    private function inspectLdd(string $server): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'not-run-on-build-host';
        }
        $process = proc_open(
            ['ldd', $server],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new ConfigurationException('target ldd verification could not start');
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1])
            . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (!str_contains($output, 'not a dynamic executable')
            && !str_contains($output, 'statically linked')
        ) {
            throw new ConfigurationException(
                'target ldd did not confirm a static executable'
            );
        }
        return 'static';
    }
}
