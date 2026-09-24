<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\BoundedTextRule;
use WebmanAot\Compatibility\RuleEngine;
use WebmanAot\Compatibility\VersionRange;
use WebmanAot\Project\ProjectWorkspace;

final class CompatibilityRuleTest
{
    public function run(): void
    {
        $project = sys_get_temp_dir() . '/webman-aot-rule-' . bin2hex(random_bytes(8));
        mkdir($project . '/vendor/example', 0700, true);
        $sourcePath = $project . '/vendor/example/Widget.php';
        $source = "<?php\nclass Widget { public function run(\$value) { return \$value; } }\n";
        file_put_contents($sourcePath, $source);
        $workspace = new ProjectWorkspace($project);
        $paths = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64));
        $engine = new RuleEngine();
        $versions = ['example/widget' => 'v1.2.3'];
        $rule = $this->rule();
        try {
            $result = $engine->apply($project, $paths['build'], [$rule], $versions);
            $this->assert(count($result) === 1, 'compatibility shadow is missing');
            $this->assert(
                $result[0]['rules'] === ['example-widget-signature']
                && $result[0]['sourceSha256'] === hash('sha256', $source),
                'compatibility rule manifest lost provenance'
            );
            $shadow = $result[0]['shadowPath'];
            $this->assert(
                str_contains((string) file_get_contents($shadow), 'run(mixed $value)')
                && file_get_contents($sourcePath) === $source,
                'compatibility rule mutated source or failed to generate shadow'
            );
            $again = $engine->apply($project, $paths['build'], [$rule], $versions);
            $this->assert($result === $again, 'compatibility shadow is not deterministic');
            $this->fails(
                fn () => $engine->apply(
                    $project,
                    $paths['build'],
                    [$rule],
                    ['example/widget' => 'v2.0.0']
                ),
                [
                    'unsupported example/widget version v2.0.0',
                    'vendor/example/Widget.php',
                    'found 1 hits',
                ]
            );
            $this->fails(
                fn () => $engine->apply($project, $paths['build'], [$rule, $rule], $versions),
                'duplicate compatibility rule'
            );
            foreach ([
                ["<?php\nclass Widget { public function run(\$input) {} }\n", 'found 0'],
                [$source . $source, 'found 2'],
                ["<?php\nfunction run(\$value) {}\n", 'source structure drift'],
            ] as [$changed, $message]) {
                file_put_contents($sourcePath, $changed);
                $this->fails(
                    fn () => $engine->apply($project, $paths['build'], [$rule], $versions),
                    $message
                );
            }
            file_put_contents($sourcePath, $source);
            $badPostcondition = new BoundedTextRule(
                'bad-postcondition',
                'example/widget',
                'vendor/example/Widget.php',
                new VersionRange('1.0.0', '2.0.0'),
                ['class Widget'],
                'run($value)',
                'run(mixed $value)',
                1,
                ['nonexistent postcondition']
            );
            $this->fails(
                fn () => $engine->apply($project, $paths['build'], [$badPostcondition], $versions),
                'postcondition failed'
            );
            $this->assert(file_get_contents($sourcePath) === $source, 'failed rule changed source');
        } finally {
            $workspace->remove();
            unlink($sourcePath);
            rmdir($project . '/vendor/example');
            rmdir($project . '/vendor');
            rmdir($project);
        }
    }

    private function rule(): BoundedTextRule
    {
        return new BoundedTextRule(
            'example-widget-signature',
            'example/widget',
            'vendor/example/Widget.php',
            new VersionRange('1.0.0', '2.0.0'),
            ['class Widget', 'public function run'],
            'run($value)',
            'run(mixed $value)',
            1,
            ['run(mixed $value)']
        );
    }

    /**
     * @param string|list<string> $message
     */
    private function fails(Closure $action, string|array $message): void
    {
        $expected = is_array($message) ? $message : [$message];
        try {
            $action();
        } catch (ConfigurationException $exception) {
            foreach ($expected as $part) {
                $this->assert(
                    str_contains($exception->getMessage(), $part),
                    "unexpected rule failure: {$exception->getMessage()}"
                );
            }
            return;
        }
        throw new RuntimeException('compatibility rule did not fail: ' . implode(', ', $expected));
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
