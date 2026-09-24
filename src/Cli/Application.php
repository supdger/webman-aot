<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Version;

final class Application
{
    public function __construct(private readonly UserDirectoryLayout $layout)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $command = $arguments[1] ?? 'help';

        if (in_array($command, ['help', '-h', '--help'], true)) {
            $this->writeHelp();
            return 0;
        }

        if (in_array($command, ['version', '-V', '--version'], true)) {
            fwrite(STDOUT, 'webman-aot ' . Version::VALUE . PHP_EOL);
            return 0;
        }

        fwrite(STDERR, "Unknown command: {$command}" . PHP_EOL);
        fwrite(STDERR, "Run 'webman-aot help' to see available commands." . PHP_EOL);
        return 64;
    }

    private function writeHelp(): void
    {
        $lines = [
            'Webman AOT ' . Version::VALUE,
            '',
            'Usage:',
            '  webman-aot <command>',
            '',
            'Commands:',
            '  help       Show this help',
            '  version    Show the CLI version',
            '',
            'User data:',
            '  ' . $this->layout->root(),
            '',
            'The target Webman project does not need an AOT Composer plugin.',
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
