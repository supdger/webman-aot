#!/usr/bin/env php
<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\DeepClonePolyfillRule;

// Use an installed, locked Symfony source and a real TypePHP 0.9.2 tree.
// php tools/test-deepclone-polyfill.php /path/to/project /path/to/typephp
$repo = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repo): void {
    if (str_starts_with($class, 'WebmanAotBuilder\\')) {
        require $repo . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
    }
});
function checkDeepClone(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$project = $argv[1] ?? '';
$typephp = $argv[2] ?? '';
checkDeepClone(is_file($project . '/composer.lock') && is_file($typephp . '/bin/bootstrap.php'),
    'Pass a project with the locked Symfony polyfill and a TypePHP 0.9.2 source directory');
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-deepclone-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/project';
mkdir($mirror . '/.typephp/build', 0700, true);
mkdir($mirror . '/vendor/symfony/polyfill-deepclone/Resources/stubs', 0700, true);
$policy = json_decode(file_get_contents($repo . '/compatibility/locks/webman-workerman-2026-09-25.json'), true,
    flags: JSON_THROW_ON_ERROR)['optionalAdaptations']['symfony/polyfill-deepclone'];
$prefix = '/vendor/symfony/polyfill-deepclone/';
try {
    copy($project . '/composer.lock', $mirror . '/composer.lock');
    foreach ($policy['files'] as $name => $digest) {
        copy($project . $prefix . $name, $mirror . $prefix . $name);
    }
    file_put_contents($mirror . '/project.linux.yml', "sources:\n  - vendor\n\nignore:\n  - main.php\n\noutput: test\n");
    $rule = new DeepClonePolyfillRule();
    try {
        $rule->apply($mirror, $policy, str_repeat('0', 64));
        throw new RuntimeException('target drift must fail');
    } catch (ConfigurationException $exception) {
        checkDeepClone(str_contains($exception->getMessage(), 'target lock'), 'wrong target rejection');
    }
    $stub = $mirror . $prefix . 'Resources/stubs/ClassNotFoundException.php';
    $original = file_get_contents($stub);
    file_put_contents($stub, $original . "// drift\n");
    try {
        $rule->apply($mirror, $policy, hash_file('sha256', $repo . '/toolchain.lock.json'));
        throw new RuntimeException('source drift must fail');
    } catch (ConfigurationException $exception) {
        checkDeepClone(str_contains($exception->getMessage(), 'source drifted'), 'wrong source rejection');
    }
    file_put_contents($stub, $original);
    $mappings = $rule->apply($mirror, $policy, hash_file('sha256', $repo . '/toolchain.lock.json'));
    checkDeepClone(count($mappings) === 4, 'all four conditional declaration files must be mapped');
    require $typephp . '/bin/bootstrap.php';
    $preprocessor = new TypePhp\Preprocessor($root);
    $preprocessor->setDiagnosticReporter(new TypePhp\Diagnostics\ThrowingDiagnosticReporter());
    try {
        $preprocessor->prepareFile($stub);
        throw new RuntimeException('original conditional declaration must reproduce stray code');
    } catch (TypePhp\Exception\TestError $exception) {
        checkDeepClone(str_contains($exception->getMessage(), 'stray code'), 'wrong preprocessor failure');
    }
    $preprocessor = new TypePhp\Preprocessor($root);
    $preprocessor->setDiagnosticReporter(new TypePhp\Diagnostics\ThrowingDiagnosticReporter());
    foreach ($mappings as $mapping) {
        checkDeepClone(hash_file('sha256', $mirror . '/' . $mapping['path']) === $mapping['sourceSha256'],
            'vendor source must remain intact');
        $preprocessor->prepareFile($mirror . '/' . $mapping['shadow']);
    }
    $preprocessor->prepareFile($mirror . $prefix . 'DeepClone.php');
    echo "PASS: original failure reproduced; complete deepclone package preprocesses; drift rejected\n";

    $probe = <<<'PROBE'
<?php
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
$value = (object) ['name' => 'roundtrip', 'nested' => [1, 2]];
$copy = deepclone_from_array(deepclone_to_array($value));
echo json_encode([$copy == $value, $copy !== $value, DEEPCLONE_HYDRATE_CALL_HOOKS,
    DEEPCLONE_HYDRATE_NO_LAZY_INIT, DEEPCLONE_HYDRATE_PRESERVE_REFS,
    get_parent_class(DeepClone\ClassNotFoundException::class),
    get_parent_class(DeepClone\NotInstantiableException::class)]);
PROBE;
    file_put_contents($root . '/probe.php', $probe);
    $results = [];
    foreach (['original', 'adapted'] as $mode) {
        $paths = $mode === 'original'
            ? [$mirror . $prefix . 'bootstrap81.php', $mirror . $prefix . 'Resources/stubs/ClassNotFoundException.php',
                $mirror . $prefix . 'Resources/stubs/NotInstantiableException.php']
            : [$mirror . '/.typephp/build/deepclone-bootstrap81.php', $mirror . '/.typephp/build/deepclone-ClassNotFoundException.php',
                $mirror . '/.typephp/build/deepclone-NotInstantiableException.php'];
        $process = proc_open([PHP_BINARY, $root . '/probe.php', ...$paths, $mirror . $prefix . 'DeepClone.php'],
            [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        checkDeepClone(is_resource($process), 'probe process failed');
        $results[$mode] = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        checkDeepClone(proc_close($process) === 0, 'behavior probe failed');
    }
    checkDeepClone($results['original'] === $results['adapted']
        && str_starts_with($results['adapted'], '[true,true,1,2,4,'), 'fallback behavior differs');
    echo 'PASS: original/adapted PHP roundtrip, identity, constants and exception ancestry match; elapsed '
        . number_format(microtime(true) - $started, 2) . "s\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
