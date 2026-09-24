<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\ProfileDetector;
use WebmanAot\Project\ProjectProfile;

final class ProfileDetectorTest
{
    public function run(): void
    {
        $this->assertWebmanDetection();
        $this->assertSaiAdminDetection();
        $this->assertIncompleteSaiAdminFailsClosed();
        $this->assertExplicitConflictsFail();
    }

    private function assertWebmanDetection(): void
    {
        $fixture = $this->fixture([
            'workerman/webman-framework' => 'v2.2.4',
        ]);
        try {
            $profile = (new ProfileDetector($fixture))->detect();
            $this->assert($profile->name() === ProjectProfile::WEBMAN, 'Webman profile was not detected');
            $this->assert(
                ($profile->packages()['workerman/webman-framework'] ?? null) === 'v2.2.4',
                'Webman lock version was not preserved'
            );
            $this->assert(
                (new ProfileDetector($fixture))->detect(ProjectProfile::WEBMAN)->name()
                    === ProjectProfile::WEBMAN,
                'matching explicit Webman profile was rejected'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertSaiAdminDetection(): void
    {
        $fixture = $this->fixture([
            'workerman/webman-framework' => 'v2.2.4',
            'saithink/saiadmin' => '6.1.5',
        ], true);
        try {
            $profile = (new ProfileDetector($fixture))->detect();
            $this->assert($profile->name() === ProjectProfile::SAIADMIN, 'SaiAdmin profile was not detected');
            $this->assert(
                ($profile->packages()['saithink/saiadmin'] ?? null) === '6.1.5',
                'SaiAdmin lock version was not preserved'
            );
            $this->assert(
                in_array(
                    'vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php',
                    $profile->evidence(),
                    true
                ),
                'SaiAdmin package structure evidence was omitted'
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function assertIncompleteSaiAdminFailsClosed(): void
    {
        $directoryMarker = $this->fixture([
            'workerman/webman-framework' => 'v2.2.4',
        ], true);
        try {
            $this->expectConfigurationFailure(
                fn(): ProjectProfile => (new ProfileDetector($directoryMarker))->detect(),
                'saithink/saiadmin is absent from composer.lock'
            );
        } finally {
            $this->removeDirectory($directoryMarker);
        }

        $lockMarker = $this->fixture([
            'workerman/webman-framework' => 'v2.2.4',
            'saithink/saiadmin' => '6.1.5',
        ]);
        try {
            $this->expectConfigurationFailure(
                fn(): ProjectProfile => (new ProfileDetector($lockMarker))->detect(),
                'SaiAdmin dependency evidence is incomplete'
            );
        } finally {
            $this->removeDirectory($lockMarker);
        }
    }

    private function assertExplicitConflictsFail(): void
    {
        $webman = $this->fixture([
            'workerman/webman-framework' => 'dev-master',
        ]);
        $saiAdmin = $this->fixture([
            'workerman/webman-framework' => 'v2.2.4',
            'saithink/saiadmin' => '6.1.1',
        ], true);
        try {
            $this->expectConfigurationFailure(
                fn(): ProjectProfile => (new ProfileDetector($webman))->detect(ProjectProfile::SAIADMIN),
                'explicit profile saiadmin conflicts with detected profile webman'
            );
            $this->expectConfigurationFailure(
                fn(): ProjectProfile => (new ProfileDetector($saiAdmin))->detect(ProjectProfile::WEBMAN),
                'explicit profile webman conflicts with detected profile saiadmin'
            );
            $this->expectConfigurationFailure(
                fn(): ProjectProfile => (new ProfileDetector($webman))->detect('unknown'),
                'unknown project profile'
            );
        } finally {
            $this->removeDirectory($webman);
            $this->removeDirectory($saiAdmin);
        }
    }

    /**
     * @param array<string, string> $packages
     */
    private function fixture(array $packages, bool $saiAdminStructure = false): string
    {
        $directory = sys_get_temp_dir() . '/webman-aot-profile-' . bin2hex(random_bytes(8));
        foreach ([$directory, $directory . '/app'] as $path) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException("unable to create profile fixture: {$path}");
            }
        }
        file_put_contents(
            $directory . '/composer.json',
            json_encode(['name' => 'webman-aot/profile-fixture'], JSON_THROW_ON_ERROR) . "\n"
        );
        $locked = [];
        foreach ($packages as $name => $version) {
            $locked[] = ['name' => $name, 'version' => $version];
        }
        file_put_contents(
            $directory . '/composer.lock',
            json_encode(
                ['packages' => $locked, 'packages-dev' => []],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n"
        );
        file_put_contents($directory . '/start.php', "<?php\n");

        if ($saiAdminStructure) {
            foreach ([
                'plugin/saiadmin/app',
                'plugin/saiadmin/config',
                'plugin/saiadmin/basic',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/app',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/basic',
            ] as $relative) {
                mkdir($directory . '/' . $relative, 0700, true);
            }
            foreach ([
                'plugin/saiadmin/basic/BaseController.php',
                'vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php',
            ] as $relative) {
                file_put_contents($directory . '/' . $relative, "<?php\n");
            }
        }

        return $directory;
    }

    /**
     * @param \Closure():ProjectProfile $operation
     */
    private function expectConfigurationFailure(\Closure $operation, string $message): void
    {
        try {
            $operation();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected profile failure: {$exception->getMessage()}"
            );
            return;
        }

        throw new RuntimeException("expected profile failure containing: {$message}");
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
