<?php

declare(strict_types=1);

use WebmanAot\Toolchain\ElfStaticVerifier;

final class ElfStaticVerifierTest
{
    public function run(): void
    {
        $verifier = new ElfStaticVerifier();
        $static = $this->fixture([1, 7]);
        $dynamic = $this->fixture([1, 3]);

        try {
            $verifier->assertFullyStaticX86_64($static);

            $failed = false;
            try {
                $verifier->assertFullyStaticX86_64($dynamic);
            } catch (RuntimeException $exception) {
                $failed = str_contains($exception->getMessage(), 'PT_INTERP');
            }
            if (!$failed) {
                throw new RuntimeException('PT_INTERP fixture must fail closed');
            }
        } finally {
            @unlink($static);
            @unlink($dynamic);
        }
    }

    /**
     * @param list<int> $programTypes
     */
    private function fixture(array $programTypes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'webman-aot-elf-');
        if ($path === false) {
            throw new RuntimeException('unable to create ELF fixture');
        }

        $header = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9);
        $header .= pack('v', 2);
        $header .= pack('v', 62);
        $header .= pack('V', 1);
        $header .= pack('V2', 0, 0);
        $header .= pack('V2', 64, 0);
        $header .= pack('V2', 0, 0);
        $header .= pack('V', 0);
        $header .= pack('v', 64);
        $header .= pack('v', 56);
        $header .= pack('v', count($programTypes));
        $header .= pack('v', 0) . pack('v', 0) . pack('v', 0);

        $programHeaders = '';
        foreach ($programTypes as $type) {
            $programHeaders .= pack('V', $type) . pack('V', 0) . str_repeat("\0", 48);
        }

        file_put_contents($path, $header . $programHeaders);
        return $path;
    }
}
