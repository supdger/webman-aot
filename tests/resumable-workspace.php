<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectBuildLease;
use WebmanAotBuilder\Project\ProjectMirror;
use WebmanAotBuilder\Project\ProjectWorkspace;
use WebmanAotBuilder\Toolchain\CompileInputFingerprint;

function ensure(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
if (($argv[1] ?? '') === '--writer') {
    file_put_contents($argv[2] . '/ready', 'ready');
    while (!is_file($argv[2] . '/continue')) { usleep(10000); }
    file_put_contents($argv[2] . '/project/object', 'old writer completed');
    exit(0);
}
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-resume-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$lease = new ProjectBuildLease($root);
$workspace = new ProjectWorkspace($root);
$paths = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64));
try { new ProjectBuildLease($root); throw new RuntimeException('concurrent lease accepted'); }
catch (ConfigurationException $expected) { ensure(str_contains($expected->getMessage(), '正在构建'), 'concurrent error lacks recovery'); }
try { $workspace->cleanTransient(); throw new RuntimeException('concurrent cleanup accepted'); }
catch (ConfigurationException $expected) { ensure(str_contains($expected->getMessage(), '正在构建'), 'cleanup refusal lost recovery advice'); }
unset($lease);
$old = $paths['build']; mkdir($old . '/project');
$child = proc_open([PHP_BINARY, __FILE__, '--writer', $old], [STDIN, STDOUT, STDERR], $pipes);
ensure(is_resource($child), 'cannot start old attempt writer');
while (!is_file($old . '/ready')) { usleep(10000); }
$lease = new ProjectBuildLease($root);
$new = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64))['build']; mkdir($new . '/project');
ensure($old !== $new && ProjectMirror::isOwnedPath($new . '/project', $root), 'attempt ownership invalid');
file_put_contents($old . '/continue', 'go'); ensure(proc_close($child) === 0, 'old writer failed');
ensure(is_file($old . '/project/object') && !file_exists($new . '/project/object'), 'old writer contaminated new attempt');
ensure(!ProjectMirror::isOwnedPath($new . '/project', dirname($root)), 'foreign project ownership accepted');
unset($lease);
$tools = [];
foreach (['php', 'compiler', 'objcopy'] as $name) {
    $directory = $root . '/tools/' . ($name === 'php' ? 'php' : 'llvm/bin');
    if (!is_dir($directory)) { mkdir($directory, 0700, true); }
    $tools[$name] = $directory . '/' . $name; file_put_contents($tools[$name], $name);
}
foreach (['typephp', 'phpx', 'sysroot', 'phprc'] as $name) {
    $tools[$name] = $root . '/tools/' . $name; mkdir($tools[$name], 0700, true); file_put_contents($tools[$name] . '/header', $name);
}
$fingerprint = new CompileInputFingerprint(); $before = $fingerprint->digest($tools, ['flags' => '-O2']);
foreach (['compiler', 'phpx', 'sysroot', 'phprc'] as $name) {
    $file = is_dir($tools[$name]) ? $tools[$name] . '/header' : $tools[$name]; $contents = file_get_contents($file);
    file_put_contents($file, $contents . '-changed'); ensure($fingerprint->digest($tools, ['flags' => '-O2']) !== $before, "{$name} bytes failed to invalidate"); file_put_contents($file, $contents);
}
ensure($fingerprint->digest($tools, ['flags' => '-O3']) !== $before, 'compile flags failed to invalidate');
$workspace->remove();
ensure(is_file($root . '/.webman-aot-builder/build.lock'), 'cleanup unlinked lock inode');
fwrite(STDOUT, sprintf("PASS concurrent build/cleanup refusal, old writer isolation, attempt ownership, actual compiler/header/config and flags invalidation (%.2fs)\nFixture: %s\n", microtime(true) - $started, $root));
