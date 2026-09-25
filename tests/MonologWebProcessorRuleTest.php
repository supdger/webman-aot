<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\MonologWebProcessorRule;

final class MonologWebProcessorRuleTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-monolog-rule-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        $relative = 'vendor/monolog/monolog/src/Monolog/Processor/WebProcessor.php';
        $file = $mirror . '/' . $relative;
        mkdir(dirname($file), 0700, true);
        $source = "<?php\nclass WebProcessor {\n"
            . '    public function __construct() { $this->serverData = &$_SERVER; }' . "\n"
            . "}\n";
        $adapted = str_replace('&$_SERVER;', '&$GLOBALS[\'_SERVER\'];', $source);
        $policy = [
            'path' => $relative,
            'version' => '2.11.1',
            'reference' => str_repeat('a', 40),
            'sourceSha256' => hash('sha256', $source),
            'adaptedSha256' => hash('sha256', $adapted),
            'rule' => 'monolog.web-processor-global-reference.v1',
        ];
        $package = [
            'name' => 'monolog/monolog',
            'version' => '2.11.1',
            'source' => ['reference' => str_repeat('a', 40)],
        ];
        $rule = new MonologWebProcessorRule();
        try {
            file_put_contents($mirror . '/composer.lock', json_encode([
                'packages' => [],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            $this->assert($rule->apply($mirror, $policy) === null, 'absent Monolog should not adapt');
            file_put_contents($mirror . '/composer.lock', json_encode([
                'packages' => [$package],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($file, $source);
            $this->assert(
                $rule->apply($mirror, $policy) === hash('sha256', $adapted)
                && file_get_contents($file) === $adapted,
                'bounded Monolog adaptation failed'
            );
            $this->fails(fn () => $rule->apply($mirror, $policy), 'source structure drifted');
            file_put_contents($file, $source . $source);
            $policy['sourceSha256'] = hash_file('sha256', $file);
            $this->fails(fn () => $rule->apply($mirror, $policy), 'source structure drifted');
            file_put_contents($file, $source);
            $policy['sourceSha256'] = hash('sha256', $source);
            $package['version'] = '2.11.2';
            file_put_contents($mirror . '/composer.lock', json_encode([
                'packages' => [$package],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            $this->fails(fn () => $rule->apply($mirror, $policy), 'package or rule drifted');
            $package['version'] = '2.11.1';
            $package['source']['reference'] = str_repeat('b', 40);
            file_put_contents($mirror . '/composer.lock', json_encode([
                'packages' => [$package],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            $this->fails(fn () => $rule->apply($mirror, $policy), 'package or rule drifted');
        } finally {
            unlink($file);
            unlink($mirror . '/composer.lock');
            foreach ([
                dirname($file),
                $mirror . '/vendor/monolog/monolog/src/Monolog',
                $mirror . '/vendor/monolog/monolog/src',
                $mirror . '/vendor/monolog/monolog',
                $mirror . '/vendor/monolog',
                $mirror . '/vendor',
                $mirror,
                $root . '/.webman-aot/build',
                $root . '/.webman-aot',
                $root,
            ] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(str_contains($exception->getMessage(), $message), $exception->getMessage());
            return;
        }
        throw new RuntimeException("Monolog rule did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
