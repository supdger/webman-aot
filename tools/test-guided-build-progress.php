<?php

declare(strict_types=1);

// Run against a task-owned private installation and a disposable, Composer-ready project.
// Both platforms use the real project menu, public launcher, CLI and locked compiler.
// This test does not install, alter PATH, create services or prepare a network fixture.
if ($argc < 4 || !in_array(PHP_OS_FAMILY, ['Darwin', 'Windows'], true)) {
    fwrite(STDERR, "Usage: php tools/test-guided-build-progress.php <private-home> <private-bin> <disposable-project> [new-evidence-directory]\n");
    exit(64);
}
[$home, $bin, $project] = array_map(static fn (string $path): string => str_replace('\\', '/', $path), array_slice($argv, 1, 3));
foreach ([$home . '/current', $bin, $project . '/vendor'] as $directory) {
    if (!is_dir($directory)) {
        throw new RuntimeException('Prepared fixture is missing: ' . $directory);
    }
}
$base = $argv[4] ?? sys_get_temp_dir() . '/guided-build-progress-' . bin2hex(random_bytes(6));
if (file_exists($base) || !mkdir($base, 0700, true)) {
    throw new RuntimeException('Evidence directory must be new: ' . $base);
}
file_put_contents($base . '/input.txt', "1\n{$project}\n0\n");
$log = $base . '/terminal.log';
$started = microtime(true);
$process = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/tools/guided.php', '--mode=project', '--home=' . $home, '--bin-dir=' . $bin, '--no-path'],
    [0 => ['file', $base . '/input.txt', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot launch real guided project menu');
}
$offset = 0;
$text = '';
$events = [];
$firstStage = null;
$buildSuccess = null;
$compilationStarted = false;
$progress = [];
$exit = -1;
try {
    while (true) {
        $status = proc_get_status($process);
        $chunk = file_get_contents($log, false, null, $offset);
        if (is_string($chunk) && $chunk !== '') {
            $offset += strlen($chunk);
            $elapsed = microtime(true) - $started;
            fwrite(STDOUT, $chunk);
            fflush(STDOUT);
            $text .= $chunk;
            $events[] = ['elapsed' => $elapsed, 'running' => $status['running'], 'bytes' => strlen($chunk)];
            $plain = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text);
            if ($firstStage === null && str_contains($plain, '[build] profile')) {
                $firstStage = $elapsed;
            }
            if (preg_match('/Starting (?:parallel )?compilation/', $plain) === 1) {
                $compilationStarted = true;
            }
            if ($compilationStarted && $status['running']) {
                preg_match_all('/\[(\d+)\/(\d+)\]\s+\d+%[^\r\n]*\.(?:cc|cpp|c)(?:\s|$)/m', $plain, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $key = $match[1] . '/' . $match[2];
                    $progress[$key] ??= ['elapsed' => $elapsed, 'line' => trim($match[0]), 'running' => true];
                }
            }
            if ($buildSuccess === null && str_contains($plain, '[成功] 构建项目')) {
                $buildSuccess = $elapsed;
            }
        }
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $started > 600) {
            throw new RuntimeException('Guided build exceeded the 600-second fixture limit; see ' . $log);
        }
        usleep(100000);
    }
    $closed = proc_close($process);
    $process = null;
    $exit = $exit >= 0 ? $exit : $closed;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}
$elapsed = microtime(true) - $started;
$receipt = ['platform' => PHP_OS_FAMILY, 'exitCode' => $exit, 'elapsed' => $elapsed, 'firstBuildStageAt' => $firstStage, 'buildSuccessAt' => $buildSuccess, 'nativeProgress' => array_values($progress), 'outputEvents' => $events];
file_put_contents($base . '/timing.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
$checks = [
    'real project menu and directory prompt were exercised' => str_contains($text, '下一步：1 构建项目') && str_contains($text, '输入 Webman/SaiAdmin 项目目录'),
    'real build stage appeared before build finished' => $firstStage !== null && $buildSuccess !== null && $firstStage < $buildSuccess - 1,
    'real compiler per-file counts advanced before build finished' => count($progress) >= 2 && $buildSuccess !== null && min(array_column($progress, 'elapsed')) < $buildSuccess - 1 && max(array_column($progress, 'elapsed')) > min(array_column($progress, 'elapsed')) + 0.1,
    'build succeeded and guided verification completed' => $exit === 0 && str_contains($text, '项目构建与校验成功。'),
];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS: ' : 'FAIL: ') . $name . "\n");
}
fwrite(STDOUT, sprintf("%d/4 checks passed on %s in %.1fs; evidence: %s\n", count(array_filter($checks)), PHP_OS_FAMILY, $elapsed, $base));
exit(in_array(false, $checks, true) ? 1 : 0);
