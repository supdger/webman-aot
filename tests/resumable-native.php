<?php

declare(strict_types=1);

// Real native integration. Copy the no-database fixture; never edit the input project.
// Candidate home must contain the matching patched TypePHP/component manifests.
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tests/resumable-native.php CANDIDATE_HOME PUBLIC_LAUNCHER COMPOSER_READY_GUIDED_FIXTURE NEW_EVIDENCE_DIRECTORY\n");
    exit(64);
}
[$home, $launcher, $source, $evidence] = array_slice($argv, 1);
$fixture = json_decode((string) file_get_contents($source . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (($fixture['name'] ?? null) !== 'webman-aot/guided-acceptance-fixture'
    || !is_file($source . '/vendor/autoload.php') || file_exists($evidence) || !mkdir($evidence, 0700, true)) {
    throw new RuntimeException('Requires the Composer-ready no-database guided fixture and a new evidence directory');
}
$project = $evidence . '/project'; mkdir($project, 0700);
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $entry) use ($source): bool {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        if (in_array(explode('/', $relative, 2)[0], ['.git', '.webman-aot-builder', 'dist-aot', 'runtime'], true)) { return false; }
        if ($entry->isLink()) { throw new RuntimeException('Fixture refuses symlinks'); }
        return true;
    }
));
foreach ($iterator as $entry) {
    if (!$entry->isFile()) { continue; }
    $target = $project . '/' . substr($entry->getPathname(), strlen($source) + 1);
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
    if (!copy($entry->getPathname(), $target)) { throw new RuntimeException('Fixture copy failed'); }
}
require dirname(__DIR__) . '/src/Cli/ProcessOutput.php';
$environment = getenv(); $environment['WEBMAN_AOT_BUILDER_HOME'] = $home;
$reports = [];
$run = static function (string $name, array $options = []) use ($launcher, $project, $environment, $evidence, &$reports): array {
    fwrite(STDOUT, "[test] {$name}\n"); $log = fopen($evidence . '/' . $name . '.log', 'xb'); $text = ''; $started = microtime(true);
    $wrapper = null;
    if (PHP_OS_FAMILY === 'Windows') {
        if (preg_match('/["\r\n%!&|<>^]/', $launcher) || !in_array($options, [[], ['--fresh']], true)) {
            throw new RuntimeException('Controlled Windows test arguments contain shell syntax');
        }
        // cmd /c receives an ASCII basename, while CALL quotes the actual public launcher.
        $wrapper = $project . '/resume-native-test.cmd';
        if (file_exists($wrapper) || file_put_contents($wrapper, "@echo off\r\nchcp 65001 >nul\r\ncall \""
            . $launcher . "\" build" . ($options === [] ? '' : ' --fresh') . "\r\nexit /b %errorlevel%\r\n") === false) {
            throw new RuntimeException('Cannot create the owned Windows test wrapper');
        }
        $command = ['cmd.exe', '/d', '/c', basename($wrapper)];
    } else { $command = [$launcher, 'build', ...$options]; }
    try {
        $code = WebmanAotBuilder\Cli\ProcessOutput::run($command, $project, $environment,
        ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        static function (int $index, string $chunk) use ($log, &$text): void { fwrite($log, $chunk); fwrite(STDOUT, $chunk); fflush(STDOUT); $text .= $chunk; },
        static function (float $elapsed, float $silent): void { fwrite(STDOUT, sprintf("[test] native process alive %.0fs; silent %.0fs\n", $elapsed, $silent)); }
        );
    } finally { if ($wrapper !== null) { unlink($wrapper); } }
    fclose($log); preg_match('/Reused verified objects: (\d+)/', $text, $match);
    $result = ['case' => $name, 'exit' => $code, 'reused' => isset($match[1]) ? (int) $match[1] : null, 'seconds' => round(microtime(true) - $started, 2)];
    $reports[] = $result; file_put_contents($evidence . '/results.json', json_encode($reports, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    if ($code !== 0) { throw new RuntimeException("{$name} failed with exit {$code}; see native log"); }
    fwrite(STDOUT, '[test] ' . json_encode($result, JSON_THROW_ON_ERROR) . "\n"); return $result;
};
$first = $run('initial'); if ($first['reused'] !== 0) { throw new RuntimeException('New fixture reused unexpected objects'); }
$repeat = $run('repeat'); $total = $repeat['reused']; if (!is_int($total) || $total <= 2) { throw new RuntimeException('Native repeat did not reuse completed units'); }
$entries = glob($project . '/.webman-aot-builder/cache/objects/*', GLOB_ONLYDIR) ?: [];
$object = $entries[0] . '/object'; $bytes = file_get_contents($object); file_put_contents($object, substr($bytes, 0, -1) . chr(ord(substr($bytes, -1)) ^ 1));
file_put_contents($entries[1] . '/complete.json', '{interrupted');
$result = $run('corrupt-object-and-record'); if ($result['reused'] !== $total - 2) { throw new RuntimeException('Corrupt checkpoints were not selectively rebuilt'); }
$controller = $project . '/app/controller/IndexController.php'; $original = file_get_contents($controller);
try {
    $changed = str_replace('guided Webman fixture', 'changed Webman fixture', $original, $count);
    if ($count !== 1) { throw new RuntimeException('Native source-change fixture contract drifted'); }
    file_put_contents($controller, $changed); $result = $run('source-change');
    if ($result['reused'] !== $total - 1) { throw new RuntimeException('Changed source did not selectively invalidate'); }
} finally { file_put_contents($controller, $original); }
$result = $run('fresh', ['--fresh']); if ($result['reused'] !== 0) { throw new RuntimeException('Fresh build reused checkpoints'); }
fwrite(STDOUT, "PASS native reuse, corrupt/incomplete checkpoint rejection, source invalidation and explicit full rebuild. Evidence: {$evidence}\n");
