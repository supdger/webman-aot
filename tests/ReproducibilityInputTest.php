<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Toolchain/ReproducibilityInput.php';

use WebmanAot\Toolchain\ReproducibilityInput;

final class ReproducibilityInputTest
{
    public function run(string $root): void
    {
        $first = (new ReproducibilityInput())->describe($root);
        $second = (new ReproducibilityInput())->describe($root);
        $this->assert($first === $second, 'normalized reproducibility input must be deterministic');
        $this->assert(
            preg_match('/^[a-f0-9]{64}$/D', $first['sha256']) === 1,
            'normalized reproducibility input digest is invalid'
        );
        $this->assert(
            ($first['input']['build']['targetTriple'] ?? null) === 'x86_64-unknown-linux-musl',
            'normalized reproducibility target drifted'
        );
        $this->assert(
            count($first['input']['patches'] ?? []) === 4,
            'normalized reproducibility input must include all TypePHP patches'
        );
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
