<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Guided;

final class ProcessRunner
{
    public function __construct(private readonly string $log)
    {
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $stage, ?string $cwd = null, array $environment = [], bool $machineOutput = false): array
    {
        $started = microtime(true);
        fwrite(STDOUT, "[开始] {$stage}\n");
        $handle = fopen($this->log, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('无法写入日志：' . $this->log);
        }
        fwrite($handle, "\n[{$stage}] " . json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, array_merge(getenv(), $environment), ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($handle);
            throw new \RuntimeException("无法启动阶段：{$stage}");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = [1 => '', 2 => ''];
        $lastOutput = microtime(true);
        $exitCode = -1;
        while (true) {
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    $output[$index] .= $chunk;
                    if (!$machineOutput) {
                        $output[$index] = substr($output[$index], -65536);
                    }
                    fwrite($handle, $chunk);
                    fflush($handle);
                    if (!$machineOutput || $index === 2) {
                        fwrite($index === 1 ? STDOUT : STDERR, $chunk);
                    }
                    $lastOutput = microtime(true);
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if (is_string($chunk) && $chunk !== '') {
                        $output[$index] .= $chunk;
                        if (!$machineOutput) {
                            $output[$index] = substr($output[$index], -65536);
                        }
                        fwrite($handle, $chunk);
                        if (!$machineOutput || $index === 2) {
                            fwrite($index === 1 ? STDOUT : STDERR, $chunk);
                        }
                    }
                }
                break;
            }
            if (microtime(true) - $lastOutput >= 5) {
                fwrite(STDOUT, sprintf("[进行中] %s，已耗时 %.0f 秒\n", $stage, microtime(true) - $started));
                $lastOutput = microtime(true);
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closedCode;
        }
        $summary = sprintf("[%s] %s，耗时 %.1f 秒，退出码 %d\n", $exitCode === 0 ? '成功' : '失败', $stage, microtime(true) - $started, $exitCode);
        fwrite(STDOUT, $summary);
        fwrite($handle, $summary);
        fclose($handle);
        return ['code' => $exitCode, 'stdout' => $output[1], 'stderr' => $output[2]];
    }
}
