<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Compatibility\PluginSourceCompletion;

final class PluginSourceCompletionTest
{
    public function run(string $repository): void
    {
        foreach ([
            'app/controller/PingController.php'
                => '2ecd9bee003e8e88c883de4ff5994ed8f48d3546c7f12586cd8f2e78de51f69e',
            'config/route.php'
                => '371964b67ac2fc1da2910535a9a9ecfce31afaa4c4c44e586790989df27f0b27',
        ] as $relative => $digest) {
            $this->assert(
                hash_file(
                    'sha256',
                    $repository . '/tests/fixtures/neutral-plugin/plugin/neutral/' . $relative
                ) === $digest,
                "runtime-tested neutral plugin fixture drifted: {$relative}"
            );
        }
        $root = sys_get_temp_dir() . '/webman-aot-plugin-sources-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        mkdir($mirror . '/app', 0700, true);
        mkdir($mirror . '/vendor/workerman/webman-framework/src', 0700, true);
        mkdir($mirror . '/plugin/neutral/app/controller', 0700, true);
        mkdir($mirror . '/plugin/neutral/config', 0700, true);
        file_put_contents($mirror . '/app/Health.php', "<?php class Health {}\n");
        file_put_contents(
            $mirror . '/plugin/neutral/app/controller/Zed.php',
            "<?php class Zed {}\n"
        );
        file_put_contents(
            $mirror . '/plugin/neutral/app/controller/Alpha.php',
            "<?php class Alpha {}\n"
        );
        file_put_contents($mirror . '/plugin/neutral/Support.php', "<?php class PluginSupport {}\n");
        file_put_contents(
            $mirror . '/plugin/neutral/config/route.php',
            "<?php return [];\n"
        );
        file_put_contents($mirror . '/plugin/neutral/Install.php', "<?php class Install {}\n");
        $projectFile = $mirror . '/project.linux.yml';
        $base = "name: webman-server\n\nsources:\n"
            . "  - app\n\nignore:\n  - config\n\noutput: build/webman-server\n";
        file_put_contents($projectFile, $base);
        $completion = new PluginSourceCompletion();
        try {
            $this->fails(
                fn () => $completion->apply($root, 'webman', []),
                'isolated project mirror'
            );
            $digest = $completion->apply($mirror, 'webman', []);
            $paths = [
                'plugin/neutral/Support.php',
                'plugin/neutral/app/controller/Alpha.php',
                'plugin/neutral/app/controller/Zed.php',
            ];
            $expected = str_replace(
                "\nignore:\n",
                "\n  - {$paths[0]}\n  - {$paths[1]}\n  - {$paths[2]}\nignore:\n",
                $base
            );
            $this->assert(
                file_get_contents($projectFile) === $expected
                && $digest === hash(
                    'sha256',
                    json_encode($paths, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                ),
                'new plugin business files were not added exactly once in stable order'
            );
            $this->assert(
                $completion->apply($mirror, 'webman', []) === null
                && file_get_contents($projectFile) === $expected,
                'already covered plugin sources changed on a second pass'
            );
            $covered = str_replace(
                "  - app\n",
                "  - app\n  - plugin/neutral\n",
                $base
            );
            file_put_contents($projectFile, $covered);
            $this->assert(
                $completion->apply($mirror, 'webman', []) === null
                && file_get_contents($projectFile) === $covered,
                'an upstream plugin source directory was duplicated'
            );

            file_put_contents($projectFile, str_replace(
                "\nignore:\n",
                "\nignore:\n  - plugin/neutral/app/controller\n",
                $base
            ));
            $this->fails(
                fn () => $completion->apply($mirror, 'webman', []),
                'cannot be added'
            );
            file_put_contents($projectFile, $base . "\nsources:\n  - app\n");
            $this->fails(
                fn () => $completion->apply($mirror, 'webman', []),
                'structure drifted'
            );
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
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
                "unexpected plugin source error: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("plugin source completion did not fail: {$expected}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
