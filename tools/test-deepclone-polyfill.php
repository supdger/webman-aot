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
    checkDeepClone(count($mappings) === 5, 'conditional declarations and complete DeepClone implementation must be mapped');
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
    echo "PASS: original failure reproduced; complete deepclone package preprocesses; drift rejected\n";

    $probe = <<<'PROBE'
<?php
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
class FirstObject { public string $name = 'first'; private int $secret = 7; public function secret(): int { return $this->secret; } }
class SecondObject { public string $name = 'second'; }
class ScopeRoot { private int $same = 1; protected int $protected = 2; }
class ScopeMiddle extends ScopeRoot { private int $same = 3; }
class ScopeLeaf extends ScopeMiddle { private int $same = 4; }
$scopesMethod = new ReflectionMethod(Symfony\Polyfill\DeepClone\DeepClone::class, 'getPropertyScopes');
$scopes = [];
foreach ([ScopeRoot::class, ScopeMiddle::class, ScopeLeaf::class, SecondObject::class] as $scopeClass) {
    $scopes[$scopeClass] = $scopesMethod->invoke(null, new ReflectionClass($scopeClass));
}

$shared = (object) ['counter' => 1];
$value = (object) ['name' => 'roundtrip', 'nested' => [new FirstObject(), new SecondObject()],
    'left' => $shared, 'right' => $shared];
$encoded = deepclone_to_array($value);
$copy = deepclone_from_array($encoded);
$countEncoded = deepclone_to_array((object) ['single' => 'count branch']);
$hydrated = deepclone_hydrate(FirstObject::class, ["\0FirstObject\0secret" => 9]);
echo json_encode([is_array($encoded['objectMeta']), is_int($countEncoded['objectMeta']),
    $encoded, $countEncoded, $copy == $value, $copy !== $value, $copy->left === $copy->right,
    $copy->left !== $shared, DEEPCLONE_HYDRATE_CALL_HOOKS,
    DEEPCLONE_HYDRATE_NO_LAZY_INIT, DEEPCLONE_HYDRATE_PRESERVE_REFS,
    get_parent_class(DeepClone\ClassNotFoundException::class),
    get_parent_class(DeepClone\NotInstantiableException::class), $scopes, $hydrated->secret()]);
PROBE;
    file_put_contents($root . '/probe.php', $probe);
    $results = [];
    foreach (['original', 'adapted'] as $mode) {
        $paths = $mode === 'original'
            ? [$mirror . $prefix . 'bootstrap81.php', $mirror . $prefix . 'Resources/stubs/ClassNotFoundException.php',
                $mirror . $prefix . 'Resources/stubs/NotInstantiableException.php']
            : [$mirror . '/.typephp/build/deepclone-bootstrap81.php', $mirror . '/.typephp/build/deepclone-ClassNotFoundException.php',
                $mirror . '/.typephp/build/deepclone-NotInstantiableException.php'];
        $process = proc_open([PHP_BINARY, $root . '/probe.php', ...$paths, ($mode === 'original' ? $mirror . $prefix . 'DeepClone.php' : $mirror . '/.typephp/build/deepclone-DeepClone.php')],
            [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        checkDeepClone(is_resource($process), 'probe process failed');
        $results[$mode] = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        checkDeepClone(proc_close($process) === 0, 'behavior probe failed');
    }
    checkDeepClone($results['original'] === $results['adapted']
        && str_starts_with($results['adapted'], '[true,true,'), 'fallback behavior differs');
    $decoded = json_decode($results['adapted'], true, flags: JSON_THROW_ON_ERROR);
    checkDeepClone(array_slice($decoded, 4, 4) === [true, true, true, true]
        && end($decoded) === 9, 'identity or scoped hydration differs');
    checkDeepClone(count($decoded[count($decoded) - 2]['ScopeLeaf']) === 6
        && $decoded[count($decoded) - 2]['SecondObject'] === ['name' => ['SecondObject', 'name']],
        'three-level or parentless property scopes differ');
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
