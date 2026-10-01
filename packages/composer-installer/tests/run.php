<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Process.php';
require dirname(__DIR__) . '/src/Archive.php';
require dirname(__DIR__) . '/src/Installer.php';

use Supdger\WebmanAotInstaller\Archive;
use Supdger\WebmanAotInstaller\Installer;
use Supdger\WebmanAotInstaller\Process;

$temporaryRoot = getenv('WEBMAN_AOT_TEST_TMP') ?: (PHP_OS_FAMILY === 'Darwin' ? '/private/tmp' : sys_get_temp_dir());
$directory = $temporaryRoot . '/composer-aot-tests-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$passed = 0;
function check(bool $condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException($message); }
    $passed++;
    fwrite(STDOUT, "[通过] {$message}\n");
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}

check(Installer::inputPath('"C:\\Download dir\\builder.zip"') === 'C:\\Download dir\\builder.zip', 'Windows带空格引号路径可解析');
check(Installer::inputPath("'/tmp/中文 目录/builder.tar.gz'") === '/tmp/中文 目录/builder.tar.gz', '中文空格引号路径可解析');
if (PHP_OS_FAMILY !== 'Windows') {
    check(Installer::inputPath('/tmp/Download\\ dir/builder.tar.gz') === '/tmp/Download dir/builder.tar.gz', 'Mac拖入转义空格可解析');
}
rejects(fn() => Installer::inputPath("bad\0path"), '拒绝空字符路径');

$file = $directory . '/完整包.bin';
file_put_contents($file, 'matching package');
$package = ['filename' => 'fixed-package', 'url' => 'https://example.invalid/fixed',
    'size' => filesize($file), 'sha256' => hash_file('sha256', $file)];
Archive::verify($file, $package);
check(true, '匹配大小和摘要的本地资源通过');
file_put_contents($file, 'tampered package');
rejects(fn() => Archive::verify($file, $package), '损坏资源拒绝');

$identity = $directory . '/identity';
mkdir($identity);
file_put_contents($identity . '/package.json', json_encode([
    'schema' => 'webman-aot-builder-installer-package-v1', 'version' => '0.3.2',
    'platform' => 'macos-arm64', 'flavor' => 'complete']));
file_put_contents($identity . '/payload-manifest.sha256', '');
mkdir($identity . '/payload/minimal-toolchain', 0700, true);
file_put_contents($identity . '/payload/minimal-toolchain/component.zip', '');
Archive::identity($identity, 'macos-arm64', '0.3.2');
check(true, '匹配完整包身份通过');
rejects(fn() => Archive::identity($identity, 'windows-x86_64', '0.3.2'), '错架构包拒绝');
rejects(fn() => Archive::identity($identity, 'macos-arm64', '0.3.1'), '错版本包拒绝');
unlink($identity . '/payload/minimal-toolchain/component.zip');
rejects(fn() => Archive::identity($identity, 'macos-arm64', '0.3.2'), '缺完整包组件拒绝');

$bin = dirname(__DIR__) . '/bin/webman-aot';
$help = Process::output([PHP_BINARY, $bin, '--help', '--state-dir=' . $directory . '/untouched']);
check(str_contains($help, '尚未') === false && str_contains($help, '目标构建器：0.3.2'), 'help报告入口和目标版本');
check(!file_exists($directory . '/untouched'), 'help不创建运行时状态');
$version = Process::output([PHP_BINARY, $bin, '--version']);
check(str_contains($version, 'Composer 入口 ' . Installer::VERSION) && str_contains($version, '目标 Webman AOT Builder 0.3.2'), 'version不冒充已安装版本');

if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
    $code = Process::run([PHP_BINARY, $bin, '--state-dir=' . $directory . '/fresh', '--non-interactive', 'build']);
    check($code !== 0 && !is_dir($directory . '/fresh/runtime'), '非交互缺资源退出不安装');
    $unowned = $directory . '/unowned';
    mkdir($unowned . '/runtime/current', 0700, true);
    file_put_contents($unowned . '/runtime/current/sentinel', 'must survive');
    $code = Process::run([PHP_BINARY, $bin, 'setup', '--state-dir=' . $unowned, '--archive=' . $file, '--non-interactive']);
    check($code !== 0 && file_get_contents($unowned . '/runtime/current/sentinel') === 'must survive'
        && !file_exists($unowned . '/owner.json'), '未owned的已有runtime不接管，哨兵和所有权状态不变');
    $oldState = $directory . '/old package state';
    mkdir($oldState . '/runtime/current', 0700, true);
    file_put_contents($oldState . '/runtime/current/sentinel', 'legacy-owned');
    file_put_contents($oldState . '/owner.json', json_encode(['schema' => 1, 'package' => 'saiadmin/webman-aot-builder']));
    $code = Process::run([PHP_BINARY, $bin, 'setup', '--state-dir=' . $oldState, '--archive=' . $file, '--non-interactive']);
    check($code === 70 && file_get_contents($oldState . '/runtime/current/sentinel') === 'legacy-owned'
        && json_decode(file_get_contents($oldState . '/owner.json'), true)['package'] === 'saiadmin/webman-aot-builder', '新setup不接管旧包owner，不修改旧payload并提示逐项卸载迁移');
    $state = $directory . '/fake state';
    mkdir($state . '/runtime/current/runtime/bin', 0700, true);
    mkdir($state . '/runtime/current/app/bin', 0700, true);
    $record = $directory . '/forward.json';
    // A tiny process fixture observes the invocation, without installing or compiling anything.
    file_put_contents($state . '/runtime/current/runtime/bin/php', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
    chmod($state . '/runtime/current/runtime/bin/php', 0700);
    file_put_contents($state . '/runtime/current/app/bin/webman-aot-builder.php',
        '<?php file_put_contents(' . var_export($record, true) . ', json_encode([getcwd(), array_slice($argv,1), getenv("WEBMAN_AOT_BUILDER_HOME")])); exit(23);');
    file_put_contents($state . '/owner.json', json_encode(['schema' => 1, 'package' => 'supdger/webman-aot-builder']));
    file_put_contents($state . '/ready.json', json_encode(['version' => '0.3.2', 'host' => 'macos-arm64']));
    $code = Process::run([PHP_BINARY, $bin, '--state-dir=' . $state, '--non-interactive', 'build', '--profile=saiadmin', '--', '--yes', '--archive=not-a-bridge-option', '--non-interactive'], $directory);
    $actual = json_decode((string) file_get_contents($record), true);
    check($code === 23, '原程序退出码透传');
    check($actual[0] === realpath($directory) && $actual[1] === ['build', '--profile=saiadmin', '--', '--yes', '--archive=not-a-bridge-option', '--non-interactive'], '原项目cwd和argv透传');
    check($actual[2] === realpath($state) . '/runtime', '私有运行时使用隔离home');
}
fwrite(STDOUT, "完成：{$passed} 项行为检查通过。测试资源：{$directory}\n");
