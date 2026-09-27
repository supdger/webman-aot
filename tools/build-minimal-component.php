<?php

declare(strict_types=1);

/**
 * Build a host-specific, source-verified toolchain component from an already
 * prepared generation. The resulting ZIP is shared by small and full installers.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/build-minimal-component.php GENERATION OUTPUT.zip\n");
    exit(64);
}

$generation = realpath($argv[1]);
$output = $argv[2];
if (!is_string($generation) || !is_dir($generation . '/prepared')
    || !str_ends_with(strtolower($output), '.zip')
) {
    throw new RuntimeException('prepared generation or output ZIP is invalid');
}
$root = dirname(__DIR__);
$lockContents = file_get_contents($generation . '/toolchain.lock.json');
$generationContents = file_get_contents($generation . '/manifest.json');
$lock = is_string($lockContents) ? json_decode($lockContents, true, flags: JSON_THROW_ON_ERROR) : null;
$generationManifest = is_string($generationContents)
    ? json_decode($generationContents, true, flags: JSON_THROW_ON_ERROR)
    : null;
$host = is_array($generationManifest) ? ($generationManifest['host'] ?? null) : null;
if (!is_array($lock) || !in_array($host, ['macos-arm64', 'windows-x86_64'], true)
    || hash_file('sha256', $root . '/toolchain.lock.json')
        !== hash_file('sha256', $generation . '/toolchain.lock.json')
) {
    throw new RuntimeException('generation host or toolchain lock differs from this source');
}

$selected = [];
$generatorFound = false;
foreach ($lock['components'] ?? [] as $component) {
    if (!is_array($component)) {
        throw new RuntimeException('locked component is malformed');
    }
    $id = (string) ($component['id'] ?? '');
    if ((str_contains($id, '-macos-') && $host !== 'macos-arm64')
        || (str_contains($id, '-windows-') && $host !== 'windows-x86_64')
    ) {
        continue;
    }
    $urlPath = parse_url((string) ($component['sourceUrl'] ?? ''), PHP_URL_PATH);
    $name = is_string($urlPath) ? basename($urlPath) : '';
    $path = $generation . '/artifacts/' . $name;
    $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if ($name === '' || !is_string($digest)
        || !hash_equals((string) ($component['sha256'] ?? ''), $digest)
    ) {
        throw new RuntimeException("original source artifact is missing or corrupt: {$id}");
    }
    if ($id === 'webman-typephp-generator-source') {
        $selected['artifacts/' . $name] = $path;
        $generatorFound = true;
    }
    fwrite(STDERR, "[source] Verified {$id}\n");
}
if (!$generatorFound) {
    throw new RuntimeException('locked Webman generator archive is missing');
}

foreach (['manifest.json', 'toolchain.lock.json', 'prepared/prepared-toolchain.json'] as $relative) {
    $selected[$relative] = $generation . '/' . $relative;
}
$prepared = $generation . '/prepared';
$selected['prepared/php-config'] = $prepared . '/php-config';
if (!is_dir($selected['prepared/php-config']) || is_link($selected['prepared/php-config'])) {
    throw new RuntimeException('prepared PHP configuration directory is missing or unsafe');
}
foreach (['php-driver', 'sysroot', 'typephp-source'] as $directory) {
    $path = $prepared . '/' . $directory;
    if (!is_dir($path) || is_link($path)) {
        throw new RuntimeException("prepared directory is missing or unsafe: {$directory}");
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($generation) + 1));
        if ($relative === 'prepared/sysroot/usr/lib/bfd-plugins/liblto_plugin.so') {
            if (!$item->isLink()
                || readlink($item->getPathname())
                    !== '//usr/libexec/gcc/x86_64-alpine-linux-musl/12.2.1/liblto_plugin.so'
            ) {
                throw new RuntimeException('locked sysroot bfd plugin link drifted');
            }
            continue;
        }
        if ($directory === 'typephp-source'
            && preg_match(
                '~^prepared/typephp-source/[^/]+/(?:benchmark|docs|examples|phpunit|tests|wasm)(?:/|$)~D',
                $relative
            ) === 1
        ) {
            continue;
        }
        if ($item->isFile() || $item->isLink()) {
            $selected[$relative] = $item->getPathname();
        }
    }
}

$llvmRelative = $host === 'macos-arm64'
    ? 'llvm'
    : 'llvm-payload/clang+llvm-19.1.7-x86_64-pc-windows-msvc';
$llvm = $prepared . '/' . $llvmRelative;
if (!is_dir($llvm) || is_link($llvm)) {
    throw new RuntimeException('prepared LLVM directory is missing or unsafe');
}
$llvmTools = $host === 'macos-arm64'
    ? ['clang++', 'clang', 'clang-19', 'ld.lld', 'lld', 'llvm-nm', 'llvm-objcopy']
    : ['clang++.exe', 'clang.exe', 'ld.lld.exe', 'lld.exe', 'lld-link.exe', 'llvm-ar.exe',
        'llvm-nm.exe', 'llvm-objcopy.exe', 'llvm-ranlib.exe'];
foreach ($llvmTools as $name) {
    $path = $llvm . '/bin/' . $name;
    if ((!is_file($path) && !is_link($path)) || is_dir($path)) {
        throw new RuntimeException("required LLVM tool is missing: {$name}");
    }
    $selected['prepared/' . $llvmRelative . '/bin/' . $name] = $path;
}
$clangResources = $llvm . '/lib/clang/19';
if (!is_dir($clangResources) || is_link($clangResources)) {
    throw new RuntimeException('locked Clang resource directory is missing');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($clangResources, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $item) {
    if ($item->isFile() || $item->isLink()) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($generation) + 1));
        $selected[$relative] = $item->getPathname();
    }
}
ksort($selected, SORT_STRING);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('cannot create minimal component ZIP; output may already exist');
}
$entries = [];
$omittedLinks = [];
try {
    $done = 0;
    $total = count($selected);
    foreach ($selected as $relative => $path) {
        $done++;
        if (is_dir($path) && !is_link($path)) {
            $entries[$relative] = ['type' => 'directory'];
            continue;
        }
        if (is_link($path)) {
            $target = readlink($path);
            if (!is_string($target) || $target === '' || str_starts_with($target, '/')
                || str_contains($target, '\\')
            ) {
                throw new RuntimeException("unsafe component symlink: {$relative}");
            }
            $segments = explode('/', dirname($relative) . '/' . $target);
            $resolved = [];
            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '') {
                    continue;
                }
                if ($segment === '..') {
                    if ($resolved === []) {
                        throw new RuntimeException("component symlink escapes the bundle: {$relative}");
                    }
                    array_pop($resolved);
                    continue;
                }
                $resolved[] = $segment;
            }
            $resolvedPath = implode('/', $resolved);
            $targetBundled = isset($selected[$resolvedPath]);
            if (!$targetBundled) {
                foreach ($selected as $candidate => $_) {
                    if (str_starts_with($candidate, $resolvedPath . '/')) {
                        $targetBundled = true;
                        break;
                    }
                }
            }
            if (!$targetBundled && realpath($path) === false) {
                $omittedLinks[$relative] = $target;
                fwrite(STDERR, "[bundle] Omitted dangling upstream link: {$relative} -> {$target}\n");
                continue;
            }
            if (!$targetBundled) {
                throw new RuntimeException("component symlink target is not bundled: {$relative}");
            }
            $entries[$relative] = ['type' => 'link', 'target' => $target];
            continue;
        }
        $digest = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($digest) || !is_int($size)) {
            throw new RuntimeException("cannot add minimal component file: {$relative}");
        }
        $added = $zip->addFile($path, $relative);
        if (!$added && PHP_OS_FAMILY === 'Windows' && $size <= 16 * 1048576) {
            $contents = file_get_contents($path);
            $added = is_string($contents) && $zip->addFromString($relative, $contents);
        }
        if (!$added) {
            throw new RuntimeException("cannot add minimal component file: {$relative}");
        }
        $entries[$relative] = [
            'type' => 'file',
            'sha256' => $digest,
            'size' => $size,
            'executable' => is_executable($path),
        ];
        if ($done % 250 === 0 || $done === $total) {
            fwrite(STDERR, "[bundle] Added {$done}/{$total} entries\n");
        }
    }
    $manifest = [
        'schema' => 'webman-aot-builder-minimal-component-v1',
        'host' => $host,
        'toolchainLockSha256' => hash_file('sha256', $generation . '/toolchain.lock.json'),
        'entries' => $entries,
        'omittedDanglingLinks' => $omittedLinks,
    ];
    $manifestJson = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    if (!$zip->addFromString('minimal-component.json', $manifestJson)) {
        throw new RuntimeException('cannot add minimal component manifest');
    }
} finally {
    $zip->close();
}
$bundleDigest = hash_file('sha256', $output);
$bundleSize = filesize($output);
if (!is_string($bundleDigest) || !is_int($bundleSize)) {
    throw new RuntimeException('cannot inspect generated component ZIP');
}
fwrite(STDOUT, json_encode([
    'host' => $host,
    'path' => $output,
    'sha256' => $bundleDigest,
    'size' => $bundleSize,
    'files' => count($entries),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
