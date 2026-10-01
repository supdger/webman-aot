<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Process
{
    /** @param list<string> $command @param array<string,string>|null $environment */
    public static function run(array $command, ?string $cwd = null, ?array $environment = null): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动所需系统命令：' . $command[0]);
        }
        return proc_close($process);
    }

    /** @param list<string> $command */
    public static function output(array $command): string
    {
        $process = proc_open($command, [STDIN, ['pipe', 'w'], STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法检查归档：' . $command[0]);
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new \RuntimeException('归档检查失败；未执行包内代码。');
        }
        return $output;
    }
}
