<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

final class StageTracker
{
    /**
     * @var array<string, array{
     *     name:string,
     *     status:string,
     *     startedAt:?string,
     *     finishedAt:?string,
     *     error:?string
     * }>
     */
    private array $records = [];

    /**
     * @param list<string> $stages
     */
    public function __construct(array $stages)
    {
        foreach ($stages as $stage) {
            if ($stage === '' || isset($this->records[$stage])) {
                throw new \InvalidArgumentException("invalid or duplicate stage: {$stage}");
            }
            $this->records[$stage] = [
                'name' => $stage,
                'status' => StageStatus::Pending->value,
                'startedAt' => null,
                'finishedAt' => null,
                'error' => null,
            ];
        }
    }

    public function start(string $stage): void
    {
        $this->assertStatus($stage, StageStatus::Pending);
        $this->records[$stage]['status'] = StageStatus::Running->value;
        $this->records[$stage]['startedAt'] = self::timestamp();
    }

    public function succeed(string $stage): void
    {
        $this->assertStatus($stage, StageStatus::Running);
        $this->records[$stage]['status'] = StageStatus::Succeeded->value;
        $this->records[$stage]['finishedAt'] = self::timestamp();
    }

    public function fail(string $stage, \Throwable $throwable): void
    {
        $this->assertStatus($stage, StageStatus::Running);
        $this->records[$stage]['status'] = StageStatus::Failed->value;
        $this->records[$stage]['finishedAt'] = self::timestamp();
        $this->records[$stage]['error'] = $throwable::class;
    }

    /**
     * @return list<array{
     *     name:string,
     *     status:string,
     *     startedAt:?string,
     *     finishedAt:?string,
     *     error:?string
     * }>
     */
    public function snapshot(): array
    {
        return array_values($this->records);
    }

    private function assertStatus(string $stage, StageStatus $expected): void
    {
        $actual = $this->records[$stage]['status'] ?? null;
        if ($actual !== $expected->value) {
            throw new \LogicException(sprintf(
                'stage %s must be %s, got %s',
                $stage,
                $expected->value,
                is_string($actual) ? $actual : 'missing'
            ));
        }
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
