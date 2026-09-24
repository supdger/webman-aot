<?php

declare(strict_types=1);

namespace WebmanAot\Doctor;

use WebmanAot\Toolchain\LockValidator;
use WebmanAot\Toolchain\HostComponentSelector;

final class Doctor
{
    public const MINIMUM_FREE_BYTES = 10 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly string $lockPath,
        private readonly string $artifactsDirectory,
        private readonly string $projectDirectory,
        private readonly SystemProbe $system,
        private readonly int $minimumFreeBytes = self::MINIMUM_FREE_BYTES
    ) {
    }

    public function inspect(): DoctorReport
    {
        $checks = [];
        $host = $this->system->hostId();
        $supported = in_array($host, ['macos-arm64', 'windows-x86_64'], true);
        $checks[] = $this->check(
            'platform',
            $supported,
            $supported ? "supported build host {$host}" : "unsupported build host {$host}",
            ['host' => $host]
        );

        $freeBytes = $this->system->freeBytes($this->artifactsDirectory);
        $checks[] = $this->check(
            'disk',
            $freeBytes >= $this->minimumFreeBytes,
            $freeBytes >= $this->minimumFreeBytes
                ? 'sufficient free space'
                : 'insufficient free space',
            [
                'freeBytes' => $freeBytes,
                'minimumBytes' => $this->minimumFreeBytes,
            ]
        );

        $lock = $this->readLock($checks);
        if ($lock !== null) {
            $this->inspectNetwork($lock, $checks);
            $this->inspectArtifacts($lock, $host, $checks);
        }
        $this->inspectProject($checks);

        return new DoctorReport($host, $checks);
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     * @return array<string, mixed>|null
     */
    private function readLock(array &$checks): ?array
    {
        $contents = @file_get_contents($this->lockPath);
        if (!is_string($contents)) {
            $checks[] = $this->check(
                'toolchain-lock',
                false,
                'toolchain lock is missing',
                ['path' => basename($this->lockPath)]
            );
            return null;
        }
        try {
            $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $checks[] = $this->check(
                'toolchain-lock',
                false,
                'toolchain lock is not valid JSON',
                ['error' => $exception->getMessage()]
            );
            return null;
        }
        if (!is_array($lock)) {
            $checks[] = $this->check('toolchain-lock', false, 'toolchain lock root is invalid');
            return null;
        }
        $errors = (new LockValidator())->validate($lock);
        $checks[] = $this->check(
            'toolchain-lock',
            $errors === [],
            $errors === [] ? 'toolchain lock is valid' : 'toolchain lock validation failed',
            ['errors' => $errors]
        );

        return $errors === [] ? $lock : null;
    }

    /**
     * @param array<string, mixed> $lock
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectNetwork(array $lock, array &$checks): void
    {
        $urls = [];
        foreach ($lock['components'] ?? [] as $component) {
            if (is_array($component) && is_string($component['sourceUrl'] ?? null)) {
                $url = $component['sourceUrl'];
                $host = parse_url($url, PHP_URL_HOST);
                if (is_string($host) && !isset($urls[$host])) {
                    $urls[$host] = $url;
                }
            }
        }
        ksort($urls, SORT_STRING);
        $unreachable = [];
        foreach ($urls as $host => $url) {
            if (!$this->system->canReach($url)) {
                $unreachable[] = $host;
            }
        }
        $checks[] = $this->check(
            'network',
            $unreachable === [],
            $unreachable === [] ? 'all toolchain source hosts are reachable' : 'toolchain source hosts are unreachable',
            [
                'checkedHosts' => array_keys($urls),
                'unreachableHosts' => $unreachable,
            ]
        );
    }

    /**
     * @param array<string, mixed> $lock
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectArtifacts(array $lock, string $host, array &$checks): void
    {
        $components = (new HostComponentSelector())->select(
            is_array($lock['components'] ?? null) ? $lock['components'] : [],
            $host
        );
        foreach ($components as $component) {
            $id = (string) $component['id'];
            $urlPath = parse_url((string) $component['sourceUrl'], PHP_URL_PATH);
            $filename = is_string($urlPath) ? basename($urlPath) : '';
            $path = $this->artifactsDirectory . '/' . $filename;
            if (!is_file($path)) {
                $checks[] = $this->check(
                    'component:' . $id,
                    false,
                    'locked component is missing',
                    [
                        'version' => (string) $component['version'],
                        'artifact' => $filename,
                        'expectedSha256' => (string) $component['sha256'],
                    ]
                );
                continue;
            }
            $actual = hash_file('sha256', $path);
            $valid = is_string($actual) && hash_equals((string) $component['sha256'], $actual);
            $checks[] = $this->check(
                'component:' . $id,
                $valid,
                $valid ? 'locked component digest matches' : 'locked component digest mismatch',
                [
                    'version' => (string) $component['version'],
                    'artifact' => $filename,
                    'expectedSha256' => (string) $component['sha256'],
                    'actualSha256' => is_string($actual) ? $actual : null,
                ]
            );
        }
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectProject(array &$checks): void
    {
        $required = ['composer.json', 'composer.lock', 'start.php'];
        $missing = [];
        foreach ($required as $relativePath) {
            if (!is_file($this->projectDirectory . '/' . $relativePath)) {
                $missing[] = $relativePath;
            }
        }
        if ($missing !== []) {
            $checks[] = $this->check(
                'project',
                false,
                'Webman project structure is incomplete',
                ['missing' => $missing]
            );
            return;
        }

        try {
            $lock = json_decode(
                (string) file_get_contents($this->projectDirectory . '/composer.lock'),
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $checks[] = $this->check(
                'project',
                false,
                'project composer.lock is invalid',
                ['error' => $exception->getMessage()]
            );
            return;
        }
        $webmanVersion = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'workerman/webman-framework') {
                $webmanVersion = $package['version'] ?? null;
                break;
            }
        }
        $valid = is_string($webmanVersion) && $webmanVersion !== '';
        $checks[] = $this->check(
            'project',
            $valid,
            $valid ? 'Webman project detected' : 'workerman/webman-framework is absent from composer.lock',
            ['webmanVersion' => $valid ? $webmanVersion : null]
        );
    }

    /**
     * @param array<string, mixed> $details
     * @return array{id:string,status:string,message:string,details:array<string,mixed>}
     */
    private function check(
        string $id,
        bool $healthy,
        string $message,
        array $details = []
    ): array {
        return [
            'id' => $id,
            'status' => $healthy ? 'ok' : 'error',
            'message' => $message,
            'details' => $details,
        ];
    }
}
