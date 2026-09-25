<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\DistributionLeakScanner;
use WebmanAot\Project\RuntimeResourceManifest;

final class DistributionLeakScannerTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-leak-scan-' . bin2hex(random_bytes(8));
        $dynamic = 'vendor/workerman/webman-framework/src/support/view/Raw.php';
        $config = 'config/app.php';
        mkdir($root . '/' . dirname($dynamic), 0700, true);
        mkdir($root . '/config', 0700, true);
        file_put_contents($root . '/' . $dynamic, "<?php class RawView {}\n");
        file_put_contents($root . '/' . $config, "<?php return [];\n");
        file_put_contents($root . '/server', "synthetic-static-elf\n");
        $resources = new RuntimeResourceManifest([
            $this->resource($dynamic, 'third-party-dynamic-php', $root),
            $this->resource($config, 'configuration', $root),
        ]);
        $scanner = new DistributionLeakScanner();
        try {
            $this->assert(
                $scanner->scan($root, $resources) === ['files' => 3, 'approvedPhp' => 2],
                'approved runtime PHP was not accepted'
            );
            file_put_contents($root . '/app.php', "<?php class SecretBusiness {}\n");
            $this->fails($scanner, $root, $resources, [], 'unapproved PHP source');
            unlink($root . '/app.php');

            file_put_contents($root . '/' . $dynamic, "<?php class ChangedView {}\n");
            $this->fails($scanner, $root, $resources, [], 'PHP resource digest drifted');
            unlink($root . '/' . $dynamic);
            $this->fails($scanner, $root, $resources, [], 'omits registered third-party');
            file_put_contents($root . '/' . $dynamic, "<?php class RawView {}\n");

            file_put_contents($root . '/build.o', 'object');
            $this->fails($scanner, $root, $resources, [], 'intermediate file');
            unlink($root . '/build.o');
            mkdir($root . '/build', 0700);
            file_put_contents($root . '/build/debug.h', 'debug');
            $this->fails($scanner, $root, $resources, [], 'build or source metadata');
            unlink($root . '/build/debug.h');
            rmdir($root . '/build');

            file_put_contents($root . '/server', "account=private-test-account\n");
            $this->fails(
                $scanner,
                $root,
                $resources,
                ['private-test-account'],
                'sensitive marker'
            );
            file_put_contents($root . '/server', "database=private-test-database\n");
            $this->fails(
                $scanner,
                $root,
                $resources,
                ['private-test-database'],
                'sensitive marker'
            );
            file_put_contents($root . '/server', "source=/Users/private-user/project\n");
            $this->fails($scanner, $root, $resources, [], 'private host path');
            file_put_contents($root . '/server', "source=C:\\Users\\private-user\\project\n");
            $this->fails($scanner, $root, $resources, [], 'private host path');
            echo "[PASS] distribution rejects unapproved PHP, missing adapters, build files and private markers\n";
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
    private function resource(string $path, string $role, string $root): array
    {
        return [
            'path' => $path,
            'role' => $role,
            'kind' => 'file',
            'sourceSha256' => (string) hash_file('sha256', $root . '/' . $path),
            'mutable' => $role !== 'third-party-dynamic-php',
        ];
    }

    /**
     * @param list<string> $markers
     */
    private function fails(
        DistributionLeakScanner $scanner,
        string $root,
        RuntimeResourceManifest $resources,
        array $markers,
        string $message
    ): void {
        try {
            $scanner->scan($root, $resources, $markers);
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected distribution scan error: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("distribution scan accepted {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
