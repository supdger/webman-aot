<?php

declare(strict_types=1);

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\Downloader;
use WebmanAot\Update\CliSelfChecker;
use WebmanAot\Update\CliVersionStore;
use WebmanAot\Update\PackageExtractor;
use WebmanAot\Update\SelfUpdater;
use WebmanAot\Update\VerifiedDownloader;
use WebmanAot\Update\ZipPackageExtractor;

final class SelfUpdaterTest
{
    public function run(string $root): void
    {
        $home = sys_get_temp_dir() . '/webman-aot-self-update-' . bin2hex(random_bytes(8));
        $previousHome = getenv('WEBMAN_AOT_HOME');
        try {
            putenv('WEBMAN_AOT_HOME=' . $home);
            $layout = UserDirectoryLayout::detect();
            $archiveContents = "fixture archive\n";
            $downloader = new VerifiedDownloader(new SelfUpdateFakeDownloader($archiveContents));
            $extractor = new SelfUpdateFakeExtractor($root);
            $checker = new SelfUpdateFakeChecker();
            $updater = new SelfUpdater(
                $layout,
                $root,
                '0.1.0-dev',
                $downloader,
                $extractor,
                $checker
            );
            $result = $updater->update([
                'version' => '0.2.0',
                'url' => 'https://example.com/webman-aot.zip',
                'sha256' => hash('sha256', $archiveContents),
            ], str_repeat('a', 64), 'release-1');
            $store = new CliVersionStore($layout);
            $active = $store->activeGeneration();
            $this->assert(is_string($active), 'successful CLI update did not activate a generation');
            $this->assert(
                basename($active) === $result['new'],
                'successful CLI update returned the wrong generation'
            );
            $this->assert(
                str_contains((string) file_get_contents($active . '/app/src/Version.php'), '0.2.0'),
                'successful CLI update did not promote candidate contents'
            );

            $beforeFailure = $active;
            $checker->failVersion('0.3.0');
            try {
                $updater->update([
                    'version' => '0.3.0',
                    'url' => 'https://example.com/webman-aot.zip',
                    'sha256' => hash('sha256', $archiveContents),
                ], str_repeat('b', 64), 'release-1');
                throw new RuntimeException('failed CLI self-check unexpectedly activated');
            } catch (UnavailableException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'self-check failed'),
                    'failed CLI self-check was not explicit'
                );
            }
            $this->assert(
                $store->activeGeneration() === $beforeFailure,
                'failed CLI self-check changed the active generation'
            );
            $this->assert(
                $this->candidateDirectories($home) === [],
                'failed CLI self-check left a candidate directory'
            );

            $interrupted = new SelfUpdater(
                $layout,
                $root,
                '0.1.0-dev',
                new VerifiedDownloader(new SelfUpdateInterruptingDownloader()),
                $extractor,
                $checker
            );
            try {
                $interrupted->update([
                    'version' => '0.4.0',
                    'url' => 'https://example.com/webman-aot.zip',
                    'sha256' => hash('sha256', $archiveContents),
                ], str_repeat('c', 64), 'release-1');
                throw new RuntimeException('interrupted CLI download unexpectedly activated');
            } catch (RuntimeException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'interrupted'),
                    'interrupted CLI download was not explicit'
                );
            }
            $this->assert(
                $store->activeGeneration() === $beforeFailure,
                'interrupted CLI download changed the active generation'
            );
            $rolledBack = $store->rollback();
            $this->assert(
                basename($rolledBack) === $result['previous'],
                'CLI rollback did not reveal the baseline generation'
            );

            $this->assertUnsafeZipRejected($home);
        } finally {
            if (is_string($previousHome)) {
                putenv('WEBMAN_AOT_HOME=' . $previousHome);
            } else {
                putenv('WEBMAN_AOT_HOME');
            }
            $this->removeDirectory($home);
        }
    }

    private function assertUnsafeZipRejected(string $home): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is required for CLI update tests');
        }
        $archive = $home . '/unsafe.zip';
        $destination = $home . '/unsafe-extract';
        $zip = new ZipArchive();
        $this->assert($zip->open($archive, ZipArchive::CREATE) === true, 'unable to create ZIP fixture');
        $zip->addFromString('../escape.php', '<?php');
        $zip->close();
        try {
            (new ZipPackageExtractor())->extract($archive, $destination);
            throw new RuntimeException('unsafe ZIP path unexpectedly extracted');
        } catch (UnavailableException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), 'unsafe'),
                'unsafe ZIP rejection was not explicit'
            );
        }
        $this->assert(!file_exists($home . '/escape.php'), 'unsafe ZIP escaped its destination');
    }

    /**
     * @return list<string>
     */
    private function candidateDirectories(string $home): array
    {
        $directories = glob($home . '/versions/.candidates/*', GLOB_ONLYDIR);

        return is_array($directories) ? $directories : [];
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
            if ($item->isDir()) {
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

final class SelfUpdateFakeDownloader implements Downloader
{
    public function __construct(private readonly string $contents)
    {
    }

    public function download(string $url, string $destination): void
    {
        file_put_contents($destination, $this->contents);
    }
}

final class SelfUpdateInterruptingDownloader implements Downloader
{
    public function download(string $url, string $destination): void
    {
        file_put_contents($destination, 'partial');
        throw new RuntimeException('fixture download interrupted');
    }
}

final class SelfUpdateFakeExtractor implements PackageExtractor
{
    public function __construct(private readonly string $source)
    {
    }

    public function extract(string $archive, string $destination): void
    {
        $this->copyDirectory($this->source, $destination . '/app');
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)
            && !mkdir($destination, 0700, true)
            && !is_dir($destination)
        ) {
            throw new RuntimeException("unable to create fixture directory: {$destination}");
        }
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot() || $item->getFilename() === '.git') {
                continue;
            }
            $target = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("unable to copy fixture file: {$target}");
            }
        }
    }
}

final class SelfUpdateFakeChecker implements CliSelfChecker
{
    /** @var array<string, true> */
    private array $failures = [];

    public function failVersion(string $version): void
    {
        $this->failures[$version] = true;
    }

    public function check(string $appRoot, string $expectedVersion): bool
    {
        if (isset($this->failures[$expectedVersion])) {
            return false;
        }
        $versionPath = $appRoot . '/src/Version.php';
        $contents = file_get_contents($versionPath);
        if (!is_string($contents)) {
            return false;
        }
        $updated = preg_replace(
            "/public const VALUE = '[^']+';/",
            "public const VALUE = '{$expectedVersion}';",
            $contents,
            1
        );
        if (!is_string($updated)) {
            return false;
        }

        return file_put_contents($versionPath, $updated) !== false;
    }
}
