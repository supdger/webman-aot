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
    }

    private function assertWebmanAndNeutralPluginDiscovery(): void
    {
        $fixture = $this->fixture(false);
        try {
            $profile = (new ProfileDetector($fixture))->detect();
            $before = (new ProjectDiscovery($fixture))->discover($profile);
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
                    === 'saiadmin-core',
                'SaiAdmin package core PHP was not discovered'
            );
            $this->assert(
                ($files['vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php']['owner']
                    ?? null) === 'saiadmin-core',
                'SaiAdmin packaged plugin PHP was not discovered'
            );
            $this->assert(
                ($files['plugin/saiadmin/db/migrations/Version.php']['category'] ?? null)
                    === ProjectDiscovery::INSTALL_ONLY,
                'SaiAdmin migration PHP was not classified as install-only'
            );
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
            'vendor/workerman/webman-framework/src',
            'vendor/workerman/webman-framework/src/View',
        ];
        if ($saiAdmin) {
            array_push(
                $directories,
                'plugin/saiadmin/app/controller',
                'plugin/saiadmin/config',
                'plugin/saiadmin/basic',
                'plugin/saiadmin/db/migrations',
                'vendor/saithink/saiadmin/src/orm',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/app',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/basic'
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
            'vendor/workerman/webman-framework/src/View/Renderer.php',
        ] as $relative) {
            $this->write($directory . '/' . $relative, "<?php\n");
        }
        $this->write($directory . '/public/index.html', "<html></html>\n");

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
