<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Platform\UserDirectoryLayout;

final class RunLogger
{
    private function __construct(
        private readonly string $runId,
        private readonly string $directory,
        private readonly string $eventsPath,
        private readonly string $humanPath
    ) {
    }

    public static function open(UserDirectoryLayout $layout): self
    {
        $runId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $logs = $layout->path('logs');
        self::createDirectory($logs);
        $directory = $logs . '/' . $runId;
        self::createDirectory($directory);

        return new self(
            $runId,
            $directory,
            $directory . '/events.jsonl',
            $directory . '/run.log'
        );
    }

    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function event(
        string $level,
        string $event,
        ?string $stage,
        string $message,
        array $context = []
    ): void {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'timestamp' => $timestamp,
            'level' => $level,
            'event' => $event,
            'stage' => $stage,
            'message' => $message,
            'context' => $context,
        ];
        $json = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $human = sprintf(
            "%s %-5s [%s] %s\n",
            $timestamp,
            strtoupper($level),
            $stage ?? 'runtime',
            $message
        );
        if (file_put_contents($this->eventsPath, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('unable to append structured run log');
        }
        if (file_put_contents($this->humanPath, $human, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('unable to append human-readable run log');
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function eventsPath(): string
    {
        return $this->eventsPath;
    }

    public function humanPath(): string
    {
        return $this->humanPath;
    }

    private static function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException("unable to create private run directory: {$directory}");
        }
    }
}
