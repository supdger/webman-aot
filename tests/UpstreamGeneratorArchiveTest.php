<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Cli\UnavailableException;
use WebmanAot\Compatibility\UpstreamGeneratorArchive;

final class UpstreamGeneratorArchiveTest
{
    public function run(string $root): void
    {
        $lock = json_decode(
            (string) file_get_contents($root . '/compatibility/locks/webman-workerman-2026-09-25.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $component = null;
        $toolchain = json_decode(
            (string) file_get_contents($root . '/toolchain.lock.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        foreach ($toolchain['components'] as $entry) {
            if (($entry['id'] ?? null) === 'webman-typephp-generator-source') {
                $component = $entry;
                break;
            }
        }
        $this->assert(
            is_array($component)
            && $component['revision'] === $lock['generator']['revision']
            && $component['sha256'] === $lock['generator']['archiveSha256'],
            'upstream generator archive is not linked to the private toolchain lock'
        );
        $this->assertExtraction();
        $this->assertArchiveDrift();
        $this->assertFileDrift();
        $this->assertInstalledDrift();
    }

    private function assertExtraction(): void
    {
        $fixture = $this->fixture();
        try {
            $extractor = new UpstreamGeneratorArchive();
            $path = $extractor->materialize(
                $fixture['archive'],
                $fixture['lock'],
                $fixture['cache']
            );
            $this->assert(
                hash_file('sha256', $path . '/src/Compiler/ProjectGenerator.php')
                    === $fixture['lock']['sourceSha256'],
                'pinned generator source was not extracted'
            );
            $this->assert(
                $extractor->materialize(
                    $fixture['archive'],
                    $fixture['lock'],
                    $fixture['cache']
                ) === $path,
                'verified generator source was not reused'
            );
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    private function assertArchiveDrift(): void
    {
        $fixture = $this->fixture();
        try {
            file_put_contents($fixture['archive'], 'corrupt');
            $this->fails(
                fn () => (new UpstreamGeneratorArchive())->materialize(
                    $fixture['archive'],
                    $fixture['lock'],
                    $fixture['cache']
                ),
                'archive digest mismatch',
                UnavailableException::class
            );
            $this->assert(
                count(glob($fixture['cache'] . '/*')) === 0,
                'corrupt archive created a generator installation'
            );
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    private function assertFileDrift(): void
    {
        $fixture = $this->fixture();
        try {
            $fixture['lock']['sourceSha256'] = hash('sha256', 'different');
            $this->fails(
                fn () => (new UpstreamGeneratorArchive())->materialize(
                    $fixture['archive'],
                    $fixture['lock'],
                    $fixture['cache']
                ),
                'archive file drift',
                UnavailableException::class
            );
            $this->assert(
                count(glob($fixture['cache'] . '/*')) === 0,
                'drifted archive file created a generator installation'
            );
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    private function assertInstalledDrift(): void
    {
        $fixture = $this->fixture();
        try {
            $extractor = new UpstreamGeneratorArchive();
            $path = $extractor->materialize(
                $fixture['archive'],
                $fixture['lock'],
                $fixture['cache']
            );
            file_put_contents($path . '/src/Compiler/ProjectGenerator.php', 'drift');
            $this->fails(
                fn () => $extractor->materialize(
                    $fixture['archive'],
                    $fixture['lock'],
                    $fixture['cache']
                ),
                'installed file drift',
                ConfigurationException::class
            );
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    /**
     * @return array{root:string,cache:string,archive:string,lock:array<string,string>}
     */
    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/webman-aot-generator-archive-' . bin2hex(random_bytes(8));
        $cache = $root . '/private-cache';
        mkdir($cache, 0700, true);
        $revision = str_repeat('a', 40);
        $files = [
            'src/Compiler/ProjectGenerator.php' => '<?php class GeneratorFixture {}',
            'src/Compiler/Profile/SaiAdminProfile.php' => '<?php class ProfileFixture {}',
            'src/Stubs/main.php.stub' => '<?php // main fixture',
        ];
        $archive = $root . '/generator.zip';
        $zip = new ZipArchive();
        $this->assert(
            $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,
            'unable to create upstream generator ZIP fixture'
        );
        foreach ($files as $path => $contents) {
            $zip->addFromString('webman-typephp-' . $revision . '/' . $path, $contents);
        }
        $zip->close();
        return [
            'root' => $root,
            'cache' => $cache,
            'archive' => $archive,
            'lock' => [
                'revision' => $revision,
                'archiveSha256' => (string) hash_file('sha256', $archive),
                'sourceSha256' => hash('sha256', $files['src/Compiler/ProjectGenerator.php']),
                'profileSha256' => hash('sha256', $files['src/Compiler/Profile/SaiAdminProfile.php']),
                'mainStubSha256' => hash('sha256', $files['src/Stubs/main.php.stub']),
            ],
        ];
    }

    /**
     * @param Closure():mixed $operation
     */
    private function fails(Closure $operation, string $message, string $exceptionClass): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assert(
                $exception instanceof $exceptionClass
                && str_contains($exception->getMessage(), $message),
                "unexpected upstream archive failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("upstream archive did not reject {$message}");
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
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
