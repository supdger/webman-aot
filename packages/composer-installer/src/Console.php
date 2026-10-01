<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Console
{
    public static function unattended(): bool
    {
        foreach (['COMPOSER_NO_INTERACTION', 'CI'] as $name) {
            if (in_array(strtolower((string) getenv($name)), ['1', 'true', 'yes'], true)) { return true; }
        }
        return false;
    }

    /** Restore only an explicitly requested guide in an already attached console. */
    public static function restore(array $argv): ?int
    {
        if (self::unattended() || getenv('WEBMAN_AOT_GUIDE_CONSOLE_DEPTH') === '1') {
            return null;
        }
        $devices = match (PHP_OS_FAMILY) {
            'Windows' => ['CONIN$', 'CONOUT$'],
            'Darwin' => ['/dev/tty', '/dev/tty'],
            default => null,
        };
        if ($devices === null) { return null; }
        $input = @fopen($devices[0], 'rb');
        $output = @fopen($devices[1], 'r+b');
        try {
            if (!is_resource($input) || !is_resource($output)
                || !stream_isatty($input) || !stream_isatty($output)) {
                return null;
            }
            $entry = dirname(__DIR__) . '/bin/webman-aot';
            if (!is_file($entry) || is_link($entry)) {
                throw new \RuntimeException('本 Composer 入口文件不可用；请重新安装 supdger/webman-aot-builder。');
            }
            $environment = getenv();
            if (!is_array($environment)) { $environment = []; }
            $environment['WEBMAN_AOT_GUIDE_CONSOLE_DEPTH'] = '1';
            $process = proc_open([PHP_BINARY, $entry, ...array_slice($argv, 1)],
                [$input, $output, $output], $pipes, getcwd() ?: null, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new \RuntimeException('无法在当前终端启动项目引导。');
            }
            return proc_close($process);
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
        }
    }
}
