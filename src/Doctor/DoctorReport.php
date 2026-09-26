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
        $missingComponents = 0;
        $repairable = false;
        $otherErrors = false;
        $profile = null;
        foreach ($this->checks as $check) {
            if ($check['status'] === 'ok') {
                if ($check['id'] === 'project') {
                    $profile = $check['details']['profile'] ?? null;
                }
                continue;
            }
            if (str_starts_with($check['id'], 'component:')) {
                $repairable = true;
                if ($check['message'] === 'locked component is missing') {
                    $missingComponents++;
                }
            } elseif ($check['id'] === 'prepared-toolchain') {
                $repairable = true;
            } else {
                $otherErrors = true;
            }
        }

        $missingSummaryPrinted = false;
        foreach ($this->checks as $check) {
            if ($check['status'] !== 'ok'
                && str_starts_with($check['id'], 'component:')
                && $check['message'] === 'locked component is missing'
            ) {
                if (!$missingSummaryPrinted) {
                    $lines[] = sprintf(
                        '[ERROR] components: %d locked %s not downloaded yet',
                        $missingComponents,
                        $missingComponents === 1 ? 'component is' : 'components are'
                    );
                    $missingSummaryPrinted = true;
                }
                continue;
            }
            $label = $check['status'] === 'ok' ? 'OK' : 'ERROR';
            $lines[] = sprintf('[%s] %s: %s', $label, $check['id'], $check['message']);
        }
        $lines[] = '';
        $lines[] = $this->healthy() ? 'Result: healthy' : 'Result: unhealthy';
        if ($this->healthy()) {
            $lines[] = 'Next: run webman-aot build'
                . ($profile === 'saiadmin' ? ' --profile=saiadmin' : '')
                . ', then webman-aot verify.';
        } else {
            if ($otherErrors) {
                $lines[] = 'Fix the other [ERROR] checks above; repair alone may not resolve them.';
            }
            if ($repairable) {
                $lines[] = 'Run webman-aot doctor to prepare missing compiler components automatically.';
            }
            $lines[] = 'Build only when Result: healthy.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
