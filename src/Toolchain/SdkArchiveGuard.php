<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class SdkArchiveGuard
{
    private const LIBPHP_SHA256 = 'edef07bd8e532334e02061481b4bbbd70210cd8505fe90d6bec155ea04133003';
    private const LIBPHPX_SHA256 = 'afb819aab33837f8a7e8a69e0fb5a6a16029ee6fb4ca28ed79ef5b41fb6fac74';
    private const ALLOWED_DUPLICATE = '__get_connection';
    private const ALLOWED_DUPLICATE_COUNT = 2;

    /**
     * @return array{libphpSha256: string, libphpxSha256: string, duplicateSymbol: string, definitions: int}
     */
    public function inspect(string $sdkDirectory, string $llvmNm): array
    {
        $libphp = rtrim($sdkDirectory, '/\\') . '/lib/libphp.a';
        $libphpx = rtrim($sdkDirectory, '/\\') . '/lib/libphpx.a';

        $libphpHash = $this->assertDigest($libphp, self::LIBPHP_SHA256);
        $libphpxHash = $this->assertDigest($libphpx, self::LIBPHPX_SHA256);
        $definitions = $this->countSymbolDefinitions($llvmNm, $libphp, self::ALLOWED_DUPLICATE);
        if ($definitions !== self::ALLOWED_DUPLICATE_COUNT) {
            throw new \RuntimeException(
                sprintf(
                    'locked SDK must contain exactly %d %s definitions, found %d',
                    self::ALLOWED_DUPLICATE_COUNT,
                    self::ALLOWED_DUPLICATE,
                    $definitions
                )
            );
        }

        return [
            'libphpSha256' => $libphpHash,
            'libphpxSha256' => $libphpxHash,
            'duplicateSymbol' => self::ALLOWED_DUPLICATE,
            'definitions' => $definitions,
        ];
    }

    private function assertDigest(string $path, string $expected): string
    {
        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new \RuntimeException("SDK archive digest mismatch: {$path}");
        }
        return $actual;
    }

    private function countSymbolDefinitions(string $llvmNm, string $archive, string $symbol): int
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [$llvmNm, '--defined-only', '--just-symbol-name', $archive],
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $nullDevice, 'a'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('unable to start llvm-nm');
        }

        $count = 0;
        while (($line = fgets($pipes[1])) !== false) {
            if (trim($line) === $symbol) {
                $count++;
            }
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("llvm-nm failed with exit code {$exitCode}");
        }
        return $count;
    }
}
