<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\DistributionLeakScanner;
use WebmanAot\Project\RuntimeResourceManifest;
use WebmanAot\Project\RuntimeResourcePackager;

final class RuntimeResourcePackagerTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-package-' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $candidate = $root . '/candidate';
        $dynamic = 'vendor/workerman/webman-framework/src/support/view/Raw.php';
        mkdir($source . '/' . dirname($dynamic), 0700, true);
        mkdir($source . '/config', 0700, true);
        mkdir($source . '/plugin/saiadmin/public/export', 0700, true);
        mkdir($candidate, 0700);
        file_put_contents($source . '/' . $dynamic, "<?php class RawView {}\n");
        file_put_contents($source . '/config/app.php', "<?php return [];\n");
        file_put_contents($source . '/.env', "DB_PASSWORD=private-example-value\n");
        file_put_contents(
            $source . '/plugin/saiadmin/public/export/private.xlsx',
            "private generated export\n"
        );
        $manifest = new RuntimeResourceManifest([
            $this->fileEntry($dynamic, 'third-party-dynamic-php', $source),
            $this->fileEntry('config/app.php', 'configuration', $source),
            [
                'path' => '.env',
                'role' => 'environment',
                'kind' => 'external-file',
                'sourceSha256' => null,
                'mutable' => true,
            ],
            [
                'path' => 'runtime/logs',
                'role' => 'logs',
                'kind' => 'writable-directory',
                'sourceSha256' => null,
                'mutable' => true,
            ],
            [
                'path' => 'plugin/saiadmin/public/export',
                'role' => 'uploads',
                'kind' => 'writable-directory',
                'sourceSha256' => null,
                'mutable' => true,
            ],
        ]);
        $packager = new RuntimeResourcePackager();
        try {
            $this->assert(
                $packager->package($source, $candidate, $manifest)
                    === ['copiedFiles' => 2, 'writableDirectories' => 2, 'externalFiles' => 1],
                'runtime package did not close its declared resources'
            );
            $this->assert(
                !file_exists($candidate . '/.env')
                    && is_dir($candidate . '/runtime/logs')
                    && is_dir($candidate . '/plugin/saiadmin/public/export')
                    && !file_exists($candidate . '/plugin/saiadmin/public/export/private.xlsx'),
                'private source .env or generated export was copied, or writable directory was omitted'
            );
            $this->assert(
                (new DistributionLeakScanner())->scan($candidate, $manifest)
                    === ['files' => 2, 'approvedPhp' => 2],
                'packaged runtime resources failed the independent leakage scan'
            );
            $this->fails($packager, $source, $candidate, $manifest, 'conflicts');
            file_put_contents($source . '/' . $dynamic, "<?php class Drift {}\n");
            $fresh = $root . '/fresh';
            mkdir($fresh, 0700);
            $this->fails($packager, $source, $fresh, $manifest, 'changed');
            echo "[PASS] resource packaging preserves registered adapters and excludes source .env\n";
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    /**
     * @return array{path:string,role:string,kind:string,sourceSha256:string,mutable:bool}
     */
    private function fileEntry(string $path, string $role, string $source): array
    {
        return [
            'path' => $path,
            'role' => $role,
            'kind' => 'file',
            'sourceSha256' => (string) hash_file('sha256', $source . '/' . $path),
            'mutable' => $role === 'configuration',
        ];
    }

    private function fails(
        RuntimeResourcePackager $packager,
        string $source,
        string $candidate,
        RuntimeResourceManifest $manifest,
        string $message
    ): void {
        try {
            $packager->package($source, $candidate, $manifest);
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected resource package failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("runtime resource package accepted {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
