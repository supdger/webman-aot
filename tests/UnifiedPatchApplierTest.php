<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';

use WebmanAot\Toolchain\UnifiedPatchApplier;

final class UnifiedPatchApplierTest
{
    public function run(): void
    {
        $directory = sys_get_temp_dir() . '/webman-aot-patch-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0777, true)) {
            throw new RuntimeException('unable to create patch test directory');
        }

        try {
            file_put_contents($directory . '/example.txt', "alpha\nbeta\ngamma\ndelta\n");
            $patch = $directory . '/change.patch';
            file_put_contents(
                $patch,
                "--- a/example.txt\n"
                . "+++ b/example.txt\n"
                . "@@ -1,3 +1,4 @@\n"
                . " alpha\n"
                . "-beta\n"
                . "+bravo\n"
                . "+charlie\n"
                . " gamma\n"
                . "@@ -4,1 +5,1 @@\n"
                . "-delta\n"
                . "+done\n"
            );

            (new UnifiedPatchApplier())->apply($patch, $directory);
            $this->assert(
                file_get_contents($directory . '/example.txt') === "alpha\nbravo\ncharlie\ngamma\ndone\n",
                'unified patch result does not match'
            );

            $failedClosed = false;
            try {
                (new UnifiedPatchApplier())->apply($patch, $directory);
            } catch (RuntimeException $exception) {
                $failedClosed = str_contains($exception->getMessage(), 'hunk context mismatch');
            }
            $this->assert($failedClosed, 'repeated patch application must fail closed');
        } finally {
            foreach ([$directory . '/example.txt', $directory . '/change.patch'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
