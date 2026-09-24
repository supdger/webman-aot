<?php

declare(strict_types=1);

const EXIT_USAGE = 64;
const EXIT_SOFTWARE = 70;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'help';

if ($command === 'help' || $command === '--help' || $command === '-h') {
    fwrite(STDOUT, <<<'HELP'
Usage: php tools/toolchain.php <command>

Commands:
  self-check  Verify the repository-owned toolchain entry and directory layout.
  lock-check  Validate toolchain.lock.json structure and digest guards.
  paths       Print normalized repository paths as JSON.
  help        Show this help.

HELP);
    exit(0);
}

if ($command === 'paths') {
    $paths = repositoryPaths($root);
    fwrite(
        STDOUT,
        json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    exit(0);
}

if ($command === 'lock-check') {
    require $root . '/src/Toolchain/LockValidator.php';

    $contents = file_get_contents($root . '/toolchain.lock.json');
    if ($contents === false) {
        fwrite(STDERR, "[FAIL] unable to read toolchain.lock.json\n");
        exit(EXIT_SOFTWARE);
    }

    try {
        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(STDERR, '[FAIL] invalid toolchain lock JSON: ' . $exception->getMessage() . PHP_EOL);
        exit(EXIT_SOFTWARE);
    }

    if (!is_array($lock)) {
        fwrite(STDERR, "[FAIL] toolchain lock root must be an object\n");
        exit(EXIT_SOFTWARE);
    }

    $validator = new WebmanAot\Toolchain\LockValidator();
    $errors = $validator->validate($lock);
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, "[FAIL] {$error}\n");
        }
        exit(EXIT_SOFTWARE);
    }

    fwrite(STDOUT, "[OK] toolchain lock structure and digests are valid\n");
    exit(0);
}

if ($command !== 'self-check') {
    fwrite(STDERR, sprintf("Unknown toolchain command: %s\n", $command));
    exit(EXIT_USAGE);
}

$required = [
    'bin',
    'src',
    'tests',
    'toolchain',
    'toolchain/recipes',
    'tools',
];

$errors = [];
foreach ($required as $relativePath) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_dir($path)) {
        $errors[] = sprintf('missing directory: %s', $relativePath);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[FAIL] {$error}\n");
    }
    exit(EXIT_SOFTWARE);
}

$paths = repositoryPaths($root);
fwrite(STDOUT, "[OK] repository toolchain entry is self-contained\n");
fwrite(STDOUT, json_encode($paths, JSON_UNESCAPED_SLASHES) . PHP_EOL);

/**
 * @return array{root:string, build:string, dist:string, toolchain:string}
 */
function repositoryPaths(string $root): array
{
    $normalizedRoot = str_replace('\\', '/', $root);

    return [
        'root' => $normalizedRoot,
        'build' => $normalizedRoot . '/build',
        'dist' => $normalizedRoot . '/dist',
        'toolchain' => $normalizedRoot . '/toolchain',
    ];
}
