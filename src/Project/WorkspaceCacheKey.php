<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class WorkspaceCacheKey
{
    public function create(
        ProjectProfile $profile,
        CoverageLedger $ledger,
        string $toolchainSha256,
        string $rulesSha256
    ): string {
        foreach ([
            'toolchain' => $toolchainSha256,
            'rules' => $rulesSha256,
        ] as $name => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new ConfigurationException("invalid {$name} digest for workspace cache key");
            }
        }
        $packages = $profile->packages();
        ksort($packages, SORT_STRING);
        $files = $ledger->files();
        usort(
            $files,
            static fn(array $left, array $right): int => $left['path'] <=> $right['path']
        );
        $payload = json_encode([
            'schema' => 'webman-aot-workspace-cache-key-v1',
            'profile' => $profile->name(),
            'packages' => $packages,
            'coverage' => $files,
            'toolchainSha256' => $toolchainSha256,
            'rulesSha256' => $rulesSha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $payload);
    }
}
