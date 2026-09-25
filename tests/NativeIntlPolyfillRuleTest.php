<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\NativeIntlPolyfillRule;

final class NativeIntlPolyfillRuleTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-intl-rule-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        $names = ['grapheme', 'idn', 'normalizer'];
        $packages = [];
        $shadows = [];
        $sources = "sources:\n";
        $ignore = "ignore:\n";
        foreach ($names as $name) {
            $bootstrap = "vendor/symfony/polyfill-intl-{$name}/bootstrap80.php";
            $shadow = ".typephp/build/symfony-{$name}-functions.php";
            mkdir(dirname($mirror . '/' . $bootstrap), 0700, true);
            if (!is_dir(dirname($mirror . '/' . $shadow))) {
                mkdir(dirname($mirror . '/' . $shadow), 0700, true);
            }
            $bootstrapSource = "<?php\n// {$name} native fallback\n";
            $shadowSource = $name === 'grapheme'
                ? "<?php\nuse Symfony\\Polyfill\\Intl\\Grapheme as p;\n"
                    . "    function grapheme_strlen(\$s) { return 1; }\n"
                    . "    function grapheme_levenshtein(\$s) { return 2; }\n"
                    . "    function grapheme_strrev(\$s) { return 3; }\n"
                : "<?php\nfunction {$name}_native() {}\n";
            file_put_contents($mirror . '/' . $bootstrap, $bootstrapSource);
            file_put_contents($mirror . '/' . $shadow, $shadowSource);
            $packages["symfony/polyfill-intl-{$name}"] = [
                'version' => 'v1.0.0',
                'reference' => str_repeat($name[0], 40),
                'bootstrapSha256' => hash('sha256', $bootstrapSource),
            ];
            $shadows[$name] = ['sourceSha256' => hash('sha256', $shadowSource)];
            $sources .= "  - {$shadow}\n";
            $ignore .= "  - {$bootstrap}\n";
        }
        $project = $sources . $ignore . "output: build/server\n";
        $adapted = "<?php\n\nuse Symfony\\Polyfill\\Intl\\Grapheme as p;\n\n"
            . "    function grapheme_levenshtein(\$s) { return 2; }\n\n"
            . "    function grapheme_strrev(\$s) { return 3; }\n";
        $policy = [
            'rule' => 'symfony.native-intl-polyfill.v1',
            'packages' => $packages,
            'shadows' => $shadows,
            'adaptedGraphemeSha256' => hash('sha256', $adapted),
        ];
        $lock = ['packages' => [], 'packages-dev' => []];
        foreach ($packages as $name => $package) {
            $lock['packages'][] = [
                'name' => $name,
                'version' => $package['version'],
                'source' => ['reference' => $package['reference']],
            ];
        }
        file_put_contents($mirror . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        file_put_contents($mirror . '/project.linux.yml', $project);
        $rule = new NativeIntlPolyfillRule();
        try {
            $result = $rule->apply($mirror, $policy);
            $this->assert($result['shadowSha256'] === hash('sha256', $adapted), 'grapheme shadow digest');
            $this->assert(
                file_get_contents($mirror . '/.typephp/build/symfony-grapheme-functions.php') === $adapted,
                'only absent native grapheme functions must remain'
            );
            $adaptedProject = (string) file_get_contents($mirror . '/project.linux.yml');
            $this->assert(!str_contains($adaptedProject, "  - .typephp/build/symfony-idn-functions.php\n")
                && !str_contains($adaptedProject, "  - .typephp/build/symfony-normalizer-functions.php\n"),
                'native intl shadows must not compile');
            $this->fails(fn () => $rule->apply($mirror, $policy), 'source drifted');

            file_put_contents($mirror . '/.typephp/build/symfony-grapheme-functions.php',
                "<?php\nuse Symfony\\Polyfill\\Intl\\Grapheme as p;\n"
                . "    function grapheme_strrev(\$s) { return 3; }\n"
            );
            $policy['shadows']['grapheme']['sourceSha256'] = hash_file(
                'sha256', $mirror . '/.typephp/build/symfony-grapheme-functions.php'
            );
            file_put_contents($mirror . '/project.linux.yml', $project);
            $this->fails(fn () => $rule->apply($mirror, $policy), 'function drifted');

            $lock['packages'][0]['version'] = 'v2.0.0';
            file_put_contents($mirror . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
            $this->fails(fn () => $rule->apply($mirror, $policy), 'package drifted');
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
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
        throw new RuntimeException("native intl rule did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
