<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Toolchain\TypePhpProjectCompiler;

final class TypePhpProjectCompilerTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-compiler-' . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        mkdir($mirror, 0700, true);
        $tools = $this->tools($root);
        try {
            $this->projectFile($mirror, 'webman-server', $tools['sysroot']);
            $calls = [];
            $compiler = new TypePhpProjectCompiler(
                function (array $command, string $directory, array $environment) use (
                    &$calls,
                    $mirror
                ): int {
                    $calls[] = [$command, $directory, $environment];
                    if (count($calls) === 1) {
                        mkdir($mirror . '/build', 0700);
                        $this->elf($mirror . '/build/webman-server', false);
                    }
                    return 0;
                }
            );
            $result = $compiler->compile($mirror, 'webman-server', $tools);
            $this->assert(
                count($calls) === 2
                && in_array('--full-static', $calls[0][0], true)
                && in_array('--compiler=' . $tools['compiler'], $calls[0][0], true)
                && $calls[0][2]['PHPX_HOME'] === $tools['phpx']
                && $calls[1][0][0] === $tools['objcopy']
                && $result['sha256'] === hash_file('sha256', $result['artifact']),
                'compiler did not use the locked static command and verify its ELF'
            );
            $wrongSdk = $tools;
            $wrongSdk['sdkSha256'] = str_repeat('0', 64);
            $this->fails(
                fn () => $compiler->compile($mirror, 'webman-server', $wrongSdk),
                'SDK fingerprint differs'
            );
            file_put_contents($root . '/phpx/full-static/sdk/libtest.a', 'changed archive');
            $this->fails(
                fn () => $compiler->compile($mirror, 'webman-server', $tools),
                'SDK fingerprint differs'
            );
            file_put_contents($root . '/phpx/full-static/sdk/libtest.a', 'static test archive');

            $this->projectFile($mirror, 'different-name', $tools['sysroot']);
            $this->fails(
                fn () => $compiler->compile($mirror, 'webman-server', $tools),
                'locked full-static target'
            );
            $this->projectFile($mirror, 'webman-server', $tools['sysroot']);
            $this->elf($mirror . '/build/webman-server', false);
            file_put_contents($mirror . '/build/webman-server', $mirror, FILE_APPEND);
            $noCompile = new TypePhpProjectCompiler(
                static fn (array $command, string $directory, array $environment): int => 0
            );
            $this->fails(
                fn () => $noCompile->compile($mirror, 'webman-server', $tools),
                'embeds a private build path'
            );
            $failed = new TypePhpProjectCompiler(
                static fn (array $command, string $directory, array $environment): int => 255
            );
            $this->fails(
                fn () => $failed->compile($mirror, 'webman-server', $tools),
                'compilation failed'
            );
            $this->elf($mirror . '/build/webman-server', true);
            $dynamic = new TypePhpProjectCompiler(
                static fn (array $command, string $directory, array $environment): int => 0
            );
            $this->fails(
                fn () => $dynamic->compile($mirror, 'webman-server', $tools),
                'PT_INTERP'
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

    /**
     * @return array{php:string,typephp:string,phpx:string,compiler:string,objcopy:string,sysroot:string,phprc:string,sdkSha256:string}
     */
    private function tools(string $root): array
    {
        foreach (['typephp/bin', 'phpx/full-static/sdk', 'llvm', 'sysroot', 'config'] as $dir) {
            mkdir($root . '/' . $dir, 0700, true);
        }
        file_put_contents($root . '/typephp/bin/tpc.php', "<?php\n");
        file_put_contents($root . '/phpx/full-static/sdk/libtest.a', 'static test archive');
        $compiler = $root . '/llvm/clang' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $objcopy = $root . '/llvm/llvm-objcopy' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (PHP_OS_FAMILY === 'Windows') {
            copy(PHP_BINARY, $compiler);
            copy(PHP_BINARY, $objcopy);
        } else {
            file_put_contents($compiler, '');
            file_put_contents($objcopy, '');
        }
        chmod($compiler, 0700);
        chmod($objcopy, 0700);
        return [
            'php' => PHP_BINARY,
            'typephp' => $root . '/typephp',
            'phpx' => $root . '/phpx',
            'compiler' => $compiler,
            'objcopy' => $objcopy,
            'sysroot' => $root . '/sysroot',
            'phprc' => $root . '/config',
            'sdkSha256' => (new \WebmanAot\Toolchain\StaticSdkFingerprint())
                ->digest($root . '/phpx/full-static/sdk'),
        ];
    }

    private function projectFile(string $mirror, string $output, string $sysroot): void
    {
        $sysroot = str_replace('\\', '/', $sysroot);
        file_put_contents(
            $mirror . '/project.linux.yml',
            "name: webman\noutput: build/{$output}\nbuild-dir: build\n"
            . "target-platform: x86_64-unknown-linux-musl\n"
            . "reproducible-source-prefix: /usr/src/webman-aot\n"
            . "cxx-flags: --sysroot=\"{$sysroot}\"\n"
            . "c-flags: --sysroot=\"{$sysroot}\"\n"
            . "asm-flags: --sysroot=\"{$sysroot}\"\n"
            . "ld-flags: --sysroot=\"{$sysroot}\"\n"
        );
    }

    private function elf(string $path, bool $dynamic): void
    {
        $header = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9);
        $header .= pack('v', 2) . pack('v', 62) . pack('V', 1);
        $header .= pack('V2', 0, 0) . pack('V2', 64, 0)
            . pack('V2', 0, 0) . pack('V', 0);
        $header .= pack('v', 64) . pack('v', 56) . pack('v', 1);
        $header .= pack('v', 0) . pack('v', 0) . pack('v', 0);
        $program = pack('V', $dynamic ? 3 : 1)
            . pack('V', 0) . str_repeat("\0", 48);
        file_put_contents($path, $header . $program);
    }

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected compiler failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("compiler did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
