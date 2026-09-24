<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\CoverageLedger;
use WebmanAot\Project\ProjectProfile;
use WebmanAot\Project\ProjectWorkspace;
use WebmanAot\Project\SourceTreeSnapshot;
use WebmanAot\Project\WorkspaceCacheKey;

final class ProjectWorkspaceTest
{
    public function run(): void
    {
        $this->assertWorkspaceDoesNotChangeSources();
        $this->assertUnmarkedWorkspaceIsNotCleaned();
    }

    private function assertWorkspaceDoesNotChangeSources(): void
    {
        $fixture = $this->fixture();
        try {
            $snapshot = new SourceTreeSnapshot($fixture);
            $before = $snapshot->capture();
            $profile = new ProjectProfile(
                ProjectProfile::WEBMAN,
                ['workerman/webman-framework' => 'v2.2.4'],
                ['fixture']
            );
            $sourceSha256 = (string) hash_file('sha256', $fixture . '/app/Controller.php');
            $ledger = new CoverageLedger([[
                'path' => 'app/Controller.php',
                'sourceSha256' => $sourceSha256,
                'category' => 'business-php',
                'owner' => 'project',
                'status' => CoverageLedger::COMPILED_DIRECT,
                'policy' => null,
                'reason' => null,
            ]]);
            $toolchain = str_repeat('1', 64);
            $rules = str_repeat('2', 64);
            $keyFactory = new WorkspaceCacheKey();
            $key = $keyFactory->create($profile, $ledger, $toolchain, $rules);
            $this->assert(
                $key === $keyFactory->create($profile, $ledger, $toolchain, $rules),
                'workspace cache key is not deterministic'
            );
            $changedLedger = new CoverageLedger([[
                'path' => 'app/Controller.php',
                'sourceSha256' => str_repeat('3', 64),
                'category' => 'business-php',
                'owner' => 'project',
                'status' => CoverageLedger::COMPILED_DIRECT,
                'policy' => null,
                'reason' => null,
            ]]);
            $this->assert(
                $key !== $keyFactory->create($profile, $changedLedger, $toolchain, $rules),
                'workspace cache key ignored a source digest change'
            );

            $workspace = new ProjectWorkspace($fixture);
            $paths = $workspace->prepare($key, $before['sha256']);
            mkdir($paths['cacheEntry'], 0700, true);
            file_put_contents($paths['build'] . '/generated.php', "<?php\n");
            file_put_contents($paths['cacheEntry'] . '/object.o', "cache\n");
            file_put_contents($paths['runs'] . '/run.json', "{}\n");
            $this->assert(
                $snapshot->capture() === $before,
                'workspace preparation changed the project source snapshot'
            );

            $workspace->cleanTransient();
            $this->assert(
                $this->directoryIsEmpty($paths['build'])
                && $this->directoryIsEmpty($paths['runs']),
                'transient workspace cleanup did not empty build and runs'
            );
            $this->assert(
                is_file($paths['cacheEntry'] . '/object.o'),
                'transient cleanup removed the reusable cache'
            );
            $this->assert(
                $snapshot->capture() === $before,
                'workspace cleanup changed the project source snapshot'
            );

            $workspace->remove();
            $this->assert(!file_exists($paths['root']), 'workspace removal left the workspace root');
            $this->assert(
                $snapshot->capture() === $before,
                'workspace removal changed the project source snapshot'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertUnmarkedWorkspaceIsNotCleaned(): void
    {
        $fixture = $this->fixture();
        try {
            mkdir($fixture . '/.webman-aot/build', 0700, true);
            file_put_contents($fixture . '/.webman-aot/build/user-file.txt', "preserve\n");
            $workspace = new ProjectWorkspace($fixture);
            try {
                $workspace->prepare(str_repeat('1', 64), str_repeat('2', 64));
                throw new RuntimeException('unmarked workspace preparation was not rejected');
            } catch (ConfigurationException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'unmarked project workspace'),
                    "unexpected workspace preparation failure: {$exception->getMessage()}"
                );
            }
            try {
                $workspace->cleanTransient();
            } catch (ConfigurationException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'unmarked project workspace'),
                    "unexpected workspace safety failure: {$exception->getMessage()}"
                );
                $this->assert(
                    is_file($fixture . '/.webman-aot/build/user-file.txt'),
                    'unmarked workspace content was modified'
                );
                return;
            }
            throw new RuntimeException('unmarked workspace cleanup was not rejected');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function fixture(): string
    {
        $directory = sys_get_temp_dir() . '/webman-aot-workspace-' . bin2hex(random_bytes(8));
        mkdir($directory . '/app', 0700, true);
        mkdir($directory . '/vendor/example', 0700, true);
        file_put_contents($directory . '/composer.lock', "{}\n");
        file_put_contents($directory . '/app/Controller.php', "<?php\n");
        file_put_contents($directory . '/vendor/example/library.php', "<?php\n");

        return $directory;
    }

    private function directoryIsEmpty(string $directory): bool
    {
        return iterator_count(
            new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS)
        ) === 0;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
