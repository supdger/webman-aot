<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\CoverageLedger;
use WebmanAot\Project\DiscoveryResult;
use WebmanAot\Project\ProjectDiscovery;
use WebmanAot\Project\RuntimeResourceManifest;
use WebmanAot\Project\RuntimeResourcePlanner;

final class RuntimeResourcePlannerTest
{
    public function run(): void
    {
        $fixture = $this->fixture();
        try {
            [$discovery, $coverage] = $this->coveredFiles($fixture);
            $planner = new RuntimeResourcePlanner($fixture);
            $manifest = $planner->plan(
                $discovery,
                $coverage,
                [['path' => 'support/certs/ca.crt', 'role' => 'certificate']],
                [['path' => 'runtime/exports', 'role' => 'uploads']]
            );
            $this->assertRoles($manifest);
            $this->assert(
                !str_contains(json_encode($manifest->toArray(), JSON_THROW_ON_ERROR), 'private-db-password'),
                'source .env secret leaked into runtime manifest'
            );
            $this->assertFailure(
                fn(): RuntimeResourceManifest => $planner->plan(
                    $discovery,
                    $coverage,
                    [['path' => 'support/certs/missing.crt', 'role' => 'certificate']]
                ),
                'required runtime resource is missing'
            );
            $this->assertFailure(
                fn(): RuntimeResourceManifest => $planner->plan(
                    $discovery,
                    $coverage,
                    [['path' => 'app/LoginController.php', 'role' => 'certificate']]
                ),
                'PHP cannot be declared as a runtime data resource'
            );
            unlink($fixture . '/app/view/index.html');
            $this->assertFailure(
                fn(): RuntimeResourceManifest => $planner->plan($discovery, $coverage),
                'required runtime resource is missing'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertRoles(RuntimeResourceManifest $manifest): void
    {
        $entries = [];
        foreach ($manifest->entries() as $entry) {
            $entries[$entry['path']] = $entry;
        }
        foreach ([
            'config/app.php' => 'configuration',
            'app/view/index.html' => 'template',
            'public/app.css' => 'static',
            'public/fonts/admin.woff2' => 'font',
            'public/certs/root.pem' => 'certificate',
            'public/zoneinfo/Asia/Shanghai' => 'timezone',
            'support/certs/ca.crt' => 'certificate',
            '.env' => 'environment',
            'public/storage' => 'uploads',
            'runtime/exports' => 'uploads',
            'runtime/logs' => 'logs',
        ] as $path => $role) {
            $this->assert(
                ($entries[$path]['role'] ?? null) === $role,
                "runtime resource role was omitted: {$path}"
            );
        }
        $this->assert(
            ($entries['.env']['kind'] ?? null) === 'external-file'
                && array_key_exists('sourceSha256', $entries['.env'])
                && $entries['.env']['sourceSha256'] === null,
            'mutable deployment .env was not isolated from source content'
        );
        foreach (['public/storage', 'runtime/exports', 'runtime/logs'] as $path) {
            $this->assert(
                ($entries[$path]['kind'] ?? null) === 'writable-directory',
                "runtime writable directory was not registered: {$path}"
            );
        }
        $this->assert(
            ($entries['config/app.php']['sourceSha256'] ?? null)
                === hash_file('sha256', $this->fixtureRoot . '/config/app.php'),
            'runtime resource source digest was not recorded'
        );
    }

    private string $fixtureRoot = '';

    private function fixture(): string
    {
        $directory = sys_get_temp_dir() . '/webman-aot-resources-' . bin2hex(random_bytes(8));
        $this->fixtureRoot = $directory;
        foreach ([
            'config',
            'app/view',
            'support/certs',
            'public/fonts',
            'public/certs',
            'public/zoneinfo/Asia',
        ] as $relative) {
            mkdir($directory . '/' . $relative, 0700, true);
        }
        foreach ([
            'config/app.php' => "<?php\nreturn [];\n",
            'app/view/index.html' => "<html></html>\n",
            'public/app.css' => "body {}\n",
            'public/fonts/admin.woff2' => "font-fixture\n",
            'public/certs/root.pem' => "certificate-fixture\n",
            'public/zoneinfo/Asia/Shanghai' => "timezone-fixture\n",
            'support/certs/ca.crt' => "ca-fixture\n",
            'app/LoginController.php' => "<?php\n",
            '.env' => "DB_PASSWORD=private-db-password\n",
        ] as $relative => $contents) {
            file_put_contents($directory . '/' . $relative, $contents);
        }

        return $directory;
    }

    /**
     * @return array{DiscoveryResult,CoverageLedger}
     */
    private function coveredFiles(string $directory): array
    {
        $categories = [
            'config/app.php' => ProjectDiscovery::CONFIG,
            'app/view/index.html' => ProjectDiscovery::TEMPLATE,
            'public/app.css' => ProjectDiscovery::STATIC_ASSET,
            'public/fonts/admin.woff2' => ProjectDiscovery::STATIC_ASSET,
            'public/certs/root.pem' => ProjectDiscovery::STATIC_ASSET,
            'public/zoneinfo/Asia/Shanghai' => ProjectDiscovery::STATIC_ASSET,
        ];
        $discovered = [];
        $covered = [];
        foreach ($categories as $path => $category) {
            $discovered[] = [
                'path' => $path,
                'category' => $category,
                'owner' => 'fixture',
            ];
            $covered[] = [
                'path' => $path,
                'sourceSha256' => hash_file('sha256', $directory . '/' . $path),
                'category' => $category,
                'owner' => 'fixture',
                'status' => CoverageLedger::RUNTIME_APPROVED,
                'policy' => 'fixture.runtime',
                'reason' => 'test runtime resource',
            ];
        }

        return [new DiscoveryResult($discovered), new CoverageLedger($covered)];
    }

    /**
     * @param Closure():RuntimeResourceManifest $operation
     */
    private function assertFailure(Closure $operation, string $message): void
    {
        try {
            $operation();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected runtime resource failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("expected runtime resource failure: {$message}");
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
