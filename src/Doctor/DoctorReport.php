<?php

declare(strict_types=1);

namespace WebmanAot\Doctor;

final class DoctorReport
{
    /**
     * @param list<array{
     *     id:string,
     *     status:string,
     *     message:string,
     *     details:array<string, mixed>
     * }> $checks
     */
    public function __construct(
        private readonly string $host,
        private readonly array $checks
    ) {
    }

    public function healthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['status'] !== 'ok') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     schema:string,
     *     healthy:bool,
     *     host:string,
     *     checks:list<array{
     *         id:string,
     *         status:string,
     *         message:string,
     *         details:array<string, mixed>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => 'webman-aot-doctor-v1',
            'healthy' => $this->healthy(),
            'host' => $this->host,
            'checks' => $this->checks,
        ];
    }

    public function toHuman(): string
    {
        $lines = ['Webman AOT Doctor', 'Host: ' . $this->host, ''];
        foreach ($this->checks as $check) {
            $label = $check['status'] === 'ok' ? 'OK' : 'ERROR';
            $lines[] = sprintf('[%s] %s: %s', $label, $check['id'], $check['message']);
        }
        $lines[] = '';
        $lines[] = $this->healthy() ? 'Result: healthy' : 'Result: unhealthy';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
