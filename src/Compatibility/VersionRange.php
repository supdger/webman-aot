<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;

final class VersionRange
{
    public function __construct(
        private readonly string $minimum,
        private readonly string $maximumExclusive
    ) {
        if (!$this->isRelease($minimum) || !$this->isRelease($maximumExclusive)
            || version_compare(ltrim($minimum, 'v'), ltrim($maximumExclusive, 'v'), '>=')
        ) {
            throw new ConfigurationException('invalid compatibility rule version range');
        }
    }

    public function assertSupported(
        string $version,
        string $ruleId,
        string $dependency,
        string $sourcePath,
        int $hits
    ): void {
        $normalized = ltrim($version, 'v');
        if (!$this->isRelease($version)
            || version_compare($normalized, ltrim($this->minimum, 'v'), '<')
            || version_compare($normalized, ltrim($this->maximumExclusive, 'v'), '>=')
        ) {
            throw new ConfigurationException(
                "compatibility rule {$ruleId}: unsupported {$dependency} version {$version} "
                . "in {$sourcePath}, found {$hits} hits; "
                . "expected [{$this->minimum}, {$this->maximumExclusive})"
            );
        }
    }

    private function isRelease(string $version): bool
    {
        return preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $version) === 1;
    }
}
