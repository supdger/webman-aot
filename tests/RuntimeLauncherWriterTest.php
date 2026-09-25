<?php

declare(strict_types=1);

use WebmanAot\Project\RuntimeLauncherWriter;

final class RuntimeLauncherWriterTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman aot launch-' . bin2hex(random_bytes(8));
        mkdir($root . '/config', 0700, true);
        file_put_contents($root . '/config/app.php', "<?php return [];\n");
        $server = <<<'SHELL'
#!/bin/sh
value=$(sed -n 's/^VALUE=//p' .env)
printf '%s|%s|%s\n' "$PWD" "$*" "$value"
SHELL;
        file_put_contents($root . '/server', $server . "\n");
        chmod($root . '/server', 0755);
        file_put_contents($root . '/.env', "VALUE=before\n");
        try {
            $scripts = (new RuntimeLauncherWriter())->write($root);
            if (PHP_OS_FAMILY === 'Windows') {
                $this->assert(
                    str_contains((string) file_get_contents($scripts['start']), 'exec ./server start')
                    && str_contains((string) file_get_contents($scripts['stop']), 'exec ./server stop'),
                    'Linux launchers were not generated on Windows'
                );
                echo "[PASS] Windows build host generates relative Linux launchers\n";
                return;
            }
            $this->assert(
                is_executable($scripts['start']) && is_executable($scripts['stop']),
                'relative runtime launchers were not executable'
            );
            [$status, $output] = $this->runScript($scripts['start']);
            $this->assert(
                $status === 0 && $output === "{$root}|start|before\n",
                'foreground start did not use the candidate directory'
            );
            file_put_contents($root . '/.env', "VALUE=after\n");
            [$status, $output] = $this->runScript($scripts['start'], ['--daemon']);
            $this->assert(
                $status === 0 && $output === "{$root}|start -d|after\n",
                'restart did not consume changed external .env'
            );
            [$status, $output] = $this->runScript($scripts['stop']);
            $this->assert(
                $status === 0 && $output === "{$root}|stop|after\n",
                'stop did not address the candidate server'
            );
            [$status] = $this->runScript($scripts['start'], ['invalid']);
            $this->assert($status === 2, 'launcher accepted an unrecognized mode');
            echo "[PASS] relative launchers honor external .env changes and reject unsafe modes\n";
        } finally {
            foreach (['start.sh', 'stop.sh', 'server', '.env', 'config/app.php'] as $relative) {
                if (is_file($root . '/' . $relative)) {
                    unlink($root . '/' . $relative);
                }
            }
            rmdir($root . '/config');
            rmdir($root);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int,string}
     */
    private function runScript(string $script, array $arguments = []): array
    {
        $process = proc_open(
            array_merge([$script], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('test launcher did not start');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('test launcher output was not readable');
        }
        return [$status, $stdout];
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
