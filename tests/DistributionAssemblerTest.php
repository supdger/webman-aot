<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\DistributionAssembler;
use WebmanAot\Project\DistributionVerifier;
use WebmanAot\Project\ProjectMirror;
use WebmanAot\Project\ProjectWorkspace;
use WebmanAot\Project\SourceTreeSnapshot;

final class DistributionAssemblerTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-assemble-' . bin2hex(random_bytes(8));
        $project = $root . '/project';
        mkdir($project . '/app', 0700, true);
        mkdir($project . '/config', 0700, true);
        mkdir($project . '/public/storage', 0700, true);
        mkdir(
            $project . '/vendor/workerman/webman-framework/src/support',
            0700,
            true
        );
        $bootstrap = 'vendor/workerman/webman-framework/src/support/bootstrap.php';
        $helpers = 'vendor/workerman/webman-framework/src/support/helpers.php';
        $shadow = '.typephp/build/helpers.php';
        $files = [
            'composer.json' => "{\"name\":\"example/fixture\"}\n",
            'composer.lock' => "{\"packages\":[{\"name\":\"workerman/webman-framework\",\"version\":\"v2.2.4\"}],\"packages-dev\":[]}\n",
            'start.php' => "<?php\n",
            'app/Health.php' => "<?php class Health {}\n",
            'config/app.php' => "<?php return [];\n",
            'public/index.html' => "<html></html>\n",
            'public/storage/upload.txt' => "private upload\n",
            $bootstrap => "<?php function worker_start() {}\n",
            $helpers => "<?php function base_path() {}\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($project . '/' . $relative, $contents);
        }
        $lock = [
            'schema' => 'webman-aot-upstream-generator-lock-v1',
            'mappings' => [
                $helpers => [
                    'shadow' => $shadow,
                    'sourceSha256' => hash('sha256', $files[$helpers]),
                    'shadowSha256' => hash('sha256', "<?php function aot_base_path() {}\n"),
                ],
            ],
            'entrypointMapping' => [
                'source' => $bootstrap,
                'sourceSha256' => hash('sha256', $files[$bootstrap]),
                'replacement' => 'main.php',
                'replacementSha256' => hash('sha256', "<?php function main() {}\n"),
                'policy' => 'upstream.webman-bootstrap-entrypoint.v1',
            ],
            'dynamicPhp' => [],
        ];
        $compatibility = $root . '/compatibility.json';
        $toolchain = $root . '/toolchain.json';
        file_put_contents($compatibility, json_encode($lock, JSON_THROW_ON_ERROR));
        file_put_contents($toolchain, "{\"schema\":\"test\"}\n");
        try {
            $source = (new SourceTreeSnapshot($project))->capture();
            $paths = (new ProjectWorkspace($project))->prepare(
                str_repeat('1', 64),
                $source['sha256']
            );
            $mirror = (new ProjectMirror($project))->create($paths['build'])['path'];
            $this->write($mirror, $shadow, "<?php function aot_base_path() {}\n");
            $this->write($mirror, 'main.php', "<?php function main() {}\n");
            $this->write(
                $mirror,
                'project.linux.yml',
                "sources:\n  - main.php\n  - app\n  - {$shadow}\n"
                    . "ignore:\n  - {$bootstrap}\n  - {$helpers}\n"
            );
            $elf = $mirror . '/build/server';
            $this->write($mirror, 'build/server', $this->staticElf());
            $inputs = [
                'sourceTreeSha256' => $source['sha256'],
                'composerLockSha256' => (string) hash_file(
                    'sha256',
                    $project . '/composer.lock'
                ),
                'profile' => 'webman',
                'compatibilityLockSha256' => (string) hash_file(
                    'sha256',
                    $compatibility
                ),
                'toolchainLockSha256' => (string) hash_file('sha256', $toolchain),
                'normalizedInputSha256' => str_repeat('a', 64),
                'extensions' => ['pdo', 'pcntl'],
                'arguments' => ['--full-static'],
            ];
            $assembler = new DistributionAssembler(
                new DistributionVerifier(static fn (string $path): string => 'static')
            );
            $first = $assembler->assemble(
                $project,
                $mirror,
                $elf,
                $compatibility,
                $toolchain,
                $inputs,
                'macos-arm64',
                ['private upload']
            );
            $published = $project . '/dist-aot';
            $this->assert(
                str_replace('\\', '/', $first['path'])
                    === str_replace('\\', '/', $published)
                && ($first['verification']['compiledDirect'] ?? null) === 1
                && ($first['verification']['compiledShadow'] ?? null) === 2
                && is_file($published . '/public/index.html')
                && !file_exists($published . '/public/storage/upload.txt'),
                'verified candidate was not safely published'
            );
            $firstManifest = hash_file('sha256', $published . '/manifest.json');
            foreach ([
                'coverage',
                'candidate',
                'binary',
                'resources',
                'launchers',
                'manifest',
                'verify',
                'publish',
            ] as $stage) {
                $this->fails(
                    fn () => $assembler->assemble(
                        $project,
                        $mirror,
                        $elf,
                        $compatibility,
                        $toolchain,
                        $inputs,
                        'macos-arm64',
                        [],
                        static function (string $current) use ($stage): void {
                            if ($current === $stage) {
                                throw new ConfigurationException("injected {$stage} failure");
                            }
                        }
                    ),
                    "injected {$stage} failure"
                );
                $this->assert(
                    hash_file('sha256', $published . '/manifest.json') === $firstManifest,
                    "failed {$stage} stage replaced the published distribution"
                );
            }
            file_put_contents($mirror . '/config/app.php', "<?php return ['path' => '{$project}'];\n");
            $this->fails(
                fn () => $assembler->assemble(
                    $project,
                    $mirror,
                    $elf,
                    $compatibility,
                    $toolchain,
                    $inputs,
                    'macos-arm64'
                ),
                'runtime resource differs from original project'
            );
            $this->assert(
                hash_file('sha256', $published . '/manifest.json') === $firstManifest,
                'runtime resource drift replaced the published distribution'
            );
            file_put_contents($mirror . '/config/app.php', $files['config/app.php']);
            file_put_contents($elf, $this->staticElf() . $project);
            $this->fails(
                fn () => $assembler->assemble(
                    $project,
                    $mirror,
                    $elf,
                    $compatibility,
                    $toolchain,
                    $inputs,
                    'macos-arm64'
                ),
                PHP_OS_FAMILY === 'Windows'
                    ? 'private host path'
                    : 'sensitive marker'
            );
            $this->assert(
                hash_file('sha256', $published . '/manifest.json') === $firstManifest,
                'private build path replaced the published distribution'
            );
            file_put_contents($elf, $this->staticElf());
            $this->fails(
                fn () => $assembler->assemble(
                    $project,
                    $mirror,
                    $elf,
                    $compatibility,
                    $toolchain,
                    $inputs,
                    'macos-arm64',
                    [],
                    static function (string $stage) use ($project): void {
                        if ($stage === 'publish') {
                            file_put_contents(
                                $project . '/app/Health.php',
                                "<?php class ChangedHealth {}\n"
                            );
                        }
                    }
                ),
                'source changed during distribution assembly'
            );
            $this->assert(
                hash_file('sha256', $published . '/manifest.json') === $firstManifest,
                'source drift replaced the published distribution'
            );
            file_put_contents($project . '/app/Health.php', $files['app/Health.php']);
            $second = $assembler->assemble(
                $project,
                $mirror,
                $elf,
                $compatibility,
                $toolchain,
                $inputs,
                'macos-arm64'
            );
            $this->assert(
                is_string($second['previous'])
                && is_file($second['previous'] . '/manifest.json')
                && is_file($published . '/manifest.json'),
                'successful replacement did not retain the previous distribution'
            );
            echo "[PASS] staged distribution assembly verifies before atomic publish and preserves prior output on stage failure\n";
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function write(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function staticElf(): string
    {
        $header = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9);
        $header .= pack('v', 2) . pack('v', 62) . pack('V', 1);
        $header .= pack('V2', 0, 0) . pack('V2', 64, 0) . pack('V2', 0, 0);
        $header .= pack('V', 0);
        $header .= pack('v', 64) . pack('v', 56) . pack('v', 1);
        $header .= pack('v', 0) . pack('v', 0) . pack('v', 0);
        return $header . pack('V', 1) . pack('V', 0) . str_repeat("\0", 48);
    }

    private function fails(Closure $action, string $expected): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $expected),
                "unexpected assembly failure: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("distribution assembly accepted {$expected}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
