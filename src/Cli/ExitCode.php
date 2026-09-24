<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

final class ExitCode
{
    public const SUCCESS = 0;
    public const USAGE = 64;
    public const UNAVAILABLE = 69;
    public const SOFTWARE = 70;
    public const CONFIGURATION = 78;

    public static function forFailure(\Throwable $throwable): int
    {
        return match (true) {
            $throwable instanceof UsageException => self::USAGE,
            $throwable instanceof UnavailableException => self::UNAVAILABLE,
            $throwable instanceof ConfigurationException => self::CONFIGURATION,
            default => self::SOFTWARE,
        };
    }
}
