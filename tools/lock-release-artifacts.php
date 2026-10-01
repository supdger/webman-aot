<?php

declare(strict_types=1);

// Release-only: derive consumer locks from actual built archives.
$root = dirname(__DIR__);
require $root . '/src/Version.php';
require $root . '/packages/composer-installer/src/Installer.php';

function releaseJson(string $contents): array
{
    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    return is_array($value) ? $value : throw new RuntimeException('JSON object required');
}

function releaseFile(string $path): string
{
    $resolved = realpath($path);
    if (!is_string($resolved) || !is_file($resolved) || is_link($path)) {
        throw new RuntimeException('Actual release file required: ' . $path);
    }
    return $resolved;
}

function releaseWrite(string $path, array $value): void
{
    $pending = $path . '.pending-' . bin2hex(random_bytes(6));
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($pending, $json) !== strlen($json) || !rename($pending, $path)) {
        throw new RuntimeException('Cannot publish derived lock: ' . $path);
    }
    fwrite(STDOUT, '[lock] Actual archive identities verified; wrote ' . $path . "\n");
}

/** Verify all file bytes; ZIP links/directories are described by the manifest. */
function releaseComponent(string $path, string $host, string $lockHash, string $patchHash): array
{
    $archive = releaseFile($path);
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot read component ZIP'); }
    try {
        $json = $zip->getFromName('minimal-component.json');
        $manifest = is_string($json) ? releaseJson($json) : [];
        if (($manifest['schema'] ?? '') !== 'webman-aot-builder-minimal-component-v1'
            || ($manifest['host'] ?? '') !== $host || ($manifest['toolchainLockSha256'] ?? '') !== $lockHash
            || !is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('Component manifest host/lock mismatch');
        }
        $ledger = $zip->getFromName('component-derivation.json');
        if (!is_string($ledger) || (releaseJson($ledger)['patchManifestSha256'] ?? '') !== $patchHash) {
            throw new RuntimeException('Component does not bind the current patch manifest');
        }
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || isset($names[$name])
                || ($name !== 'minimal-component.json' && ($manifest['entries'][$name]['type'] ?? '') !== 'file')) {
                throw new RuntimeException('Unknown or repeated component member');
            }
            $names[$name] = true;
        }
        $done = 0;
        foreach ($manifest['entries'] as $name => $entry) {
            if (($entry['type'] ?? '') !== 'file') { continue; }
            $stream = $zip->getStream($name);
            if (!is_resource($stream)) { throw new RuntimeException('Missing component file: ' . $name); }
            $context = hash_init('sha256');
            try { $bytes = hash_update_stream($context, $stream); } finally { fclose($stream); }
            if ($bytes !== $entry['size'] || hash_final($context) !== $entry['sha256']) {
                throw new RuntimeException('Component file bytes differ: ' . $name);
            }
            if (++$done % 250 === 0) { fwrite(STDOUT, "[lock] {$host}: verified {$done} file entries\n"); }
        }
        return ['archive' => basename($archive), 'sha256' => hash_file('sha256', $archive),
            'manifestSha256' => hash('sha256', $json)];
    } finally { $zip->close(); }
}

function releaseFullPackage(string $result, string $host, string $version): array
{
    $data = releaseJson((string) file_get_contents(releaseFile($result)));
    $matches = array_values(array_filter($data['packages'] ?? [], static fn ($entry): bool =>
        is_array($entry) && ($entry['platform'] ?? '') === $host));
    if (($data['schema'] ?? '') !== 'webman-aot-builder-installer-package-result-v1' || count($matches) !== 1) {
        throw new RuntimeException('Actual full packager result required');
    }
    $entry = $matches[0];
    $path = releaseFile($entry['path']);
    if (filesize($path) !== ($entry['size'] ?? null) || hash_file('sha256', $path) !== ($entry['sha256'] ?? '')) {
        throw new RuntimeException('Actual package differs from result');
    }
    if ($host === 'windows-x86_64') {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { throw new RuntimeException('Cannot open full ZIP'); }
        try { $json = $zip->getFromName('package.json'); } finally { $zip->close(); }
    } else {
        $process = proc_open(['/usr/bin/tar', '-xOzf', $path, 'package.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot inspect full tar'); }
        $json = stream_get_contents($pipes[1]); fclose($pipes[1]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Full tar identity extraction failed'); }
    }
    $identity = is_string($json) ? releaseJson($json) : [];
    $filename = 'webman-aot-builder-' . $version . '-full-' . $host . ($host === 'macos-arm64' ? '.tar.gz' : '.zip');
    if (($identity['schema'] ?? '') !== 'webman-aot-builder-installer-package-v1'
        || ($identity['version'] ?? '') !== $version || ($identity['platform'] ?? '') !== $host
        || ($identity['flavor'] ?? '') !== 'complete' || ($identity['revision'] ?? '') !== ($data['revision'] ?? null)
        || basename($path) !== $filename) {
        throw new RuntimeException('Full package version, revision or identity mismatch');
    }
    return ['filename' => $filename, 'url' => 'https://github.com/supdger/webman-aot-builder/releases/download/v'
        . $version . '/' . $filename, 'size' => $entry['size'], 'sha256' => $entry['sha256']];
}

try {
    if ($argc !== 4 || !in_array($argv[1], ['components', 'composer'], true)) {
        throw new RuntimeException('Usage: php tools/lock-release-artifacts.php components MAC.zip WINDOWS.zip | composer MAC-full-result.json WINDOWS-full-result.json');
    }
    $version = WebmanAotBuilder\Version::VALUE;
    if ($argv[1] === 'components') {
        $lockHash = (string) hash_file('sha256', $root . '/toolchain.lock.json');
        $patchHash = (string) hash_file('sha256', $root . '/toolchain/patches/typephp/0.9.2/manifest.json');
        $components = [];
        foreach (['macos-arm64' => $argv[2], 'windows-x86_64' => $argv[3]] as $host => $path) {
            if (basename($path) !== "webman-aot-builder-{$version}-{$host}-components.zip") {
                throw new RuntimeException('Component filename differs from native version');
            }
            $components[$host] = releaseComponent($path, $host, $lockHash, $patchHash);
        }
        releaseWrite($root . '/toolchain/minimal-components.lock.json', [
            'schema' => 'webman-aot-builder-minimal-components-lock-v1', 'version' => $version,
            'toolchainLockSha256' => $lockHash, 'components' => $components]);
    } else {
        if (Supdger\WebmanAotInstaller\Installer::VERSION !== $version) {
            throw new RuntimeException('Composer and native release versions differ');
        }
        $packages = [];
        foreach (['macos-arm64' => $argv[2], 'windows-x86_64' => $argv[3]] as $host => $result) {
            $packages[$host] = releaseFullPackage($result, $host, $version);
        }
        releaseWrite($root . '/packages/composer-installer/resources/releases.json', [
            'schema' => 1, 'version' => $version, 'packages' => $packages]);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[lock failed] ' . $exception->getMessage() . "\n");
    exit(1);
}
