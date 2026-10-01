<?php
declare(strict_types=1);

namespace SaiAdmin\WebmanAotInstaller;

final class Installer
{
    public const VERSION = '0.3.4';
    private array $release;
    private bool $interactive;

    public function __construct(?array $release = null, ?bool $interactive = null)
    {
        $this->release = $release ?? json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/releases.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->interactive = $interactive ?? (stream_isatty(STDIN) && stream_isatty(STDOUT));
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            [$arguments, $options] = $this->parse(array_slice($argv, 1));
            $command = $arguments[0] ?? 'help';
            if ($command === 'uninstall') {
                require_once __DIR__ . '/Uninstaller.php';
                $uninstallArguments = array_slice($arguments, 1);
                foreach (['state-dir', 'non-interactive'] as $key) {
                    if (isset($options[$key])) {
                        $uninstallArguments[] = $key === 'state-dir' ? '--state-dir=' . $options[$key] : '--non-interactive';
                    }
                }
                if (isset($options['yes']) || isset($options['archive'])) {
                    throw new \InvalidArgumentException('卸载须逐项确认；不接受 --yes 或 --archive。');
                }
                return (new Uninstaller($this->interactive))->run($uninstallArguments);
            }
            if (in_array($command, ['help', '--help', '-h'], true)) {
                $this->help();
                return 0;
            }
            if (in_array($command, ['version', '--version', '-V'], true)) {
                $this->say('Composer 入口 ' . self::VERSION . '；目标 Webman AOT Builder ' . $this->release['version']);
                return 0;
            }
            if (($options['non-interactive'] ?? false) === true) {
                $this->interactive = false;
            }
            $host = self::host();
            $state = $options['state-dir'] ?? $this->defaultState($host);
            $state = $this->state((string) $state);
            $originalDirectory = getcwd();
            if (!is_string($originalDirectory)) {
                throw new \RuntimeException('无法读取项目当前目录。');
            }
            $this->prepare($state, $host, $options);
            if ($command === 'setup') {
                $this->say('准备成功；现在可在项目目录运行 webman-aot build。');
                return 0;
            }
            return $this->forward($state, $host, $arguments, $originalDirectory);
        } catch (\Throwable $error) {
            fwrite(STDERR, '[失败] ' . $error->getMessage() . PHP_EOL);
            return 70;
        }
    }

    public static function host(): string
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('此入口需要 64 位系统 PHP。');
        }
        if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
            return 'macos-arm64';
        }
        if (PHP_OS_FAMILY === 'Windows'
            && (strtoupper((string) getenv('PROCESSOR_ARCHITECTURE')) === 'AMD64'
                || strtoupper((string) getenv('PROCESSOR_ARCHITEW6432')) === 'AMD64')) {
            return 'windows-x86_64';
        }
        throw new \RuntimeException('仅支持 macOS Apple Silicon / Windows x64 构建宿主；检测到 ' . PHP_OS_FAMILY . ' ' . php_uname('m'));
    }

    /** @return array{list<string>,array<string,string|bool>} */
    private function parse(array $arguments): array
    {
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--') {
                $i++;
                break;
            }
            if (in_array($argument, ['--yes', '--non-interactive'], true)) {
                $options[substr($argument, 2)] = true;
                continue;
            }
            if (preg_match('/^--(state-dir|archive)(?:=(.*))?$/D', $argument, $match)) {
                $value = $match[2] ?? ($arguments[++$i] ?? '');
                if ($value === '' || isset($options[$match[1]])) {
                    throw new \InvalidArgumentException('缺少参数值或重复参数：' . $match[1]);
                }
                $options[$match[1]] = $value;
                continue;
            }
            break;
        }
        $forward = array_slice($arguments, $i);
        if (($forward[0] ?? '') === 'setup') {
            [$tail, $setupOptions] = $this->parse(array_slice($forward, 1));
            if ($tail !== []) {
                throw new \InvalidArgumentException('setup 不接受项目参数；使用 --archive、--state-dir、--yes 或 --non-interactive。');
            }
            foreach ($setupOptions as $key => $value) {
                if (isset($options[$key])) {
                    throw new \InvalidArgumentException('重复参数：' . $key);
                }
                $options[$key] = $value;
            }
            $forward = ['setup'];
        }
        return [$forward, $options];
    }

    private function help(): void
    {
        $this->say("saiadmin/webman-aot-builder Composer 入口 " . self::VERSION . "\n目标构建器：" . $this->release['version']
            . "\n\n用法：\n  webman-aot build [原构建参数]\n  webman-aot doctor\n  webman-aot uninstall [--list]\n  webman-aot setup --yes\n  webman-aot setup --archive=完整安装包路径 --non-interactive"
            . "\n\n首次运行需准备完整包；交互模式自动准备，非交互需 --yes 或 --archive。\n--state-dir=目录 指定独立安装和缓存目录，不修改旧安装或 PATH。\n全局选项放在 doctor/build 等原命令之前；setup 的选项可放后面。\n默认只支持 macOS ARM64 / Windows x64，产物运行在 Linux x86_64。\nhelp/version 只说明入口，不表示原构建器已安装。准备成功后重跑原命令，不提供编译断点续跑。");
    }

    private function defaultState(string $host): string
    {
        $base = $host === 'macos-arm64' ? getenv('HOME') : getenv('LOCALAPPDATA');
        if (!is_string($base) || $base === '') {
            throw new \RuntimeException('无法读取个人数据目录；请用 --state-dir 指定。');
        }
        return $host === 'macos-arm64'
            ? $base . '/Library/Application Support/webman-aot-composer'
            : $base . '/webman-aot-composer';
    }

    private function state(string $path): string
    {
        if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $path)) {
            $path = (string) getcwd() . '/' . $path;
        }
        $probe = $path;
        while (!is_dir($probe)) {
            if (is_link($probe) || file_exists($probe)) {
                throw new \RuntimeException('状态目录不能是文件或链接。');
            }
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new \RuntimeException('状态目录无法解析。');
            }
            $probe = $parent;
        }
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true))) {
            throw new \RuntimeException('无法创建独立状态目录。');
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || dirname($resolved) === $resolved) {
            throw new \RuntimeException('状态目录不能是系统根目录。');
        }
        foreach (['runtime', 'bin', 'cache', 'ready.json', 'setup.lock', 'owner.json'] as $name) {
            if (is_link($resolved . '/' . $name)) {
                throw new \RuntimeException('状态子目录不能是链接：' . $name);
            }
        }
        $this->assertOwnership($resolved);
        return $resolved;
    }

    private function assertOwnership(string $state): void
    {
        $file = $state . '/owner.json';
        if (is_file($file)) {
            $owner = json_decode((string) file_get_contents($file), true);
            if (!is_array($owner) || ($owner['schema'] ?? null) !== 1
                || ($owner['package'] ?? '') !== 'saiadmin/webman-aot-builder') {
                throw new \RuntimeException('状态目录不属于本 Composer 入口；不会接管或覆盖。');
            }
            return;
        }
        foreach (['runtime', 'bin'] as $name) {
            $path = $state . '/' . $name;
            if (file_exists($path) && (!is_dir($path) || count(scandir($path) ?: []) > 2)) {
                throw new \RuntimeException('状态目录包含无本入口所有权标记的已有 ' . $name . '；不会覆盖，请另选空目录。');
            }
        }
        if (file_exists($state . '/ready.json')) {
            throw new \RuntimeException('已有就绪记录没有本入口所有权；不会接管，请另选空目录。');
        }
    }

    private function ready(string $state, string $host): bool
    {
        $marker = $state . '/ready.json';
        $data = is_file($marker) && !is_link($marker) ? json_decode((string) file_get_contents($marker), true) : null;
        $php = $state . '/runtime/current/runtime/' . ($host === 'macos-arm64' ? 'bin/php' : 'php.exe');
        return is_array($data) && ($data['version'] ?? '') === $this->release['version']
            && ($data['host'] ?? '') === $host && is_file($php) && !is_link($php)
            && is_file($state . '/runtime/current/app/bin/webman-aot-builder.php');
    }

    private function prepare(string $state, string $host, array $options): void
    {
        if ($this->ready($state, $host)) {
            return;
        }
        if (!isset($options['archive']) && !isset($options['yes']) && !$this->interactive) {
            throw new \RuntimeException('尚未准备运行时。非交互模式不会等待输入；运行 webman-aot setup --yes 或 setup --archive=完整包路径 --non-interactive。');
        }
        $lockFile = $state . '/setup.lock';
        if (is_link($lockFile)) {
            throw new \RuntimeException('安装锁不能是链接。');
        }
        $lock = fopen($lockFile, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('另一个入口正在准备资源；请稍后重试。');
        }
        $started = microtime(true);
        $extract = null;
        try {
            $this->assertOwnership($state);
            if (!is_file($state . '/owner.json')
                && file_put_contents($state . '/owner.json', json_encode([
                    'schema' => 1, 'package' => 'saiadmin/webman-aot-builder',
                ], JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('无法写入隔离状态所有权。');
            }
            if ($this->ready($state, $host)) {
                return;
            }
            $package = $this->release['packages'][$host];
            $this->say('[准备] ' . $this->release['version'] . ' / ' . $host . ' 完整包，' . $package['size'] . ' 字节。');
            $archive = isset($options['archive']) ? self::inputPath((string) $options['archive']) : null;
            if ($archive === null) {
                try {
                    $archive = $this->download($state, $package, $host);
                } catch (\Throwable $failure) {
                    $this->say('在线准备失败：' . $failure->getMessage());
                    $this->say("请下载这个完整安装包：\n" . $package['filename'] . "\n" . $package['url']);
                    if (!$this->interactive) {
                        throw new \RuntimeException('网络失败；下载后使用 setup --archive=完整路径 --non-interactive 导入。');
                    }
                    $archive = $this->offline($package);
                }
            }
            Archive::verify($archive, $package);
            $this->say('[校验] 大小与 SHA-256 通过。');
            $extract = $state . '/cache/extract-' . bin2hex(random_bytes(8));
            $this->say('[解包] 检查安全路径并解包原始完整安装包。');
            Archive::extract($archive, $extract, $host);
            Archive::identity($extract, $host, $this->release['version']);
            $this->say('[安装] 安装到独立目录，不改原安装与 PATH。');
            $command = $host === 'macos-arm64'
                ? ['/bin/sh', $extract . '/install.sh', '--home', $state . '/runtime', '--bin-dir', $state . '/bin', '--no-path']
                : ['powershell.exe', '-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $extract . '/install.ps1',
                    '-InstallRoot', $state . '/runtime', '-BinDir', $state . '/bin', '-NoPath'];
            $code = Process::run($command, $extract);
            if ($code !== 0) {
                throw new \RuntimeException('原安装器失败，退出码 ' . $code . '。已保留包缓存，修复后可重试。');
            }
            $code = $this->forward($state, $host, ['version'], (string) getcwd());
            if ($code !== 0) {
                throw new \RuntimeException('私有运行时自检失败，退出码 ' . $code);
            }
            $marker = json_encode(['version' => $this->release['version'], 'host' => $host], JSON_THROW_ON_ERROR);
            if (file_put_contents($state . '/ready.json', $marker . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('无法记录就绪状态。');
            }
            $this->say(sprintf('[成功] 资源准备完成，耗时 %.1f 秒。', microtime(true) - $started));
        } finally {
            if (is_string($extract)) {
                $this->removeTemporary($extract);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function download(string $state, array $package, string $host): string
    {
        $cache = $state . '/cache';
        if (!is_dir($cache) && !mkdir($cache, 0700, true)) {
            throw new \RuntimeException('无法创建资源缓存。');
        }
        $archive = $cache . '/' . $package['filename'];
        if (is_file($archive)) {
            try {
                Archive::verify($archive, $package);
                $this->say('[缓存] 复用已经校验的完整包。');
                return $archive;
            } catch (\Throwable) {
                $this->say('[缓存] 旧缓存无效，将取得新的完整包。');
            }
        }
        $partial = $cache . '/download-' . bin2hex(random_bytes(8));
        $curl = $host === 'macos-arm64' ? '/usr/bin/curl' : ((string) getenv('SystemRoot')) . '\\System32\\curl.exe';
        $this->say('[下载] ' . $package['url'] . "\n保留实际下载进度，最多重试 2 次；Ctrl+C 可取消。");
        try {
            $code = Process::run([$curl, '--fail', '--location', '--proto', '=https', '--proto-redir', '=https',
                '--retry', '2', '--retry-all-errors', '--retry-delay', '2', '--connect-timeout', '20',
                '--max-time', '1800', '--output', $partial, $package['url']]);
            if ($code !== 0) {
                throw new \RuntimeException('curl 退出码 ' . $code);
            }
            Archive::verify($partial, $package);
            if (is_link($archive) || !rename($partial, $archive)) {
                throw new \RuntimeException('无法保存已校验完整包。');
            }
        } finally {
            if (is_file($partial) && !is_link($partial)) {
                unlink($partial);
            }
        }
        return $archive;
    }

    private function offline(array $package): string
    {
        while (true) {
            $this->say('下载后按回车检查常规 Downloads 目录，或输入/拖入完整安装包路径；输入 0 取消。');
            $line = fgets(STDIN);
            if ($line === false || trim($line) === '0') {
                throw new \RuntimeException('已取消资源准备；原项目命令尚未运行。');
            }
            if (trim($line) !== '') {
                $path = self::inputPath($line);
                try {
                    Archive::verify($path, $package);
                    return $path;
                } catch (\Throwable $error) {
                    $this->say($error->getMessage());
                    continue;
                }
            }
            $home = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
            $path = is_string($home) ? $home . '/Downloads/' . $package['filename'] : '';
            if ($path !== '' && is_file($path)) {
                try {
                    Archive::verify($path, $package);
                    $this->say('[本地] 找到并校验：' . $path);
                    return $path;
                } catch (\Throwable $error) {
                    $this->say($error->getMessage());
                }
            }
            $this->say('常规 Downloads 目录未找到匹配包。浏览器自定义目录请直接输入完整路径。');
        }
    }

    public static function inputPath(string $input): string
    {
        $input = trim($input);
        if (strlen($input) >= 2 && in_array($input[0], ['"', "'"], true)
            && substr($input, -1) === $input[0]) {
            $input = substr($input, 1, -1);
        } elseif (PHP_OS_FAMILY !== 'Windows') {
            $input = preg_replace('/\\\\([ \'"()])/', '$1', $input) ?? $input;
        }
        if ($input === '' || str_contains($input, "\0")) {
            throw new \InvalidArgumentException('完整包路径为空或无效。');
        }
        return $input;
    }

    private function forward(string $state, string $host, array $arguments, string $cwd): int
    {
        $runtime = $state . '/runtime/current/runtime';
        $entry = $state . '/runtime/current/app/bin/webman-aot-builder.php';
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['WEBMAN_AOT_BUILDER_HOME'] = $state . '/runtime';
        if ($host === 'windows-x86_64') {
            $environment['WEBMAN_AOT_CALLER_CWD'] = $cwd;
            return Process::run([$runtime . '/php.exe', '-c', 'php.ini', '-d', 'extension_dir=ext',
                $state . '/runtime/current/app/tools/windows-php-bootstrap.php', $entry, ...$arguments],
                $runtime, $environment);
        }
        return Process::run([$runtime . '/bin/php', '-n', $entry, ...$arguments], $cwd, $environment);
    }

    private function say(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function removeTemporary(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
