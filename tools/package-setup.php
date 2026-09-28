<?php

declare(strict_types=1);

/** Generate fixed-version launchers only from verified, already-built installer results. */
final class SetupPackager
{
    /** @var array<string, string> */
    private array $options = [];

    public function run(array $argv): void
    {
        foreach (array_slice($argv, 1) as $argument) {
            if (!preg_match('/^--(small-result|full-result|platform|base-url|output)=(.+)$/D', $argument, $match)
                || isset($this->options[$match[1]])) {
                throw new InvalidArgumentException('invalid or duplicate setup option: ' . $argument);
            }
            $this->options[$match[1]] = $match[2];
        }
        $platform = $this->required('platform');
        if (!in_array($platform, ['macos-arm64', 'windows-x86_64'], true)) {
            throw new InvalidArgumentException('platform must be macos-arm64 or windows-x86_64');
        }
        $base = rtrim($this->required('base-url'), '/');
        if (!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[A-Za-z0-9._-]+)*$~D', $base)) {
            throw new InvalidArgumentException('base-url must be a plain HTTPS directory without query, credentials or command syntax');
        }
        $small = $this->package($this->required('small-result'), $platform, 'small');
        $full = $this->package($this->required('full-result'), $platform, 'complete');
        if ($small['version'] !== $full['version'] || $small['revision'] !== $full['revision']) {
            throw new RuntimeException('small/full package version or revision differs');
        }
        $extension = $platform === 'macos-arm64' ? 'command' : 'cmd';
        $template = dirname(__DIR__) . '/installer/' . ($platform === 'macos-arm64' ? 'macos' : 'windows') . '/setup.' . $extension . '.in';
        $contents = file_get_contents($template);
        if (!is_string($contents)) {
            throw new RuntimeException('unable to read setup template');
        }
        $constants = ['VERSION' => $small['version'], 'PLATFORM' => $platform];
        foreach (['SMALL' => $small, 'FULL' => $full] as $prefix => $package) {
            $constants[$prefix . '_FILE'] = $package['filename'];
            $constants[$prefix . '_URL'] = $base . '/' . $package['filename'];
            $constants[$prefix . '_SHA256'] = $package['sha256'];
            $constants[$prefix . '_SIZE'] = (string) $package['size'];
        }
        foreach ($constants as $name => $value) {
            // All values have a restrictive syntax; none can introduce shell/PowerShell quotes.
            $contents = str_replace('@' . $name . '@', $value, $contents);
        }
        if (preg_match('/@[A-Z_0-9]+@/', $contents)) {
            throw new RuntimeException('unresolved setup template value');
        }
        $output = $this->required('output');
        if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
            throw new RuntimeException('unable to create setup output directory');
        }
        $path = $output . '/webman-aot-builder-' . $small['version'] . '-' . $platform . '-setup.' . $extension;
        if (file_put_contents($path, $contents) === false || !chmod($path, $extension === 'command' ? 0755 : 0600)) {
            throw new RuntimeException('unable to write setup launcher');
        }
        $launcherPath = realpath($path);
        if ($extension === 'command') {
            $zipPath = substr($path, 0, -8) . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('unable to create macOS setup download ZIP');
            }
            try {
                if (!$zip->addFile($path, basename($path))
                    || !$zip->setExternalAttributesName(basename($path), ZipArchive::OPSYS_UNIX, 0100755 << 16)) {
                    throw new RuntimeException('unable to preserve macOS setup executable permission');
                }
            } finally { $zip->close(); }
            $path = $zipPath;
        }
        fwrite(STDOUT, json_encode(['schema' => 'webman-aot-builder-setup-result-v1', 'platform' => $platform,
            'version' => $small['version'], 'revision' => $small['revision'], 'path' => realpath($path),
            'launcherPath' => $launcherPath,
            'sha256' => hash_file('sha256', $path), 'size' => filesize($path)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDERR, '[setup] 已核验两个实际安装包并生成独立入口：' . $path . "\n远端资产尚未验证；此工具不会上传或发布。\n");
    }

    private function required(string $name): string
    {
        return $this->options[$name] ?? throw new InvalidArgumentException('missing --' . $name . '=...');
    }

    /** @return array<string, mixed> */
    private function json(string $contents): array
    {
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : throw new RuntimeException('JSON object required');
    }

    /** @return array{version:string,revision:string,filename:string,sha256:string,size:int} */
    private function package(string $result, string $platform, string $flavor): array
    {
        $data = $this->json((string) file_get_contents($result));
        if (($data['schema'] ?? '') !== 'webman-aot-builder-installer-package-result-v1') {
            throw new RuntimeException('invalid installer result schema');
        }
        $matches = array_values(array_filter($data['packages'] ?? [], static fn ($entry): bool => is_array($entry) && ($entry['platform'] ?? '') === $platform));
        if (count($matches) !== 1) {
            throw new RuntimeException('result must contain exactly one selected platform package');
        }
        $package = $matches[0];
        $path = $package['path'] ?? '';
        if (!is_string($path) || is_link($path) || !is_file($path) || realpath($path) !== $path
            || !is_int($package['size'] ?? null) || $package['size'] <= 0 || filesize($path) !== $package['size']
            || !is_string($package['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256'])
            || hash_file('sha256', $path) !== $package['sha256']) {
            throw new RuntimeException('actual installer archive path, size or SHA-256 differs from result');
        }
        fwrite(STDERR, '[setup] 检查实际归档：' . basename($path) . "\n");
        $metadata = null;
        $seen = [];
        if ($platform === 'windows-x86_64') {
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) { throw new RuntimeException('cannot read ZIP'); }
            try {
                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $name = $archive->getNameIndex($index);
                    $this->safeName($name);
                    $key = strtolower($name);
                    if (isset($seen[$key])) { throw new RuntimeException('duplicate installer ZIP entry'); }
                    $seen[$key] = true;
                    $opsys = 0; $attributes = 0;
                    $archive->getExternalAttributesIndex($index, $opsys, $attributes);
                    $type = ($attributes >> 16) & 0170000;
                    if (($attributes & 0x400) !== 0 || ($type !== 0 && $type !== 0100000)) {
                        throw new RuntimeException('installer ZIP contains a link or special file');
                    }
                }
                $metadata = $archive->getFromName('package.json');
            } finally { $archive->close(); }
        } else {
            $names = $this->tarListing($path, '-tzf');
            foreach (explode("\n", rtrim($this->tarListing($path, '-tvzf'), "\n")) as $entry) {
                if (!str_starts_with($entry, '-')) {
                    throw new RuntimeException('installer tar contains a link or special file');
                }
            }
            foreach (explode("\n", rtrim($names, "\n")) as $name) {
                $this->safeName($name);
                if (isset($seen[$name])) { throw new RuntimeException('duplicate installer tar entry'); }
                $seen[$name] = true;
            }
            $metadata = $this->tarListing($path, '-xzOf', 'package.json');
        }
        if (!is_string($metadata)) { throw new RuntimeException('package.json missing from installer'); }
        $identity = $this->json($metadata);
        $version = $identity['version'] ?? '';
        $revision = $identity['revision'] ?? '';
        if (($identity['schema'] ?? '') !== 'webman-aot-builder-installer-package-v1'
            || ($identity['platform'] ?? '') !== $platform || ($identity['flavor'] ?? '') !== $flavor
            || !is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
            || !is_string($revision) || !preg_match('/^[A-Za-z0-9._-]+$/D', $revision)
            || $revision !== ($data['revision'] ?? null)) {
            throw new RuntimeException('package identity does not match selected platform, flavor or revision');
        }
        $expected = 'webman-aot-builder-' . $version . ($flavor === 'complete' ? '-full' : '') . '-' . $platform . ($platform === 'macos-arm64' ? '.tar.gz' : '.zip');
        if (basename($path) !== $expected) { throw new RuntimeException('archive filename differs from package identity'); }
        return ['version' => $version, 'revision' => $revision, 'filename' => $expected,
            'sha256' => $package['sha256'], 'size' => $package['size']];
    }

    private function tarListing(string $path, string $option, ?string $member = null): string
    {
        $command = ['tar', $option, $path];
        if ($member !== null) { $command[] = $member; }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('cannot inspect tar entries'); }
        fclose($pipes[0]);
        $listing = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($listing)) {
            throw new RuntimeException('cannot inspect installer tar: ' . $error);
        }
        return $listing;
    }

    private function safeName(mixed $name): void
    {
        if (!is_string($name) || !preg_match('~^[A-Za-z0-9_.\-/]+$~D', $name)
            || str_starts_with($name, '/') || str_ends_with($name, '/')
            || count(array_intersect(['', '.', '..'], explode('/', $name))) > 0) {
            throw new RuntimeException('unsafe installer archive entry');
        }
    }
}

try {
    (new SetupPackager())->run($argv);
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . "\n");
    exit(1);
}
