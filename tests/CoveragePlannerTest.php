<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\CoverageLedger;
use WebmanAot\Project\CoveragePlanner;
use WebmanAot\Project\ProfileDetector;
use WebmanAot\Project\ProjectDiscovery;

final class CoveragePlannerTest
{
    public function run(): void
    {
        $this->assertFourStatusesAndDigests();
        $this->assertUnclassifiedFailsClosed();
        $this->assertBusinessRuntimeBypassFailsClosed();
    }

    private function assertFourStatusesAndDigests(): void
    {
        $fixture = $this->fixture();
        try {
            $discovery = $this->discovery($fixture);
            $ledger = (new CoveragePlanner($fixture))->plan($discovery, [
                'app/Legacy.php' => [
                    'status' => CoverageLedger::COMPILED_SHADOW,
                    'policy' => 'rule.fixture.legacy.v1',
                    'reason' => 'fixture requires a deterministic AOT compatibility copy',
                    'replacement' => '.webman-aot/build/app/Legacy.php',
                ],
            ]);
            $files = [];
            foreach ($ledger->files() as $file) {
                $files[$file['path']] = $file;
            }
            $this->assert(
                ($files['app/Controller/HealthController.php']['status'] ?? null)
                    === CoverageLedger::COMPILED_DIRECT,
                'ordinary business PHP was not planned for direct compilation'
            );
            $shadow = $files['app/Legacy.php'] ?? null;
            $this->assert(
                ($shadow['status'] ?? null) === CoverageLedger::COMPILED_SHADOW,
                'AOT replacement was not planned as compiled-shadow'
            );
            $this->assert(
                ($shadow['sourceSha256'] ?? null)
                    === hash_file('sha256', $fixture . '/app/Legacy.php'),
                'source digest was not recorded'
            );
            $this->assert(
                ($shadow['replacementSha256'] ?? null)
                    === hash_file('sha256', $fixture . '/.webman-aot/build/app/Legacy.php'),
                'replacement digest was not recorded'
            );
            $this->assert(
                ($files['config/app.php']['status'] ?? null)
                    === CoverageLedger::RUNTIME_APPROVED
                && ($files['config/app.php']['policy'] ?? null) === 'runtime.config.v1',
                'configuration runtime policy was not recorded'
            );
            $this->assert(
                ($files['plugin/neutral/Install.php']['status'] ?? null)
                    === CoverageLedger::INSTALL_ONLY
                && ($files['plugin/neutral/Install.php']['policy'] ?? null)
                    === 'install.webman-plugin.v1',
                'install-only policy was not recorded'
            );
            $this->assert(
                $ledger->counts() === [
                    'compiled-direct' => 3,
                    'compiled-shadow' => 1,
                    'install-only' => 1,
                    'runtime-approved' => 3,
                ],
                'coverage status counts drifted'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertUnclassifiedFailsClosed(): void
    {
        $fixture = $this->fixture();
        try {
            file_put_contents($fixture . '/app/schema.json', "{}\n");
            $this->expectFailure(
                fn(): CoverageLedger => (new CoveragePlanner($fixture))->plan(
                    $this->discovery($fixture)
                ),
                'project file is unclassified: app/schema.json'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertBusinessRuntimeBypassFailsClosed(): void
    {
        $fixture = $this->fixture();
        try {
            $this->expectFailure(
                fn(): CoverageLedger => (new CoveragePlanner($fixture))->plan(
                    $this->discovery($fixture),
                    [
                        'app/Controller/HealthController.php' => [
                            'status' => CoverageLedger::RUNTIME_APPROVED,
                            'policy' => 'unsafe.fixture',
                            'reason' => 'must be rejected',
                        ],
                    ]
                ),
                'business PHP cannot bypass AOT compilation'
            );
            $this->expectFailure(
                fn(): CoverageLedger => (new CoveragePlanner($fixture))->plan(
                    $this->discovery($fixture),
                    [
                        'app/Missing.php' => [
                            'status' => CoverageLedger::COMPILED_DIRECT,
                        ],
                    ]
                ),
                'coverage decision references an undiscovered file'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function fixture(): string
    {
        $directory = sys_get_temp_dir() . '/webman-aot-coverage-' . bin2hex(random_bytes(8));
        foreach ([
            'app/Controller',
            'support',
            'config',
            'public',
            'plugin/neutral/app/view',
            'vendor/workerman/webman-framework/src',
            '.webman-aot/build/app',
        ] as $relative) {
            mkdir($directory . '/' . $relative, 0700, true);
        }
        $this->write(
            $directory . '/composer.json',
            json_encode(['name' => 'webman-aot/coverage-fixture'], JSON_THROW_ON_ERROR) . "\n"
        );
        $this->write(
            $directory . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => 'workerman/webman-framework', 'version' => 'v2.2.4'],
                ],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        foreach ([
            'start.php',
            'app/Controller/HealthController.php',
            'app/Legacy.php',
            'support/bootstrap.php',
            'config/app.php',
            'plugin/neutral/Install.php',
            'vendor/workerman/webman-framework/src/App.php',
        ] as $relative) {
            $this->write($directory . '/' . $relative, "<?php\n// {$relative}\n");
        }
        $this->write($directory . '/public/app.js', "void 0;\n");
        $this->write($directory . '/plugin/neutral/app/view/index.html', "ok\n");
        $this->write(
            $directory . '/.webman-aot/build/app/Legacy.php',
            "<?php\n// deterministic replacement\n"
        );

        return $directory;
    }

    private function discovery(string $fixture): \WebmanAot\Project\DiscoveryResult
    {
        $profile = (new ProfileDetector($fixture))->detect();

        return (new ProjectDiscovery($fixture))->discover($profile);
    }

    /**
     * @param \Closure():CoverageLedger $operation
     */
    private function expectFailure(\Closure $operation, string $message): void
    {
        try {
            $operation();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected coverage failure: {$exception->getMessage()}"
            );
            return;
        }

        throw new RuntimeException("expected coverage failure containing: {$message}");
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("unable to write coverage fixture: {$path}");
        }
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
