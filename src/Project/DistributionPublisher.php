<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class DistributionPublisher
{
    /** @var \Closure(string,string):bool */
    private readonly \Closure $move;

    /**
     * @param (\Closure(string,string):bool)|null $move
     */
    public function __construct(
        private readonly string $projectDirectory,
        ?\Closure $move = null
    ) {
        $this->move = $move ?? static fn (string $from, string $to): bool => rename($from, $to);
    }

    /**
     * The verifier must inspect the complete candidate before the existing
     * distribution is touched. A prior distribution is retained for rollback.
     *
     * @param \Closure(string):void $verify
     * @return array{path:string,previous:?string}
     */
    public function publish(string $candidateDirectory, \Closure $verify): array
    {
        $project = realpath($this->projectDirectory);
        $candidate = realpath($candidateDirectory);
        $build = is_string($project)
            ? realpath($project . '/.webman-aot/build')
            : false;
        if (!is_string($project)
            || !is_string($candidate)
            || !is_string($build)
            || is_link($project . '/.webman-aot')
            || is_link($candidateDirectory)
            || dirname($candidate) !== $build
            || preg_match('/^dist-candidate-[a-f0-9]{16}$/D', basename($candidate)) !== 1
            || !$this->ownedWorkspace($project)
        ) {
            throw new ConfigurationException('distribution candidate is outside the owned build workspace');
        }
        $this->assertMarked($candidate);
        $destination = $project . '/dist-aot';
        if (is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
            throw new ConfigurationException('existing distribution path is unsafe');
        }
        if (is_dir($destination)) {
            $this->assertMarked($destination);
        }

        $verify($candidate);

        if (!is_dir($destination)) {
            if (!($this->move)($candidate, $destination)) {
                throw new ConfigurationException('unable to publish verified distribution');
            }
            return ['path' => $destination, 'previous' => null];
        }

        $releases = $project . '/.webman-aot/releases';
        if (is_link($releases)
            || (!is_dir($releases) && !mkdir($releases, 0700))
        ) {
            throw new ConfigurationException('distribution rollback directory is unsafe');
        }
        $previous = $releases . '/previous-' . bin2hex(random_bytes(8));
        if (!($this->move)($destination, $previous)) {
            throw new ConfigurationException('unable to preserve previous distribution');
        }
        try {
            if (!($this->move)($candidate, $destination)) {
                throw new ConfigurationException('unable to publish verified distribution');
            }
        } catch (\Throwable $failure) {
            if (!($this->move)($previous, $destination)) {
                throw new ConfigurationException(
                    "distribution rollback requires recovery from {$previous}",
                    previous: $failure
                );
            }
            throw $failure;
        }

        return ['path' => $destination, 'previous' => $previous];
    }

    private function ownedWorkspace(string $project): bool
    {
        $metadata = $project . '/.webman-aot/workspace.json';
        $contents = is_file($metadata) && !is_link($metadata)
            ? file_get_contents($metadata)
            : false;
        if (!is_string($contents)) {
            return false;
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($decoded)
            && ($decoded['schema'] ?? null) === 'webman-aot-project-workspace-v1';
    }

    private function assertMarked(string $directory): void
    {
        $path = $directory . '/manifest.json';
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('distribution manifest is missing or unsafe');
        }
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $manifest = null;
        }
        if (!is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'webman-aot-distribution-v1'
        ) {
            throw new ConfigurationException('distribution manifest marker is invalid');
        }
    }
}
