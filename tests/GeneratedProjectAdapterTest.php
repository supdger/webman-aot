<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\GeneratedProjectAdapter;

final class GeneratedProjectAdapterTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-generated-adapter-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        $workerRelative = '.typephp/build/workerman-worker.php';
        $installerRelative = 'vendor/workerman/webman-framework/src/support/Plugin.php';
        $workerFile = $mirror . '/' . $workerRelative;
        $installerFile = $mirror . '/' . $installerRelative;
        $projectFile = $mirror . '/project.linux.yml';
        mkdir(dirname($workerFile), 0700, true);
        mkdir(dirname($installerFile), 0700, true);
        $worker = "<?php\nclass Worker {\n"
            . '$startFileDir = dirname(static::$startFile);' . "\n"
            . '$file = __DIR__ . "/../../$unique_prefix.pid";' . "\n"
            . "function writeStatisticsToStatusFile() {\n"
            . "\$loadavg = function_exists('sys_getloadavg')"
            . " ? array_map(round(...), sys_getloadavg(), [2, 2, 2])"
            . " : ['-', '-', '-'];\n}\n}\n";
        $installer = "<?php\nrequire_once __DIR__ . '/helpers.php';\n"
            . "class Plugin {\npublic static function install(\$event) {}\n"
            . "public static function update(\$event) {}\n"
            . "public static function uninstall(\$event) {}\n}\n";
        $project = "sources:\n  - {$workerRelative}\nignore:\n"
            . "  - vendor/workerman/webman-framework/src/support/helpers.php\n";
        file_put_contents($workerFile, $worker);
        file_put_contents($installerFile, $installer);
        file_put_contents($projectFile, $project);
        $expectedWorker = str_replace(
            [
                '$file = __DIR__ . "/../../$unique_prefix.pid";',
                'array_map(round(...), sys_getloadavg(), [2, 2, 2])',
            ],
            [
                '$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";',
                "array_map(round(...), call_user_func('sys_getloadavg'), [2, 2, 2])",
            ],
            $worker
        );
        $lock = [
            'packages' => [
                'workerman/workerman' => ['version' => 'v5.2.2'],
                'workerman/webman-framework' => ['version' => 'v2.2.4'],
            ],
            'mappings' => [
                'vendor/workerman/workerman/src/Worker.php' => [
                    'shadow' => $workerRelative,
                    'shadowSha256' => hash('sha256', $worker),
                    'adaptedShadowSha256' => hash('sha256', $expectedWorker),
                ],
            ],
            'installOnly' => [
                $installerRelative => [
                    'policy' => 'webman.composer-installer.v1',
                    'sourceSha256' => hash('sha256', $installer),
                ],
            ],
        ];
        $adapter = new GeneratedProjectAdapter();
        try {
            $this->fails(
                fn () => $adapter->apply($root, $lock),
                'isolated project mirror'
            );
            $result = $adapter->apply($mirror, $lock);
            $adaptedWorker = (string) file_get_contents($workerFile);
            $adaptedProject = (string) file_get_contents($projectFile);
            $this->assert(
                str_contains($adaptedWorker, '$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";')
                && !str_contains($adaptedWorker, '$file = __DIR__')
                && str_contains($adaptedWorker, "call_user_func('sys_getloadavg')")
                && !str_contains($adaptedWorker, "array_map(round(...), sys_getloadavg()")
                && $result['workerShadowSha256'] === hash('sha256', $adaptedWorker),
                'Worker shadow was not adapted exactly once'
            );
            $this->assert(
                substr_count($adaptedProject, "  - {$installerRelative}\n") === 1
                && hash('sha256', $installer) === $result['installOnlySourceSha256']
                && file_get_contents($installerFile) === $installer,
                'install-only source was modified or not excluded'
            );
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'source digest drifted');

            file_put_contents($workerFile, str_replace(
                '$file = __DIR__ . "/../../$unique_prefix.pid";',
                '$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";',
                $worker
            ));
            file_put_contents($projectFile, $project);
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'source digest drifted');
            file_put_contents($workerFile, $worker);
            file_put_contents($workerFile, $worker . $worker);
            $lock['mappings']['vendor/workerman/workerman/src/Worker.php']['shadowSha256']
                = hash_file('sha256', $workerFile);
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'found 2');
            file_put_contents($workerFile, $worker);
            $lock['mappings']['vendor/workerman/workerman/src/Worker.php']['shadowSha256']
                = hash('sha256', $worker);
            $lock['mappings']['vendor/workerman/workerman/src/Worker.php']['adaptedShadowSha256']
                = str_repeat('0', 64);
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'post-adaptation digest drifted');
            $lock['mappings']['vendor/workerman/workerman/src/Worker.php']['adaptedShadowSha256']
                = hash('sha256', $expectedWorker);
            $lock['packages']['workerman/workerman']['version'] = 'v5.2.3';
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'unsupported');
            $lock['packages']['workerman/workerman']['version'] = 'v5.2.2';
            $lock['packages']['workerman/webman-framework']['version'] = 'v2.2.5';
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'unsupported');
            $lock['packages']['workerman/webman-framework']['version'] = 'v2.2.4';
            file_put_contents($installerFile, $installer . "\n// drift\n");
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'source digest drifted');
            file_put_contents($installerFile, $installer);
            file_put_contents($projectFile, $project . $project);
            $this->fails(fn () => $adapter->apply($mirror, $lock), 'shape drifted');
        } finally {
            unlink($workerFile);
            unlink($installerFile);
            unlink($projectFile);
            rmdir(dirname($workerFile));
            rmdir($mirror . '/.typephp');
            rmdir(dirname($installerFile));
            rmdir($mirror . '/vendor/workerman/webman-framework/src');
            rmdir($mirror . '/vendor/workerman/webman-framework');
            rmdir($mirror . '/vendor/workerman');
            rmdir($mirror . '/vendor');
            rmdir($mirror);
            rmdir($root . '/.webman-aot/build');
            rmdir($root . '/.webman-aot');
            rmdir($root);
        }
    }

    private function fails(Closure $action, string $expected): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $expected),
                "unexpected adapter error: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("generated adapter did not fail: {$expected}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
