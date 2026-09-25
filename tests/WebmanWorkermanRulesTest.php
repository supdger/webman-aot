<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\RuleEngine;
use WebmanAot\Compatibility\WebmanWorkermanRules;
use WebmanAot\Project\ProjectWorkspace;

final class WebmanWorkermanRulesTest
{
    public function run(): void
    {
        $rules = WebmanWorkermanRules::knownRules();
        $this->assert(count($rules) === 19, 'unexpected known Webman/Workerman rule set');
        $versions = [
            'workerman/webman-framework' => 'v2.2.4',
            'workerman/workerman' => 'v5.2.2',
        ];
        $sources = [
            'vendor/workerman/webman-framework/src/App.php' =>
                "<?php\nclass App {\npublic static function handler() {\n"
                . "\$allowHeader = implode(', ', ['GET']);\n"
                . "return static function () use (\$allowHeader) {\n"
                . "return new Response(405, ['Allow' => \$allowHeader], '405 Method Not Allowed');\n};\n}\n"
                . "public static function getFallback() {\n"
                . "return Route::getFallback(\$plugin, \$status) ?: function () {\n"
                . "throw new PageNotFoundException();\n};\n}\n"
                . "public static function findFile() {\n"
                . "static::collectCallbacks(\$key, [function () use (\$file) {\n"
                . "return static::execPhpFile(\$file);\n}]);\n}\n"
                . "protected static function stringify(\$data): string {\n"
                . "switch (gettype(\$data)) {\ncase 'object':\n"
                . "                if (!method_exists(\$data, '__toString')) {\n"
                . "                    return 'Object';\n"
                . "                }\n"
                . "            default:\n"
                . "return (string)\$data;\n}\n}\n}\n",
            'vendor/workerman/webman-framework/src/File.php' =>
                "<?php\nclass File {\npublic function move(string \$destination): File {\n"
                . "set_error_handler(function (\$type, \$msg) use (&\$error) {\n"
                . "\$error = \$msg;\n});\nreturn \$this;\n}\n}\n",
            'vendor/workerman/webman-framework/src/Config.php' =>
                "<?php\nclass Config {\n"
                . "public static function loadFromDir(string \$configPath): array {\n"
                . "\$file = new SplFileInfo('config.php');\n"
                . "if (is_dir(\$file) || false) { return []; }\n"
                . "\$path = substr(\$file, 0, -4);\n"
                . "\$config = include \$file;\n"
                . "\$config = include \$file;\n"
                . "return (array) \$config;\n}\n}\n",
            'vendor/workerman/workerman/src/Timer.php' =>
                "<?php\nclass Timer {\npublic static function init(?EventInterface \$event = null): void {\n"
                . "pcntl_signal(SIGALRM, self::signalHandle(...), false);\n}\n"
                . "public static function signalHandle(): void {}\n}\n",
            'vendor/workerman/workerman/src/Events/Select.php' =>
                "<?php\nclass Select {\npublic function onSignal(int \$signal, callable \$func): void {\n"
                . "\$this->signalEvents[\$signal] = \$func;\n"
                . "pcntl_signal(\$signal, fn () => \$this->safeCall(\$this->signalEvents[\$signal], [\$signal]));\n"
                . "}\n}\n",
            'vendor/workerman/workerman/src/Worker.php' =>
                "<?php\nclass Worker {\npublic static function run(): void {\n"
                . "\$startFileDir = dirname(static::\$startFile);\n"
                . "\$file = __DIR__ . \"/../../\$unique_prefix.pid\";\n"
                . "function writeStatisticsToStatusFile() {\n"
                . "\$loadavg = function_exists('sys_getloadavg')"
                . " ? array_map(round(...), sys_getloadavg(), [2, 2, 2])"
                . " : ['-', '-', '-'];\n}\n"
                . str_repeat("set_error_handler(static fn (): bool => true);\n", 7)
                . "set_error_handler(function (\$code, \$msg) {});\n"
                . "array_walk(\$workers, static fn (Worker \$worker) => \$worker->stop(false));\n"
                . "array_walk(\$workerPidArray, static fn (\$pid) => posix_kill(\$pid, \$sig));\n"
                . "pcntl_signal(\$signal, static::signalHandler(...), false);\n"
                . "restore_error_handler();\n}\n"
                . "protected static function signalHandler(int \$signal): void {}\n}\n",
            'vendor/workerman/workerman/src/Connection/TcpConnection.php' =>
                "<?php\nclass TcpConnection {\npublic function handshake(): void {\n"
                . "set_error_handler(static function (int \$code, string \$msg): bool { return true; });\n"
                . "stream_socket_enable_crypto(\$socket, true, \$type);\n}\n}\n",
            'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php' =>
                "<?php\nclass AsyncTcpConnection {\nconst STATUS_CONNECTING = 1;\n"
                . "public function connect(): void {\nset_error_handler(fn() => false);\n}\n}\n",
        ];
        $project = sys_get_temp_dir() . '/webman-aot-core-rules-' . bin2hex(random_bytes(8));
        mkdir($project, 0700, true);
        foreach ($sources as $path => $source) {
            $absolute = $project . '/' . $path;
            if (!is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0700, true);
            }
            file_put_contents($absolute, $source);
        }
        $workspace = new ProjectWorkspace($project);
        $build = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64))['build'];
        $engine = new RuleEngine();
        try {
            $manifest = $engine->apply($project, $build, $rules, $versions);
            $this->assert(count($manifest) === 8, 'known rules did not create eight shadows');
            foreach ($manifest as $item) {
                $this->assert(
                    hash_file('sha256', $project . '/' . $item['path']) === $item['sourceSha256']
                    && hash_file('sha256', $item['shadowPath']) === $item['shadowSha256'],
                    "known rule changed source or shadow digest: {$item['path']}"
                );
                token_get_all((string) file_get_contents($item['shadowPath']), TOKEN_PARSE);
            }
            $this->assert(
                $manifest === $engine->apply($project, $build, $rules, $versions),
                'known Webman/Workerman rules are not deterministic'
            );
            foreach ($rules as $rule) {
                $path = $rule->sourcePath();
                $source = $sources[$path];
                $this->fails(
                    fn () => $rule->transform(str_replace($rule->searchText(), 'unrelated', $source), $versions[$rule->dependency()]),
                    'found 0'
                );
                $this->fails(
                    fn () => $rule->transform($source . $source, $versions[$rule->dependency()]),
                    'found ' . ($rule->expectedHits() * 2)
                );
                $this->fails(
                    fn () => $rule->transform($source, 'v99.0.0'),
                    'unsupported'
                );
            }
        } finally {
            $workspace->remove();
            foreach (array_keys($sources) as $path) {
                unlink($project . '/' . $path);
            }
            foreach ([
                'vendor/workerman/webman-framework/src',
                'vendor/workerman/webman-framework',
                'vendor/workerman/workerman/src/Events',
                'vendor/workerman/workerman/src/Connection',
                'vendor/workerman/workerman/src',
                'vendor/workerman/workerman',
                'vendor/workerman',
                'vendor',
            ] as $path) {
                rmdir($project . '/' . $path);
            }
            rmdir($project);
        }
    }

    private function fails(Closure $action, string $expected): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $expected),
                "unexpected known-rule error: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("known rule did not fail: {$expected}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
