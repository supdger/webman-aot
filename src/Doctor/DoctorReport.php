<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Doctor;

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
            'schema' => 'webman-aot-builder-doctor-v1',
            'healthy' => $this->healthy(),
            'host' => $this->host,
            'checks' => $this->checks,
        ];
    }

    public function toHuman(): string
    {
        $lines = ['Webman AOT Builder Doctor', 'Host: ' . $this->host, ''];
        $missingComponents = 0;
        $repairable = false;
        $otherErrors = false;
        $networkProbeFailed = false;
        foreach ($this->checks as $check) {
            if ($check['status'] === 'ok') {
                continue;
            }
            if (str_starts_with($check['id'], 'component:')) {
                $repairable = true;
                if ($check['message'] === 'locked component is missing') {
                    $missingComponents++;
                }
            } elseif (in_array($check['id'], ['prepared-toolchain', 'minimal-component'], true)) {
                $repairable = true;
            } elseif ($check['id'] === 'network-tcp') {
                $networkProbeFailed = true;
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
            $lines[] = 'Next: change to your Webman project root, run webman-aot build,'
                . ' then webman-aot verify.';
        } else {
            if ($networkProbeFailed) {
                $lines[] = 'The TCP probe may fail behind a proxy; an archive download can still succeed.';
            }
            if ($otherErrors) {
                $lines[] = 'Fix the other [ERROR] checks above before preparing components.';
            }
            if ($repairable && !$otherErrors) {
                $lines[] = 'Run webman-aot doctor to prepare missing compiler components automatically.';
            } elseif ($repairable) {
                $lines[] = 'Then run webman-aot doctor to prepare missing compiler components.';
            }
            $lines[] = 'Build only when Result: healthy.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
