<?php

declare(strict_types=1);

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Project\GeneratedProjectCoveragePlanner;
use WebmanAot\Project\ProjectProfile;

final class GeneratedProjectCoveragePlannerTest
{
    public function run(): void
    {
        $root = sys_get_temp_dir() . '/webman-aot-generated-coverage-'
            . bin2hex(random_bytes(8));
        $mirror = $root . '/.webman-aot/build/project';
        $bootstrap = 'vendor/workerman/webman-framework/src/support/bootstrap.php';
        $helpers = 'vendor/workerman/webman-framework/src/support/helpers.php';
        $shadow = '.typephp/build/helpers.php';
        $files = [
            'app/Controller/HealthController.php' => "<?php class HealthController {}\n",
            'config/app.php' => "<?php return [];\n",
            $bootstrap => "<?php function worker_start() {}\n",
            'support/bootstrap.php' => "<?php function worker_start() {}\n",
            $helpers => "<?php function base_path() {}\n",
            $shadow => "<?php function aot_base_path() {}\n",
            'main.php' => "<?php function main() {}\n",
            'composer.lock' => "{\"packages\":[],\"packages-dev\":[]}\n",
        ];
        foreach ($files as $relative => $contents) {
            $path = $mirror . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $contents);
        }
        $project = "sources:\n  - main.php\n"
            . "  - app\n  - {$shadow}\nignore:\n"
            . "  - {$bootstrap}\n  - support/bootstrap.php\n  - {$helpers}\n";
        file_put_contents($mirror . '/project.linux.yml', $project);
        $lock = [
            'schema' => 'webman-aot-upstream-generator-lock-v1',
            'mappings' => [
                $helpers => [
                    'shadow' => $shadow,
                    'sourceSha256' => hash('sha256', $files[$helpers]),
                    'shadowSha256' => hash('sha256', $files[$shadow]),
                ],
            ],
            'entrypointMapping' => [
                'source' => $bootstrap,
                'sourceSha256' => hash('sha256', $files[$bootstrap]),
                'replacement' => 'main.php',
                'replacementSha256' => hash('sha256', $files['main.php']),
                'policy' => 'upstream.webman-bootstrap-entrypoint.v1',
            ],
            'dynamicPhp' => [],
        ];
        $lockFile = $root . '/compatibility.json';
        file_put_contents($lockFile, json_encode($lock, JSON_THROW_ON_ERROR));
        $planner = new GeneratedProjectCoveragePlanner();
        $profile = new ProjectProfile(ProjectProfile::WEBMAN, [], []);
        try {
            $result = $planner->plan($mirror, $profile, $lockFile);
            $this->assert(
                $result['compiled'] === ['direct' => 1, 'shadow' => 3]
                && $result['coverage']->counts() === [
                    'compiled-direct' => 1,
                    'compiled-shadow' => 3,
                    'runtime-approved' => 1,
                ]
                && count($result['resources']->entries()) === 4,
                'generated project coverage did not close the fixture'
            );
            file_put_contents($mirror . '/project.linux.yml', str_replace(
                "  - app\n",
                '',
                $project
            ));
            $this->fails(
                fn () => $planner->plan($mirror, $profile, $lockFile),
                'not directly compiled'
            );
            file_put_contents($mirror . '/project.linux.yml', $project);
            file_put_contents($mirror . '/' . $shadow, $files[$shadow] . "// drift\n");
            $this->fails(
                fn () => $planner->plan($mirror, $profile, $lockFile),
                'shadow mapping drifted'
            );
            file_put_contents($mirror . '/' . $shadow, $files[$shadow]);
            file_put_contents($mirror . '/' . $bootstrap, $files[$bootstrap] . "// drift\n");
            $this->fails(
                fn () => $planner->plan($mirror, $profile, $lockFile),
                'entrypoint mapping drifted'
            );
            file_put_contents($mirror . '/' . $bootstrap, $files[$bootstrap]);
            file_put_contents(
                $mirror . '/support/bootstrap.php',
                $files['support/bootstrap.php'] . "// drift\n"
            );
            $this->fails(
                fn () => $planner->plan($mirror, $profile, $lockFile),
                'project bootstrap differs'
            );
            file_put_contents($mirror . '/support/bootstrap.php', $files['support/bootstrap.php']);
            $this->testCaptchaFonts($mirror, $lock, $planner);
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

    private function testCaptchaFonts(string $mirror, array $lock, GeneratedProjectCoveragePlanner $planner): void
    {
        $directory = $mirror . '/vendor/webman/captcha/src';
        mkdir($directory . '/Font', 0700, true);
        $builder = "<?php\n\$font = __DIR__ . '/Font/captcha'.\$this->rand(0, 4).'.ttf';\n";
        file_put_contents($directory . '/CaptchaBuilder.php', $builder);
        $fonts = [];
        for ($index = 0; $index < 5; $index++) {
            $name = "captcha{$index}.ttf";
            $data = "fixture-font-{$index}\n";
            file_put_contents($directory . '/Font/' . $name, $data);
            $fonts[$name] = hash('sha256', $data);
        }
        $lock['runtimeResources']['webman/captcha'] = [
            'version' => 'v1.0.5',
            'reference' => str_repeat('a', 40),
            'builderSha256' => hash('sha256', $builder),
            'fonts' => $fonts,
        ];
        $composer = [
            'packages' => [[
                'name' => 'webman/captcha',
                'version' => 'v1.0.5',
                'source' => ['reference' => str_repeat('a', 40)],
            ]],
            'packages-dev' => [],
        ];
        file_put_contents($mirror . '/composer.lock', json_encode($composer, JSON_THROW_ON_ERROR));
        $method = new ReflectionMethod($planner, 'requiredRuntimeFiles');
        $required = $method->invoke($planner, $mirror, $lock);
        $this->assert(count($required) === 5
            && $required[0]['role'] === 'font'
            && $required[4]['path'] === 'vendor/webman/captcha/src/Font/captcha4.ttf',
            'locked captcha fonts were not declared');
        file_put_contents($directory . '/Font/captcha4.ttf', 'drift');
        $this->fails(
            fn () => $method->invoke($planner, $mirror, $lock),
            'font digest drifted'
        );
        file_put_contents($directory . '/Font/captcha4.ttf', "fixture-font-4\n");
        $composer['packages'][0]['version'] = 'v1.0.6';
        file_put_contents($mirror . '/composer.lock', json_encode($composer, JSON_THROW_ON_ERROR));
        $this->fails(
            fn () => $method->invoke($planner, $mirror, $lock),
            'runtime font policy drifted'
        );
        file_put_contents($mirror . '/composer.lock', "{\"packages\":[],\"packages-dev\":[]}\n");
    }

    private function fails(Closure $action, string $message): void
    {
        try {
            $action();
        } catch (ConfigurationException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), $message),
                "unexpected generated coverage error: {$exception->getMessage()}"
            );
            return;
        }
        throw new RuntimeException("generated coverage did not reject {$message}");
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
