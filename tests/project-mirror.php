<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectMirror;
use WebmanAotBuilder\Project\SourceTreeSnapshot;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeFixture(string $path): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

if (($argv[1] ?? null) === '--writer') {
    $root = $argv[2];
    $deadline = microtime(true) + 10;
    do {
        $candidates = glob($root . '/.webman-aot-builder/build/.project-*') ?: [];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/c-modified.php')) {
                file_put_contents($root . '/a-added.php', '<?php // added');
                unlink($root . '/b-removed.php');
                file_put_contents($root . '/c-modified.php', '<?php // modified');
                file_put_contents($root . "/d-control\n.php", '<?php // modified');
                file_put_contents($root . '/e-' . str_repeat('x', 130) . '.php', '<?php // modified');
                for ($index = 0; $index < 8; ++$index) {
                    file_put_contents($root . "/f-{$index}.php", '<?php // modified');
                }
                exit(0);
            }
        }
        usleep(1000);
    } while (microtime(true) < $deadline);
    fwrite(STDERR, "writer did not observe mirror copy\n");
    exit(1);
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-mirror-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$writer = null;
try {
    mkdir($root . '/nested');
    mkdir($root . '/.webman-aot-builder/build', 0700, true);
    file_put_contents($root . '/source.php', '<?php // source');
    file_put_contents($root . '/.custom', 'retained hidden input');
    file_put_contents($root . '/.DS_Store', 'metadata one');
    file_put_contents($root . '/nested/.DS_Store', 'metadata two');
    $snapshot = new SourceTreeSnapshot($root);
    $before = $snapshot->capture();
    file_put_contents($root . '/.DS_Store', 'metadata changed');
    unlink($root . '/nested/.DS_Store');
    check($snapshot->capture() === $before, 'Finder metadata changed source fingerprint');
    file_put_contents($root . '/nested/.DS_Store', 'metadata recreated before mirror');
    $mirror = (new ProjectMirror($root))->create($root . '/.webman-aot-builder/build');
    check($mirror['files'] === 2, 'mirror omitted a source or included metadata');
    check(!file_exists($mirror['path'] . '/.DS_Store'), 'root metadata entered mirror');
    check(!file_exists($mirror['path'] . '/nested/.DS_Store'), 'nested metadata entered mirror');
    check(is_file($mirror['path'] . '/.custom'), 'other hidden inputs were excluded');
    file_put_contents($root . '/source.php', '<?php // changed');
    check($snapshot->capture() !== $before, 'PHP content change was ignored');
    $changed = $snapshot->capture();
    file_put_contents($root . '/added.php', '<?php');
    check($snapshot->capture() !== $changed, 'PHP addition was ignored');
    unlink($root . '/added.php');
    check($snapshot->capture() === $changed, 'PHP removal was ignored');
    fwrite(STDOUT, "PASS metadata exclusion, hidden input retention, PHP modification/addition/removal\n");
    removeFixture($root);
    mkdir($root, 0700);
    mkdir($root . '/.webman-aot-builder/build', 0700, true);
    file_put_contents($root . '/b-removed.php', '<?php');
    file_put_contents($root . '/c-modified.php', '<?php');
    file_put_contents($root . "/d-control\n.php", '<?php');
    file_put_contents($root . '/e-' . str_repeat('x', 130) . '.php', '<?php');
    for ($index = 0; $index < 8; ++$index) {
        file_put_contents($root . "/f-{$index}.php", '<?php');
    }
    mkdir($root . '/padding');
    for ($index = 0; $index < 3000; ++$index) {
        file_put_contents($root . "/padding/{$index}", 'copy time for concurrent writer');
    }
    $writer = proc_open(
        [PHP_BINARY, __FILE__, '--writer', $root],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    check(is_resource($writer), 'unable to start concurrent writer');
    fclose($pipes[0]);
    try {
        (new ProjectMirror($root))->create($root . '/.webman-aot-builder/build');
        throw new RuntimeException('mirror accepted concurrent source changes');
    } catch (ConfigurationException $error) {
        $message = $error->getMessage();
        check(str_contains($message, 'added "a-added.php"'), 'addition path missing');
        check(str_contains($message, 'removed "b-removed.php"'), 'removal path missing');
        check(str_contains($message, 'modified "c-modified.php"'), 'modification path missing');
        check(str_contains($message, 'd-control\\n.php'), 'control characters were not escaped');
        check(!str_contains($message, "\n"), 'diagnostic contains a raw newline');
        check(!str_contains($message, $root), 'diagnostic exposes absolute fixture path');
        check(str_contains($message, '(+8 more)'), 'diagnostic did not cap changed path count');
        check(strlen($message) < 1000, 'diagnostic is unbounded');
        check(str_contains($message, 'retry webman-aot build'), 'recovery guidance missing');
        check(!is_dir($root . '/.webman-aot-builder/build/project'), 'failed mirror was activated');
        check((glob($root . '/.webman-aot-builder/build/.project-*') ?: []) === [], 'candidate remains after failure');
        fwrite(STDOUT, "PASS concurrent source change rejection, bounded safe relative paths, candidate cleanup\n");
    }
    check(proc_close($writer) === 0, 'concurrent writer failed');
    $writer = null;
} finally {
    if (is_resource($writer)) {
        proc_terminate($writer);
        proc_close($writer);
    }
    if (is_dir($root)) {
        removeFixture($root);
    }
}
fwrite(STDOUT, sprintf("PASS completed in %.3fs\n", microtime(true) - $started));
