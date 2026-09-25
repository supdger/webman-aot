<?php

declare(strict_types=1);

use WebmanAot\Compatibility\SaiAdminProfileOverlay;

final class SaiAdminProfileOverlayTest
{
    public function run(): void
    {
        $directory = sys_get_temp_dir() . '/webman-aot-profile-overlay-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $source = $directory . '/SaiAdminProfile.php';
        $lock = $directory . '/composer.lock';
        $body = "<?php\n'nesbot/carbon' => ['3.13.2'],\n";
        file_put_contents($source, $body);
        $overlay = new SaiAdminProfileOverlay();
        $cache = $directory . '/cache';
        try {
            $this->writeLock($lock, '3.13.2');
            $this->assert(
                $overlay->prepare($source, hash('sha256', $body), $lock, $cache) === $source,
                'supported original Carbon version must use the unchanged upstream profile'
            );

            $this->writeLock($lock, '3.14.0');
            $shadow = $overlay->prepare($source, hash('sha256', $body), $lock, $cache);
            $this->assert(
                $shadow !== $source
                && str_contains((string) file_get_contents($shadow), "'3.13.2', '3.14.0'")
                && file_get_contents($source) === $body,
                'Carbon 3.14 overlay must be private, bounded, and preserve upstream source'
            );
            $this->assert(
                $overlay->prepare($source, hash('sha256', $body), $lock, $cache) === $shadow,
                'Carbon 3.14 overlay must be deterministic'
            );
            $this->expectFailure(
                fn() => $overlay->prepare($source, str_repeat('0', 64), $lock, $cache),
                'profile source drift must fail'
            );
            file_put_contents($source, $body . "'nesbot/carbon' => ['3.13.2'],\n");
            $this->expectFailure(
                fn() => $overlay->prepare($source, hash_file('sha256', $source), $lock, $cache),
                'duplicate Carbon gate must fail'
            );
            $this->writeLock($lock, '3.15.0');
            $this->assert(
                $overlay->prepare($source, hash_file('sha256', $source), $lock, $cache) === $source,
                'unknown Carbon version must remain subject to the upstream gate'
            );
        } finally {
            foreach (glob($cache . '/saiadmin-profile-overlays/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($cache . '/saiadmin-profile-overlays')) {
                rmdir($cache . '/saiadmin-profile-overlays');
            }
            if (is_dir($cache)) {
                rmdir($cache);
            }
            unlink($source);
            unlink($lock);
            rmdir($directory);
        }
    }

    private function writeLock(string $path, string $version): void
    {
        file_put_contents($path, json_encode([
            'packages' => [['name' => 'nesbot/carbon', 'version' => $version]],
        ], JSON_THROW_ON_ERROR));
    }

    private function expectFailure(callable $action, string $message): void
    {
        try {
            $action();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
