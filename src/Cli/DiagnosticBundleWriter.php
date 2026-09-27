<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Version;

final class DiagnosticBundleWriter
{
    public function __construct(private readonly RunLogger $logger)
    {
    }

    public function write(
        string $command,
        string $failedStage,
        int $exitCode,
        \Throwable $throwable,
        StageTracker $tracker
    ): string {
        $path = $this->logger->directory() . '/diagnostic.json';
        $diagnostic = [
            'schema' => 'webman-aot-diagnostic-v1',
            'runId' => $this->logger->runId(),
            'version' => Version::VALUE,
            'command' => $command,
            'failedStage' => $failedStage,
            'exitCode' => $exitCode,
            'failure' => [
                'type' => $throwable::class,
                'message' => $throwable->getMessage(),
            ],
            'host' => [
                'osFamily' => PHP_OS_FAMILY,
                'architecture' => php_uname('m'),
            ],
            'stages' => $tracker->snapshot(),
            'logs' => [
                'structured' => basename($this->logger->eventsPath()),
                'human' => basename($this->logger->humanPath()),
            ],
        ];
        $encoded = json_encode(
            $diagnostic,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('unable to write diagnostic bundle');
        }

        return $path;
    }
}
