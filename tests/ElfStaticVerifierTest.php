<?php

declare(strict_types=1);

use WebmanAot\Toolchain\ElfStaticVerifier;

final class ElfStaticVerifierTest
{
    public function run(): void
    {
        $verifier = new ElfStaticVerifier();
        $static = $this->fixture([1, 7]);
        $staticPie = $this->fixture([1], 3);
        $dynamic = $this->fixture([1, 3]);
        $dynamicSegment = $this->fixture([1, 2]);
        $empty = $this->fixture([]);
        $unloadable = $this->fixture([7]);
        $relocatable = $this->fixture([1], 1);

        try {
            $verifier->assertFullyStaticX86_64($static);
            $verifier->assertFullyStaticX86_64($staticPie);
            $this->assertRejected($verifier, $dynamic, 'PT_INTERP');
            $this->assertRejected($verifier, $dynamicSegment, 'PT_DYNAMIC');
            $this->assertRejected($verifier, $empty, 'no program headers');
            $this->assertRejected($verifier, $unloadable, 'no PT_LOAD');
            $this->assertRejected($verifier, $relocatable, 'executable ELF type');
        } finally {
            @unlink($static);
            @unlink($staticPie);
            @unlink($dynamic);
            @unlink($dynamicSegment);
            @unlink($empty);
            @unlink($unloadable);
            @unlink($relocatable);
        }
    }

    private function assertRejected(
        ElfStaticVerifier $verifier,
        string $path,
        string $expected
    ): void {
        try {
            $verifier->assertFullyStaticX86_64($path);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), $expected)) {
                return;
            }
            throw $exception;
        }
        throw new RuntimeException("ELF fixture must reject {$expected}");
    }

    /**
     * @param list<int> $programTypes
     */
    private function fixture(array $programTypes, int $elfType = 2): string
    {
        $path = tempnam(sys_get_temp_dir(), 'webman-aot-elf-');
        if ($path === false) {
            throw new RuntimeException('unable to create ELF fixture');
        }

        $header = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9);
        $header .= pack('v', $elfType);
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
