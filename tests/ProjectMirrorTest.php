<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\ProjectMirror;
use WebmanAot\Project\ProjectWorkspace;
use WebmanAot\Project\SourceTreeSnapshot;

final class ProjectMirrorTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-mirror-' . bin2hex(random_bytes(8));
        mkdir($root . '/app/Controller', 0700, true);
        mkdir($root . '/vendor/workerman/workerman/src', 0700, true);
        mkdir($root . '/runtime/logs', 0700, true);
        mkdir($root . '/public/storage', 0700, true);
        mkdir($root . '/plugin/saiadmin/public/export', 0700, true);
        mkdir($root . '/plugin/saiadmin/public/template', 0700, true);
        file_put_contents($root . '/composer.json', "{}\n");
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/start.php', "<?php\n");
        file_put_contents($root . '/app/Controller/Health.php', "<?php\n// source\n");
        file_put_contents($root . '/vendor/workerman/workerman/src/Worker.php', "<?php\n");
        file_put_contents($root . '/.env', "SECRET=never-copy\n");
        file_put_contents($root . '/runtime/logs/current.log', "private log\n");
        file_put_contents($root . '/public/app.css', "body {}\n");
        file_put_contents($root . '/public/storage/upload.txt', "private upload\n");
        file_put_contents(
            $root . '/plugin/saiadmin/public/export/private.xlsx',
            "private generated export\n"
        );
        file_put_contents(
            $root . '/plugin/saiadmin/public/template/template.xlsx',
            "static template\n"
        );
        try {
            $snapshot = new SourceTreeSnapshot($root);
            $before = $snapshot->capture();
            $workspace = new ProjectWorkspace($root);
            $paths = $workspace->prepare(str_repeat('1', 64), $before['sha256']);
            $mirror = (new ProjectMirror($root))->create($paths['build']);
            $this->assert(
                $mirror['sourceSha256'] === $before['sha256']
                && $mirror['files'] === 7
                && preg_match('/^[a-f0-9]{64}$/D', $mirror['sha256']) === 1,
                'mirror metadata is incomplete'
            );
            $this->assert(
                is_file($mirror['path'] . '/app/Controller/Health.php')
                && is_file($mirror['path'] . '/vendor/workerman/workerman/src/Worker.php')
                && is_file($mirror['path'] . '/public/app.css')
                && is_file($mirror['path'] . '/plugin/saiadmin/public/template/template.xlsx')
                && !file_exists($mirror['path'] . '/.env')
                && !file_exists($mirror['path'] . '/runtime')
                && !file_exists($mirror['path'] . '/public/storage')
                && !file_exists($mirror['path'] . '/plugin/saiadmin/public/export'),
                'mirror omitted sources or copied private runtime/upload data'
            );
            file_put_contents($root . '/public/storage/upload.txt', "changed private upload\n");
            file_put_contents(
                $root . '/plugin/saiadmin/public/export/private.xlsx',
                "changed private generated export\n"
            );
            file_put_contents($root . '/.env', "SECRET=changed-without-rebuild\n");
            $this->assert(
                $snapshot->capture() === $before,
                'private environment, uploads, or exports changed the build source snapshot'
            );
            file_put_contents($mirror['path'] . '/app/Controller/Health.php', "<?php\n// AOT copy\n");
            $this->assert(
                file_get_contents($root . '/app/Controller/Health.php') === "<?php\n// source\n"
                && $snapshot->capture() === $before,
                'mirror mutation changed project sources'
            );
            $this->fails(
                fn () => (new ProjectMirror($root))->create($paths['build']),
                'already exists'
            );
            $this->fails(
                fn () => (new ProjectMirror($root))->create($root),
                'owned build workspace'
            );
            $workspace->cleanTransient();
            $this->assert(!file_exists($mirror['path']), 'transient cleanup left the mirror');
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

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected mirror failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("mirror did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
