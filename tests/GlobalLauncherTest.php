<?php

declare(strict_types=1);

use WebmanAot\Platform\UserDirectoryLayout;

final class GlobalLauncherTest
{
    public function run(string $root): void
    {
        $project = $root . '/tests/fixtures/minimal-webman';
        $composer = json_decode(
            (string) file_get_contents($project . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $dependencies = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );
        $this->assert(
            !array_key_exists('webman-aot/webman-aot', $dependencies)
            && !array_key_exists('tinywan/webman-typephp', $dependencies),
            'minimal Webman fixture must not install an AOT Composer plugin'
        );

        $temporaryRoot = sys_get_temp_dir() . '/webman-aot-launcher-' . bin2hex(random_bytes(8));
        try {
            $this->assertLayout($temporaryRoot);
            $this->assertPhpEntrypoint($root, $project, $temporaryRoot);
            if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
                $this->assertMacLauncher($root, $project, $temporaryRoot);
            }
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    private function assertLayout(string $temporaryRoot): void
    {
        $previous = getenv('WEBMAN_AOT_HOME');
        putenv('WEBMAN_AOT_HOME=' . $temporaryRoot);
        try {
            $paths = UserDirectoryLayout::detect()->paths();
        } finally {
            if (is_string($previous)) {
                putenv('WEBMAN_AOT_HOME=' . $previous);
            } else {
                putenv('WEBMAN_AOT_HOME');
            }
        }

        $expectedRoot = str_replace('\\', '/', $temporaryRoot);
        $this->assert($paths['home'] === $expectedRoot, 'private user home path drifted');
        foreach (['current', 'versions', 'toolchains', 'artifacts', 'cache', 'logs', 'tmp'] as $name) {
            $this->assert(
                str_starts_with($paths[$name], $expectedRoot . '/'),
                "private user path escaped its root: {$name}"
            );
        }
    }

    private function assertPhpEntrypoint(string $root, string $project, string $temporaryRoot): void
    {
        $environment = $this->environmentWithHome($temporaryRoot);
        $version = $this->runProcess(
            [PHP_BINARY, $root . '/bin/webman-aot.php', '--version'],
            $project,
            $environment
        );
        $this->assert($version['exitCode'] === 0, 'PHP CLI version command failed');
        $this->assert($version['stdout'] === "webman-aot 0.1.0-dev\n", 'PHP CLI version output drifted');
        $this->assert($version['stderr'] === '', 'PHP CLI version command wrote to stderr');

        $help = $this->runProcess(
            [PHP_BINARY, $root . '/bin/webman-aot.php', '--help'],
            $project,
            $environment
        );
        $this->assert($help['exitCode'] === 0, 'PHP CLI help command failed');
        $this->assert(str_contains($help['stdout'], 'Usage:'), 'PHP CLI help lacks usage');
        $this->assert(
            str_contains($help['stdout'], 'does not need an AOT Composer plugin'),
            'PHP CLI help must preserve project independence'
        );
    }

    private function assertMacLauncher(string $root, string $project, string $temporaryRoot): void
    {
        $runtime = $temporaryRoot . '/current/runtime/bin';
        $application = $temporaryRoot . '/current/app';
        foreach ([$runtime, $application . '/bin'] as $directory) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException("unable to create staged launcher directory: {$directory}");
            }
        }
        if (!symlink(PHP_BINARY, $runtime . '/php')) {
            throw new RuntimeException('unable to stage private PHP runtime link');
        }
        if (!copy($root . '/bin/webman-aot.php', $application . '/bin/webman-aot.php')) {
            throw new RuntimeException('unable to stage launcher application entrypoint');
        }
        $this->copyDirectory($root . '/src', $application . '/src');

        $result = $this->runProcess(
            ['/bin/sh', $root . '/bin/webman-aot', 'version'],
            $project,
            $this->environmentWithHome($temporaryRoot)
        );
        $this->assert($result['exitCode'] === 0, 'macOS global launcher version command failed');
        $this->assert($result['stdout'] === "webman-aot 0.1.0-dev\n", 'macOS launcher output drifted');
        $this->assert($result['stderr'] === '', 'macOS launcher wrote to stderr');
    }

    /**
     * @return array<string, string>
     */
    private function environmentWithHome(string $home): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['WEBMAN_AOT_HOME'] = $home;

        return $environment;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $directory, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $directory,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('unable to start CLI test process');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new RuntimeException("unable to create staged application directory: {$destination}");
        }
        $items = new DirectoryIterator($source);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }
            $target = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("unable to stage application file: {$target}");
            }
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
