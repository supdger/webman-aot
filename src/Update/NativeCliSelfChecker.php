<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Platform\UserDirectoryLayout;

final class NativeCliSelfChecker implements CliSelfChecker
{
    public function __construct(private readonly UserDirectoryLayout $layout)
    {
    }

    public function check(string $appRoot, string $expectedVersion): bool
    {
        $entry = $appRoot . '/bin/webman-aot.php';
        if (!is_file($entry)) {
            return false;
        }
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['WEBMAN_AOT_HOME'] = $this->layout->root();
        $environment['WEBMAN_AOT_BOOTSTRAPPED'] = '1';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $entry, '--version'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $appRoot,
            $environment
        );
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $exitCode === 0
            && is_string($stdout)
            && rtrim($stdout, "\r\n") === 'webman-aot ' . $expectedVersion;
    }
}
