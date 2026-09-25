<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\DistributionPublisher;
use WebmanAot\Project\ProjectWorkspace;

final class DistributionPublisherTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-publish-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $paths = (new ProjectWorkspace($root))->prepare(
                str_repeat('1', 64),
                str_repeat('2', 64)
            );
            $publisher = new DistributionPublisher($root);
            $first = $this->candidate($paths['build'], 'aaaaaaaaaaaaaaaa', 'first');
            $this->fails(
                fn () => $publisher->publish(
                    $first,
                    static function (): void {
                        throw new ConfigurationException('verification failed');
                    }
                ),
                'verification failed'
            );
            $this->assert(
                is_file($first . '/manifest.json') && !file_exists($root . '/dist-aot'),
                'failed verification promoted a candidate'
            );
            $result = $publisher->publish(
                $first,
                static function (string $path): void {
                    if (!is_file($path . '/payload.txt')) {
                        throw new ConfigurationException('payload missing');
                    }
                }
            );
            $this->assert(
                $result['previous'] === null
                && file_get_contents($root . '/dist-aot/payload.txt') === 'first',
                'first verified distribution was not published'
            );

            $second = $this->candidate($paths['build'], 'bbbbbbbbbbbbbbbb', 'second');
            $moves = 0;
            $failingPublisher = new DistributionPublisher(
                $root,
                static function (string $from, string $to) use (&$moves): bool {
                    $moves++;
                    return $moves === 2 ? false : rename($from, $to);
                }
            );
            $this->fails(
                fn () => $failingPublisher->publish($second, static function (): void {}),
                'unable to publish'
            );
            $this->assert(
                $moves === 3
                && file_get_contents($root . '/dist-aot/payload.txt') === 'first'
                && file_get_contents($second . '/payload.txt') === 'second',
                'failed replacement lost the previous distribution or candidate'
            );

            $result = $publisher->publish($second, static function (): void {});
            $this->assert(
                file_get_contents($root . '/dist-aot/payload.txt') === 'second'
                && is_string($result['previous'])
                && file_get_contents($result['previous'] . '/payload.txt') === 'first',
                'successful replacement did not retain the previous distribution'
            );
            $unmarked = $this->candidate($paths['build'], 'cccccccccccccccc', 'third');
            unlink($unmarked . '/manifest.json');
            $this->fails(
                fn () => $publisher->publish($unmarked, static function (): void {}),
                'manifest is missing'
            );
            $this->assert(
                file_get_contents($root . '/dist-aot/payload.txt') === 'second',
                'unmarked candidate changed the active distribution'
            );
            $outside = $root . '/outside';
            mkdir($outside, 0700);
            file_put_contents(
                $outside . '/manifest.json',
                "{\"schema\":\"webman-aot-distribution-v1\"}\n"
            );
            $this->fails(
                fn () => $publisher->publish($outside, static function (): void {}),
                'outside the owned build workspace'
            );
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function candidate(string $build, string $suffix, string $payload): string
    {
        $path = $build . '/dist-candidate-' . $suffix;
        mkdir($path, 0700);
        file_put_contents(
            $path . '/manifest.json',
            "{\"schema\":\"webman-aot-distribution-v1\"}\n"
        );
        file_put_contents($path . '/payload.txt', $payload);
        return $path;
    }

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected distribution failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("distribution did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
