<?php

declare(strict_types=1);

final class InstallerPackager
{
    /** @var array<string, string> */
    private array $options = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): void
    {
        $this->parseOptions($arguments);
        $lock = $this->readJson($this->root . '/installer/runtime.lock.json');
        if (($lock['schema'] ?? null) !== 'webman-aot-installer-runtime-lock-v1'
            || !is_array($lock['runtimes'] ?? null)
        ) {
            throw new RuntimeException('installer runtime lock is invalid');
        }

        $output = $this->requiredOption('output');
        $this->createDirectory($output);
        $workspace = sys_get_temp_dir() . '/webman-aot-package-' . bin2hex(random_bytes(8));
        $this->createDirectory($workspace);
        try {
            $mac = $this->packageMac(
                $workspace,
                $output,
                $this->requiredOption('mac-runtime'),
                $this->requiredOption('mac-runtime-license-dir'),
                $lock['runtimes']['macos-arm64'] ?? null
            );
            $windows = $this->packageWindows(
                $workspace,
                $output,
                $this->requiredOption('windows-runtime-archive'),
                $lock['runtimes']['windows-x86_64'] ?? null
            );
        } finally {
            $this->removeDirectory($workspace);
        }

        fwrite(STDOUT, json_encode([
            'schema' => 'webman-aot-installer-package-result-v1',
            'revision' => $this->options['revision'] ?? 'unknown',
            'packages' => [$mac, $windows],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, string|int>
     */
    private function packageMac(
        string $workspace,
        string $output,
        string $runtimePath,
        string $runtimeLicenseDirectory,
        ?array $runtime
    ): array {
        if (!is_array($runtime)
            || ($runtime['binarySha256'] ?? null) !== $this->digest($runtimePath)
        ) {
            throw new RuntimeException('macOS runtime does not match installer lock');
        }
        if (!is_dir($runtimeLicenseDirectory)
            || $this->files($runtimeLicenseDirectory) === []
        ) {
            throw new RuntimeException('macOS runtime license directory is missing or empty');
        }
        $stage = $workspace . '/macos-arm64';
        $this->stageApplication($stage);
        $this->createDirectory($stage . '/payload/runtime/bin');
        if (!copy($runtimePath, $stage . '/payload/runtime/bin/php')) {
            throw new RuntimeException('unable to stage macOS private PHP runtime');
        }
        chmod($stage . '/payload/runtime/bin/php', 0700);
        $this->copyDirectory($runtimeLicenseDirectory, $stage . '/payload/runtime/licenses');
        $this->createDirectory($stage . '/payload/launcher');
        copy($this->root . '/bin/webman-aot', $stage . '/payload/launcher/webman-aot');
        chmod($stage . '/payload/launcher/webman-aot', 0700);
        copy($this->root . '/installer/macos/install.sh', $stage . '/install.sh');
        copy($this->root . '/installer/macos/uninstall.sh', $stage . '/uninstall.sh');
        chmod($stage . '/install.sh', 0700);
        chmod($stage . '/uninstall.sh', 0700);
        $this->writeMetadata($stage, 'macos-arm64', $runtime);

        $archive = $output . '/webman-aot-' . $this->version() . '-macos-arm64.tar.gz';
        $tarPath = substr($archive, 0, -3);
        if (is_file($tarPath)) {
            unlink($tarPath);
        }
        if (is_file($archive)) {
            unlink($archive);
        }
        $tar = new PharData($tarPath);
        $this->addTreeToTar($tar, $stage, '');
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($tarPath);

        return $this->packageResult('macos-arm64', $archive);
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, string|int>
     */
    private function packageWindows(
        string $workspace,
        string $output,
        string $runtimeArchive,
        ?array $runtime
    ): array {
        if (!is_array($runtime)
            || ($runtime['archiveSha256'] ?? null) !== $this->digest($runtimeArchive)
            || !is_string($runtime['archiveRoot'] ?? null)
        ) {
            throw new RuntimeException('Windows runtime archive does not match installer lock');
        }
        $stage = $workspace . '/windows-x86_64';
        $this->stageApplication($stage);
        $extract = $workspace . '/windows-runtime-extract';
        $this->extractLockedZip($runtimeArchive, $extract, $runtime['archiveRoot']);
        $source = $extract . '/' . $runtime['archiveRoot'];
        if (($runtime['binarySha256'] ?? null) !== $this->digest($source . '/php.exe')
            || ($runtime['phpLibrarySha256'] ?? null) !== $this->digest($source . '/php8ts.dll')
        ) {
            throw new RuntimeException('Windows private PHP runtime files do not match lock');
        }
        $this->copyDirectory($source, $stage . '/payload/runtime');
        $this->createDirectory($stage . '/payload/launcher');
        copy($this->root . '/bin/webman-aot.cmd', $stage . '/payload/launcher/webman-aot.cmd');
        copy($this->root . '/installer/windows/install.ps1', $stage . '/install.ps1');
        copy($this->root . '/installer/windows/uninstall.ps1', $stage . '/uninstall.ps1');
        $this->writeMetadata($stage, 'windows-x86_64', $runtime);

        $archive = $output . '/webman-aot-' . $this->version() . '-windows-x86_64.zip';
        if (is_file($archive)) {
            unlink($archive);
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('unable to create Windows installer archive');
        }
        try {
            $this->addTreeToZip($zip, $stage, '');
        } finally {
            $zip->close();
        }

        return $this->packageResult('windows-x86_64', $archive);
    }

    private function stageApplication(string $stage): void
    {
        $app = $stage . '/payload/app';
        $this->createDirectory($app . '/bin');
        copy($this->root . '/bin/webman-aot.php', $app . '/bin/webman-aot.php');
        $this->copyDirectory($this->root . '/src', $app . '/src');
        copy($this->root . '/toolchain.lock.json', $app . '/toolchain.lock.json');
        copy($this->root . '/LICENSE', $app . '/LICENSE');
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function writeMetadata(string $stage, string $platform, array $runtime): void
    {
        $manifest = [];
        $payload = $stage . '/payload';
        $files = $this->files($payload);
        foreach ($files as $relative => $path) {
            $manifest[] = $this->digest($path) . '  payload/' . $relative;
        }
        file_put_contents(
            $stage . '/payload-manifest.sha256',
            implode("\n", $manifest) . "\n"
        );
        file_put_contents(
            $stage . '/package.json',
            json_encode([
                'schema' => 'webman-aot-installer-package-v1',
                'version' => $this->version(),
                'revision' => $this->options['revision'] ?? 'unknown',
                'platform' => $platform,
                'runtime' => $runtime,
                'payloadFiles' => count($manifest),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function extractLockedZip(string $archive, string $destination, string $root): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('unable to open Windows runtime archive');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name)) {
                    throw new RuntimeException('Windows runtime archive has unnamed entry');
                }
                $normalized = str_replace('\\', '/', $name);
                $segments = explode('/', $normalized);
                if ($normalized === ''
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:/', $normalized) === 1
                    || in_array('..', $segments, true)
                    || ($segments[0] ?? null) !== $root
                ) {
                    throw new RuntimeException("unsafe Windows runtime archive entry: {$name}");
                }
            }
            $this->createDirectory($destination);
            if (!$zip->extractTo($destination)) {
                throw new RuntimeException('unable to extract Windows runtime archive');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, string>
     */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr(
                $item->getPathname(),
                strlen($root) + 1
            ));
            $files[$relative] = $item->getPathname();
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function addTreeToTar(PharData $tar, string $root, string $prefix): void
    {
        foreach ($this->files($root) as $relative => $path) {
            $tar->addFile($path, ltrim($prefix . '/' . $relative, '/'));
        }
    }

    private function addTreeToZip(ZipArchive $zip, string $root, string $prefix): void
    {
        foreach ($this->files($root) as $relative => $path) {
            if (!$zip->addFile($path, ltrim($prefix . '/' . $relative, '/'))) {
                throw new RuntimeException("unable to add installer file: {$relative}");
            }
        }
    }

    /**
     * @return array{platform:string,path:string,sha256:string,size:int}
     */
    private function packageResult(string $platform, string $path): array
    {
        $size = filesize($path);
        if (!is_int($size)) {
            throw new RuntimeException("unable to stat package: {$path}");
        }

        return [
            'platform' => $platform,
            'path' => $path,
            'sha256' => $this->digest($path),
            'size' => $size,
        ];
    }

    private function version(): string
    {
        $contents = file_get_contents($this->root . '/src/Version.php');
        if (!is_string($contents)
            || preg_match("/public const VALUE = '([^']+)'/", $contents, $matches) !== 1
        ) {
            throw new RuntimeException('unable to resolve Webman AOT version');
        }

        return $matches[1];
    }

    private function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException("unable to hash file: {$path}");
        }

        return $digest;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("unable to read JSON: {$path}");
        }
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    /**
     * @param list<string> $arguments
     */
    private function parseOptions(array $arguments): void
    {
        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new InvalidArgumentException("invalid option: {$argument}");
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if ($name === '' || $value === '') {
                throw new InvalidArgumentException("invalid option: {$argument}");
            }
            $this->options[$name] = $value;
        }
    }

    private function requiredOption(string $name): string
    {
        if (!isset($this->options[$name])) {
            throw new InvalidArgumentException("missing option: --{$name}=...");
        }

        return $this->options[$name];
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->createDirectory($destination);
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $target = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("unable to copy installer file: {$target}");
            }
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException("unable to create directory: {$directory}");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

try {
    (new InstallerPackager(dirname(__DIR__)))->run($argv);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
