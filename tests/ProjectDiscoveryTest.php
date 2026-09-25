<?php

declare(strict_types=1);

use WebmanAot\Project\ProfileDetector;
use WebmanAot\Project\ProjectDiscovery;

final class ProjectDiscoveryTest
{
    public function run(): void
    {
        $this->assertWebmanAndNeutralPluginDiscovery();
        $this->assertSaiAdminCoreDiscovery();
        $this->assertPinnedDynamicDiscovery();
    }

    private function assertWebmanAndNeutralPluginDiscovery(): void
    {
        $fixture = $this->fixture(false);
        try {
            $profile = (new ProfileDetector($fixture))->detect();
            $before = (new ProjectDiscovery($fixture))->discover($profile);
            $this->assert(
                !in_array('public/storage/upload.txt', $before->paths(ProjectDiscovery::STATIC_ASSET), true),
                'private uploads were discovered as static assets'
            );
            $this->assert(
                $before->paths(ProjectDiscovery::BUSINESS_PHP) === [
                    'app/Controller/HealthController.php',
                    'app/Service/config/RuntimeConfig.php',
                    'support/bootstrap.php',
                    'vendor/workerman/webman-framework/src/App.php',
                    'vendor/workerman/webman-framework/src/View/Renderer.php',
                ],
                'base Webman PHP discovery drifted'
            );
            $this->assert(
                $before->paths(ProjectDiscovery::INSTALL_ONLY) === [
                    'vendor/workerman/webman-framework/src/Install.php',
                    'vendor/workerman/webman-framework/src/start.php',
                    'vendor/workerman/webman-framework/src/support/Plugin.php',
                    'vendor/workerman/webman-framework/src/windows.php',
                ],
                'Webman Composer installer was not classified as install-only'
            );

            $this->addNeutralPlugin($fixture);
            $after = (new ProjectDiscovery($fixture))->discover($profile);
            $business = $after->paths(ProjectDiscovery::BUSINESS_PHP);
            $this->assert(
                in_array('plugin/neutral/app/controller/PingController.php', $business, true),
                'new neutral plugin business PHP was not discovered'
            );
            $this->assert(
                !in_array('plugin/neutral/Install.php', $business, true),
                'plugin installer leaked into business PHP'
            );
            $this->assert(
                in_array(
                    'plugin/neutral/Install.php',
                    $after->paths(ProjectDiscovery::INSTALL_ONLY),
                    true
                ),
                'plugin installer was not classified as install-only'
            );
            $this->assert(
                in_array(
                    'plugin/neutral/app/view/ping.html',
                    $after->paths(ProjectDiscovery::TEMPLATE),
                    true
                ),
                'plugin template was not classified'
            );
            $this->assert(
                in_array(
                    'plugin/neutral/config/app.php',
                    $after->paths(ProjectDiscovery::CONFIG),
                    true
                ),
                'plugin configuration was not classified'
            );
            $this->assert(
                in_array(
                    'plugin/neutral/public/neutral.js',
                    $after->paths(ProjectDiscovery::STATIC_ASSET),
                    true
                ),
                'plugin static asset was not classified'
            );
            $this->assert(
                in_array(
                    'plugin/neutral/README.md',
                    $after->paths(ProjectDiscovery::SOURCE_METADATA),
                    true
                ),
                'plugin source metadata was not classified'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertSaiAdminCoreDiscovery(): void
    {
        $fixture = $this->fixture(true);
        try {
            $profile = (new ProfileDetector($fixture))->detect();
            $result = (new ProjectDiscovery($fixture))->discover($profile);
            $files = [];
            foreach ($result->files() as $file) {
                $files[$file['path']] = $file;
            }
            $this->assert(
                ($files['plugin/saiadmin/app/controller/LoginController.php']['owner'] ?? null)
                    === 'plugin:saiadmin',
                'installed SaiAdmin business PHP was not discovered'
            );
            $this->assert(
                ($files['vendor/saithink/saiadmin/src/orm/Model.php']['owner'] ?? null)
                    === 'saiadmin-core'
                && ($files['vendor/saithink/saiadmin/src/orm/Model.php']['category'] ?? null)
                    === ProjectDiscovery::INSTALL_ONLY,
                'SaiAdmin ORM installation template was not classified'
            );
            $this->assert(
                ($files['vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php']['owner']
                    ?? null) === 'saiadmin-core'
                && ($files['vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php']['category']
                    ?? null) === ProjectDiscovery::INSTALL_ONLY,
                'SaiAdmin duplicate installation copy was not classified'
            );
            $this->assert(
                ($files['plugin/saiadmin/db/migrations/Version.php']['category'] ?? null)
                    === ProjectDiscovery::INSTALL_ONLY,
                'SaiAdmin migration PHP was not classified as install-only'
            );
            $this->assert(
                !isset($files['plugin/saiadmin/public/export/private.xlsx'])
                    && ($files['plugin/saiadmin/public/template/template.xlsx']['category'] ?? null)
                        === ProjectDiscovery::STATIC_ASSET,
                'SaiAdmin generated exports were included or static templates were omitted'
            );
            $this->write(
                $fixture . '/plugin/saiadmin/public/export/Business.php',
                "<?php class Business {}\n"
            );
            try {
                (new ProjectDiscovery($fixture))->discover($profile);
            } catch (\WebmanAot\Cli\ConfigurationException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'cannot be silently excluded'),
                    'unexpected excluded business PHP failure'
                );
                return;
            }
            throw new RuntimeException('PHP under writable export directory was silently omitted');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertPinnedDynamicDiscovery(): void
    {
        $fixture = $this->fixture(false);
        $relative = 'vendor/workerman/webman-framework/src/support/view/Raw.php';
        $path = $fixture . '/' . $relative;
        try {
            mkdir(dirname($path), 0700, true);
            $this->write($path, "<?php class RawView {}\n");
            $profile = (new ProfileDetector($fixture))->detect();
            $unregistered = (new ProjectDiscovery($fixture))->discover($profile);
            $this->assert(
                in_array($relative, $unregistered->paths(ProjectDiscovery::BUSINESS_PHP), true),
                'unregistered third-party PHP escaped business coverage'
            );
            $registration = [$relative => (string) hash_file('sha256', $path)];
            $registered = (new ProjectDiscovery($fixture, $registration))->discover($profile);
            $this->assert(
                $registered->paths(ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP) === [$relative],
                'registered third-party dynamic PHP was not classified'
            );
            $missingRejected = false;
            try {
                (new ProjectDiscovery(
                    $fixture,
                    ['vendor/workerman/webman-framework/src/support/view/Missing.php'
                        => str_repeat('a', 64)]
                ))->discover($profile);
            } catch (\WebmanAot\Cli\ConfigurationException $exception) {
                $missingRejected = true;
                $this->assert(
                    str_contains($exception->getMessage(), 'dynamic PHP is missing'),
                    'unexpected missing third-party dynamic error'
                );
            }
            $this->assert($missingRejected, 'missing registered dynamic PHP was accepted');
            $this->write($path, "<?php class DriftedView {}\n");
            try {
                (new ProjectDiscovery($fixture, $registration))->discover($profile);
            } catch (\WebmanAot\Cli\ConfigurationException $exception) {
                $this->assert(
                    str_contains($exception->getMessage(), 'dynamic PHP drifted'),
                    'unexpected third-party dynamic drift error'
                );
                return;
            }
            throw new RuntimeException('third-party dynamic PHP drift was accepted');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function fixture(bool $saiAdmin): string
    {
        $directory = sys_get_temp_dir() . '/webman-aot-discovery-' . bin2hex(random_bytes(8));
        $directories = [
            'app/Controller',
            'app/Service/config',
            'support',
            'config',
            'public',
            'public/storage',
            'vendor/workerman/webman-framework/src',
            'vendor/workerman/webman-framework/src/support',
            'vendor/workerman/webman-framework/src/View',
        ];
        if ($saiAdmin) {
            array_push(
                $directories,
                'plugin/saiadmin/app/controller',
                'plugin/saiadmin/config',
                'plugin/saiadmin/basic',
                'plugin/saiadmin/command',
                'plugin/saiadmin/db/migrations',
                'plugin/saiadmin/public/export',
                'plugin/saiadmin/public/template',
                'vendor/saithink/saiadmin/src/orm',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/app',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/basic',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/command'
            );
        }
        foreach ($directories as $relative) {
            mkdir($directory . '/' . $relative, 0700, true);
        }
        $packages = [
            ['name' => 'workerman/webman-framework', 'version' => 'v2.2.4'],
        ];
        if ($saiAdmin) {
            $packages[] = ['name' => 'saithink/saiadmin', 'version' => '6.1.5'];
        }
        $this->write(
            $directory . '/composer.json',
            json_encode(['name' => 'webman-aot/discovery-fixture'], JSON_THROW_ON_ERROR) . "\n"
        );
        $this->write(
            $directory . '/composer.lock',
            json_encode(
                ['packages' => $packages, 'packages-dev' => []],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n"
        );
        foreach ([
            'start.php',
            'app/Controller/HealthController.php',
            'app/Service/config/RuntimeConfig.php',
            'support/bootstrap.php',
            'config/app.php',
            'vendor/workerman/webman-framework/src/App.php',
            'vendor/workerman/webman-framework/src/Install.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/support/Plugin.php',
            'vendor/workerman/webman-framework/src/View/Renderer.php',
            'vendor/workerman/webman-framework/src/windows.php',
        ] as $relative) {
            $this->write($directory . '/' . $relative, "<?php\n");
        }
        $this->write($directory . '/public/index.html', "<html></html>\n");
        $this->write($directory . '/public/storage/upload.txt', "private upload\n");

        if ($saiAdmin) {
            foreach ([
                'plugin/saiadmin/app/controller/LoginController.php',
                'plugin/saiadmin/config/app.php',
                'plugin/saiadmin/basic/BaseController.php',
                'plugin/saiadmin/db/migrations/Version.php',
                'vendor/saithink/saiadmin/src/orm/Model.php',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php',
            ] as $relative) {
                $this->write($directory . '/' . $relative, "<?php\n");
            }
            $this->write(
                $directory . '/vendor/saithink/saiadmin/src/Install.php',
                "<?php\n'plugin/saiadmin' => 'plugin/saiadmin'; copy_dir(\$source, \$dest);\n"
            );
            $this->write(
                $directory . '/vendor/saithink/saiadmin/src/plugin/saiadmin/command/SaiOrm.php',
                "<?php\nvendor/saithink/saiadmin/src/orm/; copyDirectory(\$source, \$dest);\n"
            );
            $this->write(
                $directory . '/plugin/saiadmin/command/SaiOrm.php',
                "<?php\nvendor/saithink/saiadmin/src/orm/; copyDirectory(\$source, \$dest);\n"
            );
            $this->write(
                $directory . '/plugin/saiadmin/public/export/private.xlsx',
                "private generated export\n"
            );
            $this->write(
                $directory . '/plugin/saiadmin/public/template/template.xlsx',
                "static export template\n"
            );
        }

        return $directory;
    }

    private function addNeutralPlugin(string $directory): void
    {
        foreach ([
            'plugin/neutral/app/controller',
            'plugin/neutral/app/view',
            'plugin/neutral/config',
            'plugin/neutral/public',
        ] as $relative) {
            mkdir($directory . '/' . $relative, 0700, true);
        }
        foreach ([
            'plugin/neutral/app/controller/PingController.php',
            'plugin/neutral/Install.php',
            'plugin/neutral/config/app.php',
        ] as $relative) {
            $this->write($directory . '/' . $relative, "<?php\n");
        }
        $this->write($directory . '/plugin/neutral/app/view/ping.html', "pong\n");
        $this->write($directory . '/plugin/neutral/public/neutral.js', "void 0;\n");
        $this->write($directory . '/plugin/neutral/README.md', "fixture\n");
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("unable to write discovery fixture: {$path}");
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
