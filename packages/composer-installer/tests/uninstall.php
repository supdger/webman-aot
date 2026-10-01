<?php
declare(strict_types=1);

// Every operation is isolated under a new temporary HOME; no real installation or PATH is changed.
$repository = dirname(__DIR__, 3);
$temporary = rtrim((string) realpath(sys_get_temp_dir()), '/') . '/aot-uninstall-tests-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    fwrite(STDOUT, "[通过] {$message}\n");
}
function directory(string $path): void { if (!is_dir($path)) { mkdir($path, 0700, true); } }
function native(string $path, string $version, bool $legacy = false): void {
    global $repository;
    directory($path . '/app/src'); directory($path . '/app/bin'); directory($path . '/runtime');
    $namespace = $legacy ? 'WebmanAot' : 'WebmanAotBuilder';
    file_put_contents($path . '/app/src/Version.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class Version\n{\n    public const VALUE = '{$version}';\n}\n");
    $entry = $legacy ? 'webman-aot.php' : 'webman-aot-builder.php';
    $content = $legacy ? shell_exec('git -C ' . escapeshellarg($repository) . ' show v0.1.2:bin/webman-aot.php')
        : file_get_contents($repository . '/bin/webman-aot-builder.php');
    file_put_contents($path . '/app/bin/' . $entry, $content);
    file_put_contents($path . '/manifest.json', json_encode(['schema' => 'webman-aot-builder-cli-generation-v1', 'version' => $version]));
}
/** @return array{int,string} */
function execute(array $arguments, string $input = '', bool $interactive = true, array $extraEnvironment = []): array {
    global $temporary, $repository;
    $environment = array_merge(getenv(), ['HOME' => $temporary . '/用户 空格', 'USERPROFILE' => $temporary . '/用户 空格',
        'LOCALAPPDATA' => $temporary . '/local', 'APPDATA' => $temporary . '/appdata', 'COMPOSER_HOME' => $temporary . '/global',
        'XDG_CONFIG_HOME' => $temporary . '/config', 'PATH' => $temporary . '/fake-bin:/usr/bin:/bin',
        'WEBMAN_AOT_HOME' => '', 'WEBMAN_AOT_BUILDER_HOME' => ''], $extraEnvironment);
    $engine = $repository . '/packages/composer-installer/src/Uninstaller.php';
    $command = $interactive ? [PHP_BINARY, '-r', 'require $argv[1]; exit((new SaiAdmin\\WebmanAotInstaller\\Uninstaller(true))->run(array_slice($argv, 2)));', $engine]
        : [PHP_BINARY, $repository . '/packages/composer-installer/bin/webman-aot', 'uninstall'];
    $process = proc_open(array_merge($command, $arguments), [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes, $repository, $environment);
    if (!is_resource($process)) { throw new RuntimeException('无法启动测试'); }
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process), (string) $output];
}
function clean(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { clean($path . '/' . $name); } } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
}
try {
    $root = $temporary . '/独立 安装';
    $bin = $temporary . '/命令 空格';
    directory($bin);
    native($root . '/current', '0.3.2');
    native($root . '/versions/00000000000000000001-0.3.1', '0.3.1');
    native($root . '/versions/00000000000000000002-0.3.2', '0.3.2');
    native($root . '/.install-backups/previous/current', '0.2.3');
    directory($root . '/toolchains'); file_put_contents($root . '/toolchains/sentinel', 'keep');
    directory($temporary . '/project/dist-aot'); file_put_contents($temporary . '/project/dist-aot/sentinel', 'keep');
    file_put_contents($bin . '/webman-aot', file_get_contents($repository . '/bin/webman-aot'));
    directory($root . '/.previous-launcher');
    $legacy = shell_exec('git -C ' . escapeshellarg($repository) . ' show v0.1.2:bin/webman-aot');
    file_put_contents($root . '/.previous-launcher/webman-aot', $legacy);
    $options = ['--home=' . $root, '--home=' . $root . '/', '--bin-dir=' . $bin, '--bin-dir=' . $bin . '/'];
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify($code === 0 && substr_count($output, '独立安装（当前）') === 1 && str_contains($output, '0.3.1') && str_contains($output, '历史备份'), '只读列多版本、备份、中文空格并去重');
    verify(is_dir($root . '/current') && !file_exists($temporary . '/用户 空格'), 'list不创建HOME或状态，不删除安装');
    [$code, $output] = execute($options, "y\ny\n", false);
    verify($code === 0 && is_dir($root . '/current') && str_contains($output, '非交互'), '产品Composer入口非TTY即使收到y仍默认保留');
    [$code, $output] = execute($options, "\nn\nq\n");
    verify($code === 0 && is_dir($root . '/current') && is_dir($root . '/versions/00000000000000000001-0.3.1'), '回车n与q保留当前和其他版本');
    [$code, $output] = execute($options, '');
    verify($code === 0 && is_dir($root . '/current') && str_contains($output, '未确认'), 'EOF不删除');
    [$code, $output] = execute($options, "n\ny\nq\n");
    verify($code === 0 && !file_exists($root . '/versions/00000000000000000002-0.3.2') && is_dir($root . '/versions/00000000000000000001-0.3.1'), '逐项仅删活动generation，旧generation保留');
    // Explicit root alone cannot prove that a standard launcher points to it.
    verify(is_file($bin . '/webman-aot'), '自定义root不猜测publiclauncher目标');
    native($root . '/versions/00000000000000000002-0.3.2', '0.3.2');
    [$code, $output] = execute($options, "n\ny\nq\n", true, ['WEBMAN_AOT_BUILDER_HOME' => $root]);
    verify($code === 0 && !file_exists($bin . '/webman-aot') && str_contains($output, '已撤销命令'), '明确绑定时删活动版本同时撤销入口，旧版本不自动公开启用');
    verify(is_file($root . '/.previous-launcher/webman-aot'), '备份旧命令未选择不恢复不删除');
    verify(is_file($root . '/toolchains/sentinel') && is_file($temporary . '/project/dist-aot/sentinel'), '共享工具链及项目dist成果保留');
    $unknown = "#!/bin/sh\n# WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER\n# WEBMAN_AOT_BUILDER_HOME /current/runtime /current/app/bin/webman-aot-builder.php\ntouch '" . $temporary . "/must-not-execute'\n";
    file_put_contents($bin . '/webman-aot', $unknown);
    [$code, $output] = execute($options, "n\nn\nn\nn\ny\n");
    verify(is_file($bin . '/webman-aot') && !file_exists($temporary . '/must-not-execute') && str_contains($output, '无足够所有权'), '伪造关键词launcher保留且从不执行');
    $linkRoot = $temporary . '/linked-root'; symlink($root, $linkRoot);
    [$code, $output] = execute(['--home=' . $linkRoot, '--list']);
    verify($code === 0 && str_contains($output, '归属') && is_dir($root . '/current'), '符号链接managedroot拒绝卸载');
    symlink($temporary . '/project', $root . '/current/app/external');
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify(str_contains($output, '无法安全确认') && is_file($temporary . '/project/dist-aot/sentinel'), '版本子树链接拒绝删除');
    unlink($root . '/current/app/external');
    file_put_contents($root . '/current/unowned-file', 'keep');
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify(str_contains($output, '无法安全确认') && is_file($root . '/current/unowned-file'), '版本目录未知附加文件保持');
    if (PHP_OS_FAMILY !== 'Windows') {
        $literal = $temporary . '/collision/aot\\history';
        $nested = $temporary . '/collision/aot/history';
        native($literal . '/current', '0.3.1'); native($nested . '/current', '0.3.2');
        [$code, $output] = execute(['--home=' . $literal, '--list']);
        verify($code === 0 && str_contains($output, $literal . '/current') && !str_contains($output, $nested . '/current'), 'Mac真实反斜杠路径不转成另一目录，也不漏掉目标');
        [$code, $output] = execute(['--home=' . $literal], "y\n");
        verify($code === 0 && !is_dir($literal . '/current') && is_dir($nested . '/current'), '路径collision仅删除明确选择的literal安装，另一真实安装保留');
        foreach (['trailing-space ' => 'trailing-space', 'quote"' => 'quote'] as $name => $otherName) {
            $exact = $temporary . '/collision/' . $name;
            $otherPath = $temporary . '/collision/' . $otherName;
            native($exact . '/current', '0.3.1'); native($otherPath . '/current', '0.3.2');
            [$code, $output] = execute(['--home=' . $exact], "y\n");
            verify($code === 0 && !is_dir($exact . '/current') && is_dir($otherPath . '/current'), 'Mac路径末尾空格或引号保留真实字节，只删目标：' . $name);
        }

    }
    $state = $temporary . '/composer 状态'; directory($state . '/runtime/current');
    file_put_contents($state . '/owner.json', json_encode(['schema' => 1, 'package' => 'saiadmin/webman-aot-builder']));
    file_put_contents($state . '/ready.json', json_encode(['version' => '0.3.2']));
    file_put_contents($state . '/runtime/current/payload', 'owned'); file_put_contents($state . '/user-file', 'keep');
    $heldLock = fopen($state . '/setup.lock', 'c+');
    flock($heldLock, LOCK_EX);
    [$code, $output] = execute(['--state-dir=' . $state], "y\n");
    verify($code === 70 && is_file($state . '/owner.json') && is_file($state . '/runtime/current/payload'), '持锁时拒绝卸载，owner与payload保持');
    $lockInode = fileinode($state . '/setup.lock');
    flock($heldLock, LOCK_UN); fclose($heldLock);
    [$code, $output] = execute(['--state-dir=' . $state], "y\n");
    verify($code === 0 && !is_dir($state . '/runtime') && is_file($state . '/user-file'), 'Composerowner白名单卸载，额外文件保持');
    verify(is_file($state . '/setup.lock') && fileinode($state . '/setup.lock') === $lockInode, '卸载保留原锁inode与状态根，防止并发setup创建两把锁');
    $badState = $temporary . '/badstate'; directory($badState . '/runtime'); file_put_contents($badState . '/runtime/sentinel', 'keep');
    [$code, $output] = execute(['--state-dir=' . $badState], "y\n");
    verify(is_file($badState . '/runtime/sentinel') && str_contains($output, '保留'), '无ownerComposer状态保留');
    $global = $temporary . '/global'; directory($global); directory($temporary . '/fake-bin');
    file_put_contents($global . '/composer.json', json_encode(['require' => ['saiadmin/webman-aot-builder' => '^0.3.3', 'other/tool' => '*']]));
    file_put_contents($global . '/composer.lock', json_encode(['packages' => [['name' => 'saiadmin/webman-aot-builder', 'version' => '0.3.3']]]));
    $fakeComposer = $temporary . '/fake-bin/composer';
    file_put_contents($fakeComposer, "#!/bin/sh\nprintf '%s\\n' \"\$COMPOSER_HOME\" \"\$@\" > " . escapeshellarg($temporary . '/composer-args') . "\nexit 7\n"); chmod($fakeComposer, 0700);
    [$code, $output] = execute([], "y\n");
    verify($code === 70 && str_contains($output, '退出码 7') && isset(json_decode(file_get_contents($global . '/composer.json'), true)['require']['other/tool']), 'Composer失败非零且其他全局包记录保持');
    $args = file_get_contents($temporary . '/composer-args');
    verify(str_starts_with($args, $global . "\n") && str_contains($args, "global\nremove\n--no-interaction\nsaiadmin/webman-aot-builder") && str_contains($args, '--no-scripts') && str_contains($args, '--no-plugins'), 'Composer指向选定globalhome且精确package，禁用hook与plugin');
    verify(str_contains($output, '[剩余]') && str_contains($output, $global), '完成重新检查并显示残留路径');
    [$code, $output] = execute(['--yes']); verify($code !== 0 && str_contains($output, '免确认'), '无免确认全删入口');
    $legacyRoot = $temporary . '/用户 空格/Library/Application Support/webman-aot';
    native($legacyRoot . '/current', '0.1.2', true);
    native($legacyRoot . '/.install-backups/current-previous', '0.1.2', true);
    [$code, $output] = execute(['--list']);
    verify($code === 0 && str_contains($output, '旧版历史备份') && substr_count($output, '0.1.2') >= 2, 'v0.1.2直接app/runtime备份真实布局被识别');
    [$code, $output] = execute([], "n\ny\nq\n");
    verify($code === 0 && is_dir($legacyRoot . '/current') && !is_dir($legacyRoot . '/.install-backups/current-previous'), '旧版真实备份可逐项删且当前0.1.2保留');
    [$code, $output] = execute(['--home=' . $repository, '--list']); verify($code === 0, 'project工作区根仅安全列出不扫描成果');
    // A missing state directory on the real public entry never triggers preparation or download.
    [$code, $output] = execute(['--state-dir=' . $temporary . '/missing', '--list'], '', false);
    verify($code === 0 && !file_exists($temporary . '/missing'), '公开uninstall --state-dir不存在也不prepare不创建状态');
    fwrite(STDOUT, "[结果] {$checks} checks passed; all writes isolated under {$temporary}\n");
} finally { clean($temporary); }
