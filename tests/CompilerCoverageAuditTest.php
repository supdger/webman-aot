<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\CompilerCoverageAudit;
use WebmanAot\Project\CoverageLedger;
use WebmanAot\Project\ProjectDiscovery;

final class CompilerCoverageAuditTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-input-audit-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        mkdir($mirror . '/app', 0700, true);
        mkdir($mirror . '/.typephp/build', 0700, true);
        mkdir($mirror . '/vendor/workerman/webman-framework/src/support', 0700, true);
        file_put_contents($mirror . '/app/Controller.php', "<?php class Controller {}\n");
        file_put_contents($mirror . '/.typephp/build/webman-app.php', "<?php class App {}\n");
        file_put_contents(
            $mirror . '/vendor/workerman/webman-framework/src/App.php',
            "<?php class FrameworkApp {}\n"
        );
        $ledger = new CoverageLedger([
            [
                'path' => 'app/Controller.php',
                'sourceSha256' => (string) hash_file('sha256', $mirror . '/app/Controller.php'),
                'category' => ProjectDiscovery::BUSINESS_PHP,
                'owner' => 'project',
                'status' => CoverageLedger::COMPILED_DIRECT,
                'policy' => null,
                'reason' => null,
            ],
            [
                'path' => 'vendor/workerman/webman-framework/src/App.php',
                'sourceSha256' => (string) hash_file(
                    'sha256',
                    $mirror . '/vendor/workerman/webman-framework/src/App.php'
                ),
                'category' => ProjectDiscovery::BUSINESS_PHP,
                'owner' => 'webman-core',
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => 'upstream.webman-app.v1',
                'reason' => 'verified AOT replacement',
                'replacement' => '.typephp/build/webman-app.php',
                'replacementSha256' => (string) hash_file(
                    'sha256',
                    $mirror . '/.typephp/build/webman-app.php'
                ),
            ],
        ]);
        try {
            $this->writeYml($mirror, ['main.php', 'app', '.typephp/build/webman-app.php'], [
                'vendor/workerman/webman-framework/src/App.php',
            ]);
            $this->assert(
                (new CompilerCoverageAudit())->audit($mirror, $ledger)
                    === ['direct' => 1, 'shadow' => 1],
                'compiler input audit lost direct or shadow coverage'
            );
            $this->writeYml($mirror, ['main.php', '.typephp/build/webman-app.php'], [
                'vendor/workerman/webman-framework/src/App.php',
            ]);
            $this->fails($mirror, $ledger, 'not directly compiled');
            $this->writeYml($mirror, ['main.php', 'app', '.typephp/build/webman-app.php'], [
                'app',
                'vendor/workerman/webman-framework/src/App.php',
            ]);
            $this->fails($mirror, $ledger, 'not directly compiled');
            $this->writeYml($mirror, ['main.php', 'app'], [
                'vendor/workerman/webman-framework/src/App.php',
            ]);
            $this->fails($mirror, $ledger, 'replacement is not compiled');
            $this->writeYml($mirror, ['main.php', 'app', '.typephp/build/webman-app.php'], [
                'vendor/workerman/webman-framework/src/App.php',
            ]);
            file_put_contents($mirror . '/.typephp/build/webman-app.php', "<?php class Drift {}\n");
            $this->fails($mirror, $ledger, 'replacement is not compiled');
            $this->assertEntrypointReplacement($mirror);
            $this->assertRegisteredDynamicPhp($mirror);
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

    private function assertRegisteredDynamicPhp(string $mirror): void
    {
        $source = 'vendor/workerman/webman-framework/src/support/view/Raw.php';
        $sourcePath = $mirror . '/' . $source;
        mkdir(dirname($sourcePath), 0700, true);
        file_put_contents($sourcePath, "<?php class RawView {}\n");
        $record = [
            'path' => $source,
            'sourceSha256' => (string) hash_file('sha256', $sourcePath),
            'category' => ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP,
            'owner' => 'webman-core',
            'status' => CoverageLedger::RUNTIME_APPROVED,
            'policy' => 'runtime.third-party-dynamic.v1',
            'reason' => 'registered runtime template adapter',
        ];
        $ledger = new CoverageLedger([$record]);
        $this->writeYml($mirror, ['main.php'], [$source]);
        $this->assert(
            (new CompilerCoverageAudit())->audit($mirror, $ledger)
                === ['direct' => 0, 'shadow' => 0],
            'registered dynamic PHP was not covered'
        );
        $this->writeYml($mirror, ['main.php'], []);
        $this->fails($mirror, $ledger, 'dynamic PHP is not covered');
        $this->writeYml($mirror, ['main.php'], [$source]);
        $record['policy'] = 'unregistered';
        $this->fails(
            $mirror,
            new CoverageLedger([$record]),
            'dynamic PHP is not covered'
        );
    }

    private function assertEntrypointReplacement(string $mirror): void
    {
        $source = 'vendor/workerman/webman-framework/src/support/bootstrap.php';
        $sourcePath = $mirror . '/' . $source;
        file_put_contents($sourcePath, "<?php function webman_bootstrap() {}\n");
        file_put_contents($mirror . '/main.php', "<?php function main() {}\n");
        $mapping = [
            'source' => $source,
            'sourceSha256' => (string) hash_file('sha256', $sourcePath),
            'replacement' => 'main.php',
            'replacementSha256' => (string) hash_file('sha256', $mirror . '/main.php'),
            'policy' => 'upstream.webman-bootstrap-entrypoint.v1',
        ];
        $ledger = new CoverageLedger([[
            'path' => $source,
            'sourceSha256' => $mapping['sourceSha256'],
            'category' => ProjectDiscovery::BUSINESS_PHP,
            'owner' => 'webman-core',
            'status' => CoverageLedger::COMPILED_SHADOW,
            'policy' => $mapping['policy'],
            'reason' => 'pinned framework bootstrap is replaced by the compiled entrypoint',
            'replacement' => 'main.php',
            'replacementSha256' => $mapping['replacementSha256'],
        ]]);
        $this->writeYml($mirror, ['main.php'], [$source]);
        $this->assert(
            (new CompilerCoverageAudit($mapping))->audit($mirror, $ledger)
                === ['direct' => 0, 'shadow' => 1],
            'locked framework bootstrap entrypoint was not covered'
        );
        $this->fails($mirror, $ledger, 'replacement is not compiled');
        $mapping['sourceSha256'] = str_repeat('0', 64);
        $this->failsWithMapping(
            $mirror,
            $ledger,
            'replacement is not compiled',
            $mapping
        );
        $mapping['sourceSha256'] = (string) hash_file('sha256', $sourcePath);
        file_put_contents($mirror . '/main.php', "<?php function changed_main() {}\n");
        $this->failsWithMapping(
            $mirror,
            $ledger,
            'replacement is not compiled',
            $mapping
        );
    }

    /**
     * @param list<string> $sources
     * @param list<string> $ignore
     */
    private function writeYml(string $mirror, array $sources, array $ignore): void
    {
        $contents = "sources:\n";
        foreach ($sources as $source) {
            $contents .= "  - {$source}\n";
        }
        $contents .= "ignore:\n";
        foreach ($ignore as $path) {
            $contents .= "  - {$path}\n";
        }
        file_put_contents($mirror . '/project.linux.yml', $contents);
    }

    private function fails(string $mirror, CoverageLedger $ledger, string $message): void
    {
        $this->failsWithMapping($mirror, $ledger, $message, null);
    }

    /**
     * @param array{source:string,sourceSha256:string,replacement:string,replacementSha256:string,policy:string}|null $mapping
     */
    private function failsWithMapping(
        string $mirror,
        CoverageLedger $ledger,
        string $message,
        ?array $mapping
    ): void {
        try {
            (new CompilerCoverageAudit($mapping))->audit($mirror, $ledger);
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected input audit failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("input audit did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
