<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

use WebmanAot\Cli\ConfigurationException;

final class BoundedTextRule implements CompatibilityRule
{
    /**
     * @param list<string> $requiredBefore
     * @param list<string> $requiredAfter
     */
    public function __construct(
        private readonly string $id,
        private readonly string $dependency,
        private readonly string $sourcePath,
        private readonly VersionRange $versions,
        private readonly array $requiredBefore,
        private readonly string $needle,
        private readonly string $replacement,
        private readonly int $expectedHits,
        private readonly array $requiredAfter
    ) {
        if ($id === '' || $dependency === '' || $sourcePath === ''
            || $needle === '' || $expectedHits < 1 || $needle === $replacement
        ) {
            throw new ConfigurationException('invalid bounded compatibility rule');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function dependency(): string
    {
        return $this->dependency;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function transform(string $source, string $version): string
    {
        $hits = substr_count($source, $this->needle);
        $this->versions->assertSupported(
            $version,
            $this->id,
            $this->dependency,
            $this->sourcePath,
            $hits
        );
        foreach ($this->requiredBefore as $marker) {
            if ($marker === '' || !str_contains($source, $marker)) {
                throw new ConfigurationException(
                    "compatibility rule {$this->id}: source structure drift in {$this->sourcePath} "
                    . "at {$version}, found {$hits} hits"
                );
            }
        }
        if ($hits !== $this->expectedHits) {
            throw new ConfigurationException(
                "compatibility rule {$this->id}: expected {$this->expectedHits} hits "
                . "in {$this->sourcePath} at {$version}, found {$hits}"
            );
        }
        $output = str_replace($this->needle, $this->replacement, $source);
        foreach ($this->requiredAfter as $marker) {
            if ($marker === '' || !str_contains($output, $marker)) {
                throw new ConfigurationException(
                    "compatibility rule {$this->id}: postcondition failed in {$this->sourcePath} "
                    . "at {$version}, found {$hits} hits"
                );
            }
        }
        return $output;
    }
}
