<?php

declare(strict_types=1);

use WebmanAot\Toolchain\PreparedToolchain;
use WebmanAot\Toolchain\StaticSdkFingerprint;

final class PreparedToolchainTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-prepared-' . bin2hex(random_bytes(8));
        $private = $root . '/private';
        $prepared = $private . '/prepared';
        $typephp = $prepared . '/typephp';
        $phpx = $typephp . '/vendor/swoole/phpx';
        foreach ([
            $phpx . '/full-static/sdk',
            $typephp . '/bin',
            $prepared . '/php-driver',
            $prepared . '/llvm',
            $prepared . '/sysroot',
            $prepared . '/php-config',
        ] as $directory) {
            mkdir($directory, 0700, true);
        }
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $paths = [
            'php' => $prepared . '/php-driver/php' . $suffix,
            'typephp' => $typephp,
            'phpx' => $phpx,
            'compiler' => $prepared . '/llvm/clang++' . $suffix,
            'objcopy' => $prepared . '/llvm/llvm-objcopy' . $suffix,
            'sysroot' => $prepared . '/sysroot',
            'phprc' => $prepared . '/php-config',
        ];
        foreach (['php', 'compiler', 'objcopy'] as $name) {
            if (PHP_OS_FAMILY === 'Windows') {
                copy(PHP_BINARY, $paths[$name]);
            } else {
                file_put_contents($paths[$name], '');
            }
            chmod($paths[$name], 0700);
        }
        file_put_contents($typephp . '/bin/tpc.php', "<?php\n");
        file_put_contents($phpx . '/full-static/sdk/test.a', 'static archive');
        $lock = $root . '/toolchain.lock.json';
        file_put_contents($lock, "{}\n");
        $manifest = $prepared . '/prepared-toolchain.json';
        $data = [
            'schema' => 'webman-aot-prepared-toolchain-v1',
            'host' => 'test-host',
            'lockSha256' => hash_file('sha256', $lock),
            ...$paths,
            'sdkSha256' => (new StaticSdkFingerprint())
                ->digest($phpx . '/full-static/sdk'),
        ];
        $write = static function () use ($manifest, &$data): void {
            file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
        };
        $write();
        $loader = new PreparedToolchain();
        try {
            $loaded = $loader->load($manifest, $private, $lock, 'test-host');
            $this->assert($loaded['phpx'] === realpath($phpx)
                && $loaded['sdkSha256'] === $data['sdkSha256'],
                'prepared toolchain did not resolve its private tools');
            foreach (array_keys($paths) as $name) {
                $data[$name] = substr($paths[$name], strlen($prepared) + 1);
            }
            $write();
            $relative = $loader->load($manifest, $private, $lock, 'test-host');
            $different = [];
            foreach ($loaded as $name => $value) {
                if (($relative[$name] ?? null) !== $value) {
                    $different[] = $name;
                }
            }
            $this->assert(
                $different === [],
                'relative prepared paths did not survive relocation: ' . implode(', ', $different)
            );
            $this->fails(
                fn () => $loader->load($manifest, $private, $lock, 'other-host'),
                'host or lock differs'
            );
            file_put_contents($lock, "{\"changed\":true}\n");
            $this->fails(
                fn () => $loader->load($manifest, $private, $lock, 'test-host'),
                'host or lock differs'
            );
            file_put_contents($lock, "{}\n");
            $data['compiler'] = PHP_BINARY;
            $write();
            $this->fails(
                fn () => $loader->load($manifest, $private, $lock, 'test-host'),
                'missing or unsafe'
            );
            $data['compiler'] = '../php-driver/php' . $suffix;
            $write();
            $this->fails(
                fn () => $loader->load($manifest, $private, $lock, 'test-host'),
                'missing or unsafe'
            );
            $data['compiler'] = substr($paths['compiler'], strlen($prepared) + 1);
            $write();
            if (PHP_OS_FAMILY !== 'Windows') {
                $clang = $prepared . '/llvm/clang-19';
                rename($paths['compiler'], $clang);
                symlink('clang-19', $paths['compiler']);
                $internalLink = $loader->load($manifest, $private, $lock, 'test-host');
                $this->assert(
                    $internalLink['compiler'] === $paths['compiler'],
                    'internal compiler symlink lost its clang++ invocation name'
                );
                unlink($paths['compiler']);
                symlink(PHP_BINARY, $paths['compiler']);
                $this->fails(
                    fn () => $loader->load($manifest, $private, $lock, 'test-host'),
                    'missing or unsafe'
                );
                unlink($paths['compiler']);
                rename($clang, $paths['compiler']);
            }
            file_put_contents($phpx . '/full-static/sdk/test.a', 'tampered');
            $this->fails(
                fn () => $loader->load($manifest, $private, $lock, 'test-host'),
                'SDK fingerprint differs'
            );
            $this->fails(
                fn () => $loader->load($manifest, $root . '/other', $lock, 'test-host'),
                'outside the private directory'
            );
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

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected prepared toolchain failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("prepared toolchain did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
