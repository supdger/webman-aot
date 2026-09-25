#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $matches)) {
        throw new InvalidArgumentException("invalid option: {$argument}");
    }
    $options[$matches[1]] = $matches[2];
}
foreach (['typephp', 'php', 'phpx', 'compiler', 'fixture'] as $required) {
    if (!isset($options[$required])) {
        throw new InvalidArgumentException("missing --{$required}=...");
    }
}

$typephp = realpath($options['typephp']);
$php = realpath($options['php']);
$phpx = realpath($options['phpx']);
$compiler = realpath($options['compiler']);
$fixture = realpath($options['fixture']);
if (!is_string($typephp) || !is_string($php) || !is_string($phpx)
    || !is_string($compiler) || !is_string($fixture)
) {
    throw new RuntimeException('one or more locked compiler paths are missing');
}
$patchedFile = $typephp . '/src/Generator/CallArgumentGenerator.php';
$expectedDigest = '53db96dcbc1330dd511d57d6856611c8daa0236b235906750fbcb3e18f78c8fb';
if (hash_file('sha256', $patchedFile) !== $expectedDigest) {
    throw new RuntimeException('TypePHP PCNTL reference patch digest mismatch');
}

putenv('PHP_HOME=' . dirname($php));
putenv('PHPX_HOME=' . $phpx);
putenv('PATH=' . dirname($php) . PATH_SEPARATOR . dirname($compiler)
    . PATH_SEPARATOR . (getenv('PATH') ?: ''));
$output = sys_get_temp_dir() . '/webman-aot-pcntl-reference-codegen';
$command = [
    $php,
    $typephp . '/bin/tpc.php',
    $fixture,
    '--dry',
    '--force',
    '--full-static',
    '--target-platform=x86_64-unknown-linux-musl',
    '--compiler=' . $compiler,
    '--no-progress',
    '-o',
    $output,
];
$process = proc_open(
    $command,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    dirname($fixture)
);
if (!is_resource($process)) {
    throw new RuntimeException('unable to start the locked TypePHP compiler');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
if ($status !== 0 || !str_contains((string) $stdout, 'Dry run completed')) {
    throw new RuntimeException(
        "TypePHP dry run failed ({$status}): " . trim((string) $stdout . "\n" . $stderr)
    );
}

$generated = [];
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($typephp . '/build', FilesystemIterator::SKIP_DOTS)
);
foreach ($files as $file) {
    if ($file->isFile() && $file->getFilename() === 'pcntl-reference-metadata.cc') {
        $generated[] = $file->getPathname();
    }
}
if (count($generated) !== 1) {
    throw new RuntimeException('expected one generated PCNTL fixture C++ file');
}
$cpp = file_get_contents($generated[0]);
if (!is_string($cpp)) {
    throw new RuntimeException('unable to read generated PCNTL fixture');
}
foreach ([
    'waitStatus',
    'waitUsage',
    'pidStatus',
    'pidUsage',
    'info',
    'oldSignals',
    'namedStatus',
] as $variable) {
    if (preg_match('/php::RefWrap<php::(?:Int|Array)> \w+\(' . $variable . '\);/', $cpp) !== 1) {
        throw new RuntimeException("missing generated reference bridge: {$variable}");
    }
}
if (substr_count($cpp, '.commit();') < 7
    || preg_match('/\.set\([^\n]*&\w+\.ref\(\)\)/', $cpp) !== 1
) {
    throw new RuntimeException('generated PCNTL reference commits or named argument are missing');
}
echo json_encode([
    'schema' => 'webman-aot-pcntl-codegen-check-v1',
    'host' => PHP_OS_FAMILY,
    'target' => 'x86_64-unknown-linux-musl',
    'referenceBridges' => 7,
    'namedReference' => true,
    'patchedSourceSha256' => $expectedDigest,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
