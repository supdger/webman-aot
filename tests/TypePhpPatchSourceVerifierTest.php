<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Toolchain\TypePhpPatchSourceVerifier;

final class TypePhpPatchSourceVerifierTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-patch-source-' . bin2hex(random_bytes(8));
        $typephp = $root . '/typephp';
        $target = $typephp . '/src/Parser/Example.php';
        mkdir(dirname($target), 0700, true);
        $manifest = $root . '/manifest.json';
        $source = "<?php\n// patched\n";
        file_put_contents($target, $source);
        file_put_contents($manifest, json_encode([
            'component' => 'typephp-source',
            'version' => '0.9.2',
            'rules' => [[
                'path' => 'src/Parser/Example.php',
                'afterSha256' => hash('sha256', $source),
            ]],
        ], JSON_THROW_ON_ERROR));
        $verifier = new TypePhpPatchSourceVerifier();
        try {
            $verifier->verify($typephp, $manifest);
            file_put_contents($target, $source . "// drift\n");
            $this->fails(
                fn () => $verifier->verify($typephp, $manifest),
                'prepared TypePHP patch source drifted'
            );
            unlink($target);
            $this->fails(
                fn () => $verifier->verify($typephp, $manifest),
                'prepared TypePHP patch source drifted'
            );
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            unlink($manifest);
            rmdir(dirname($target));
            rmdir($typephp . '/src');
            rmdir($typephp);
            rmdir($root);
        }
    }

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            if (str_contains($exception->getMessage(), $message)) {
                return;
            }
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
        throw new RuntimeException("patch source verifier did not reject {$message}");
    }
}
