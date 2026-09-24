<?php

declare(strict_types=1);

use WebmanAot\Toolchain\LockValidator;

final class ToolchainLockTest
{
    public function run(string $root): void
    {
        $contents = file_get_contents($root . '/toolchain.lock.json');
        if ($contents === false) {
            throw new RuntimeException('unable to read toolchain.lock.json');
        }

        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($lock)) {
            throw new RuntimeException('toolchain lock root must be an object');
        }

        $validator = new LockValidator();
        $this->assertSame([], $validator->validate($lock), 'real lock must be valid');

        $badDigest = $lock;
        $badDigest['components'][0]['sha256'] = 'not-a-sha256';
        $this->assertContains(
            'component php-source has invalid sha256',
            $validator->validate($badDigest),
            'invalid digest must fail closed'
        );

        $badProvider = $lock;
        $badProvider['requiredExtensions']['event'][] = 'missing-provider';
        $this->assertContains(
            'extension event references unknown provider',
            $validator->validate($badProvider),
            'unknown extension provider must fail closed'
        );
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     */
    private function assertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': ' . json_encode($actual));
        }
    }

    /**
     * @param list<string> $values
     */
    private function assertContains(string $expected, array $values, string $message): void
    {
        if (!in_array($expected, $values, true)) {
            throw new RuntimeException($message . ': ' . json_encode($values));
        }
    }
}

