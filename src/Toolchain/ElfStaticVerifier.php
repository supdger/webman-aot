<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class ElfStaticVerifier
{
    private const ELF_MACHINE_X86_64 = 62;
    private const PROGRAM_DYNAMIC = 2;
    private const PROGRAM_INTERPRETER = 3;

    /**
     * @return array{machine: string, interpreter: bool, dynamicSegment: bool, neededLibraries: list<string>}
     */
    public function inspect(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("unable to open ELF: {$path}");
        }

        try {
            $header = $this->readAt($handle, 0, 64);
            if (substr($header, 0, 4) !== "\x7fELF") {
                throw new \RuntimeException('artifact is not an ELF file');
            }
            if (ord($header[4]) !== 2 || ord($header[5]) !== 1) {
                throw new \RuntimeException('only little-endian ELF64 artifacts are supported');
            }

            $machine = $this->uint16($header, 18);
            if ($machine !== self::ELF_MACHINE_X86_64) {
                throw new \RuntimeException("expected x86_64 ELF machine 62, got {$machine}");
            }

            $programOffset = $this->uint64($header, 32);
            $programEntrySize = $this->uint16($header, 54);
            $programCount = $this->uint16($header, 56);
            if ($programEntrySize < 56) {
                throw new \RuntimeException('ELF program header entry is too small');
            }

            $hasInterpreter = false;
            $hasDynamicSegment = false;
            for ($index = 0; $index < $programCount; $index++) {
                $entry = $this->readAt(
                    $handle,
                    $programOffset + ($index * $programEntrySize),
                    $programEntrySize
                );
                $type = $this->uint32($entry, 0);
                $hasInterpreter = $hasInterpreter || $type === self::PROGRAM_INTERPRETER;
                $hasDynamicSegment = $hasDynamicSegment || $type === self::PROGRAM_DYNAMIC;
            }

            return [
                'machine' => 'x86_64',
                'interpreter' => $hasInterpreter,
                'dynamicSegment' => $hasDynamicSegment,
                'neededLibraries' => [],
            ];
        } finally {
            fclose($handle);
        }
    }

    public function assertFullyStaticX86_64(string $path): void
    {
        $result = $this->inspect($path);
        if ($result['interpreter']) {
            throw new \RuntimeException('ELF contains a PT_INTERP program header');
        }
        if ($result['dynamicSegment']) {
            throw new \RuntimeException(
                'ELF contains a PT_DYNAMIC segment; DT_NEEDED must be inspected and the artifact is not fully static'
            );
        }
    }

    /**
     * @param resource $handle
     */
    private function readAt($handle, int $offset, int $length): string
    {
        if (fseek($handle, $offset) !== 0) {
            throw new \RuntimeException("unable to seek to ELF offset {$offset}");
        }
        $contents = fread($handle, $length);
        if ($contents === false || strlen($contents) !== $length) {
            throw new \RuntimeException("unable to read {$length} bytes at ELF offset {$offset}");
        }
        return $contents;
    }

    private function uint16(string $contents, int $offset): int
    {
        return (int) unpack('vvalue', substr($contents, $offset, 2))['value'];
    }

    private function uint32(string $contents, int $offset): int
    {
        return (int) unpack('Vvalue', substr($contents, $offset, 4))['value'];
    }

    private function uint64(string $contents, int $offset): int
    {
        $parts = unpack('Vlow/Vhigh', substr($contents, $offset, 8));
        return (int) ($parts['low'] + ($parts['high'] * 4294967296));
    }
}
