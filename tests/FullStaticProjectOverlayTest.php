<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Toolchain\FullStaticProjectOverlay;

final class FullStaticProjectOverlayTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-overlay-' . bin2hex(random_bytes(8));
        $sysroot = $root . '/sysroot';
        $project = $root . '/project.linux.yml';
        try {
            foreach ([
                '/usr/include/c++/12.2.1/vector',
                '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl/bits/c++config.h',
                '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/crtbegin.o',
                '/usr/lib/libstdc++.a',
            ] as $required) {
                $path = $sysroot . $required;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                file_put_contents($path, 'fixture');
            }
            $source = "name: webman-server\n\n"
                . "sources:\n  - main.php\n  - app\n"
                . "\nignore:\n  - config\n"
                . "\noutput: build/webman-server\n"
                . "mode: bin\noptimize: 2\njob: 4\ndebug: false\n";
            file_put_contents($project, $source);
            (new FullStaticProjectOverlay())->apply($project, $sysroot);
            $result = (string) file_get_contents($project);
            $this->assert(str_starts_with($result, $source), 'overlay changed generator output');
            $this->assert(
                str_contains($result, "\nbuild-dir: build\n")
                && str_contains($result, 'target-platform: x86_64-unknown-linux-musl')
                && str_contains($result, '--sysroot="' . str_replace('\\', '/', $sysroot) . '"')
                && str_contains($result, '-fuse-ld=lld'),
                'overlay omitted locked static target flags'
            );
            $example = $root . '/vendor/workerman/crontab/example/test.php';
            mkdir(dirname($example), 0700, true);
            $exampleSource = <<<'PHP'
<?php
use Workerman\Worker;
require __DIR__ . '/../vendor/autoload.php';

use Workerman\Crontab\Crontab;

$worker = new Worker();

$worker->onWorkerStart = function () {
    // Execute the function in the first second of every minute.
    new Crontab('1 * * * * *', function(){
        echo date('Y-m-d H:i:s')."\n";
    });
};

Worker::runAll();
PHP;
            file_put_contents($example, $exampleSource . "\n");
            file_put_contents($root . '/composer.lock', json_encode([
                'packages' => [[
                    'name' => 'workerman/crontab',
                    'version' => 'v1.0.7',
                    'source' => ['reference' => '74f51ca8204e8eb628e57bc0e640561d570da2cb'],
                ]],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($project, $source);
            (new FullStaticProjectOverlay())->apply($project, $sysroot, 'saiadmin');
            $this->assert(
                str_contains(
                    (string) file_get_contents($project),
                    "\n  - vendor/workerman/crontab/example/test.php\n"
                ),
                'locked third-party example was not excluded from compilation'
            );
            file_put_contents($example, $exampleSource . "// drift\n");
            file_put_contents($project, $source);
            $this->fails(
                fn () => (new FullStaticProjectOverlay())->apply($project, $sysroot, 'saiadmin'),
                'example exclusion drifted'
            );
            file_put_contents($example, $exampleSource . "\n");
            file_put_contents($project, $result);
            $this->fails(
                fn () => (new FullStaticProjectOverlay())->apply($project, $sysroot),
                'structure drifted'
            );

            file_put_contents($project, str_replace("job: 4\n", "job: 4\njob: 8\n", $source));
            $this->fails(
                fn () => (new FullStaticProjectOverlay())->apply($project, $sysroot),
                'duplicate TypePHP project key'
            );
            file_put_contents($project, $source);
            unlink($sysroot . '/usr/lib/libstdc++.a');
            $this->fails(
                fn () => (new FullStaticProjectOverlay())->apply($project, $sysroot),
                'incomplete'
            );
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
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
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected overlay failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("overlay did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
