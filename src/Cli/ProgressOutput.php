<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

final class ProgressOutput
{
    private readonly bool $interactive;
    private int $width = 0;
    private bool $visible = false;

    public function __construct(private readonly mixed $stream)
    {
        $this->interactive = function_exists('stream_isatty') && stream_isatty($stream);
    }

    public function update(string $message): void
    {
        if ($this->interactive) {
            fwrite($this->stream, "\r" . $message . str_repeat(' ', max(0, $this->width - strlen($message))));
            fflush($this->stream);
            $this->width = strlen($message);
            $this->visible = true;
            return;
        }

        fwrite($this->stream, $message . PHP_EOL);
    }

    public function message(string $message): void
    {
        $this->finish();
        fwrite($this->stream, $message . PHP_EOL);
    }

    public function finish(): void
    {
        if ($this->interactive && $this->visible) {
            fwrite($this->stream, PHP_EOL);
            fflush($this->stream);
            $this->width = 0;
            $this->visible = false;
        }
    }
}
