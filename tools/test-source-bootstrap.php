<?php

declare(strict_types=1);

// Behavioral regression: real shell entry and private archives; no real user install or network.
if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
    fwrite(STDERR, "macOS ARM64 is required; this cannot validate Windows.\n");
    exit(78);
}
$root = dirname(__DIR__);
$base = $argv[1] ?? sys_get_temp_dir() . '/source-bootstrap-test-' . bin2hex(random_bytes(6));
if (file_exists($base)) {
    throw new RuntimeException('Use a new fixture directory');
}
mkdir($base, 0700, true);
$fixture = $base . '/源码 with spaces 中文';
foreach (['tools', 'installer', 'dist/installer-inputs', 'archive/bin', 'fake-bin'] as $directory) {
    mkdir($fixture . '/' . $directory, 0700, true);
}
copy($root . '/build.command', $fixture . '/build.command');
copy($root . '/tools/source-bootstrap-macos.sh', $fixture . '/tools/source-bootstrap-macos.sh');
$php = "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"\$BOOTSTRAP_PROBE\"\necho 'locked PHP fixture invoked'\n";
file_put_contents($fixture . '/archive/bin/php', $php);
file_put_contents($fixture . '/archive/bin/compiler', 'private compiler fixture');
chmod($fixture . '/archive/bin/php', 0700);
$archive = $fixture . '/dist/installer-inputs/materials.tar.gz';
function runFixture(array $command, string $cwd, array $environment): array
{
    $p = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}
[$code] = runFixture(['/usr/bin/tar', '-czf', $archive, '-C', $fixture . '/archive', 'bin'], $fixture, getenv());
if ($code !== 0) {
    throw new RuntimeException('Fixture archive creation failed');
}
$digest = hash_file('sha256', $archive);
file_put_contents($fixture . '/tools/source-build-materials.lock', "schema webman-aot-builder-source-materials-v1\nurl https://fixture.invalid/materials.tar.gz\nsha256 {$digest}\nsize " . filesize($archive) . "\nruntime bin/php\ncompiler bin/compiler\nlicenses licenses\nsource php.tar.xz\n");
file_put_contents($fixture . '/installer/runtime.lock.json', json_encode(['runtimes' => ['macos-arm64' => ['binarySha256' => hash_file('sha256', $fixture . '/archive/bin/php'), 'compilerDriver' => ['binarySha256' => hash_file('sha256', $fixture . '/archive/bin/compiler')]]]], JSON_THROW_ON_ERROR));
$curl = <<<'SH'
#!/bin/sh
printf '%s\n' "$@" >> "$CURL_PROBE"
if [ "$CURL_MODE" = fail ]; then echo 'curl: fixture DNS failure' >&2; exit 6; fi
while [ "$#" -gt 0 ]; do
  if [ "$1" = --output ]; then printf 'corrupt download' > "$2"; fi
  shift
done
exit 0
SH;
file_put_contents($fixture . '/fake-bin/curl', $curl);
chmod($fixture . '/fake-bin/curl', 0700);
$environment = getenv();
$environment['PATH'] = $fixture . '/fake-bin:/usr/bin:/bin';
$environment['BOOTSTRAP_PROBE'] = $base . '/php-calls';
$environment['CURL_PROBE'] = $base . '/curl-calls';
$environment['CURL_MODE'] = 'corrupt';
$log = $base . '/results.log';
$count = 0;
function checkFixture(bool $ok, string $name, string $output): void
{
    global $count, $log;
    file_put_contents($log, $name . "\n" . $output . "\n", FILE_APPEND);
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $count++;
    fwrite(STDOUT, "PASS {$name}\n");
}
$command = ['/bin/sh', $fixture . '/build.command', '--flavor=small', '--home=' . $base . '/私有 home'];
[$code, $out] = runFixture($command, $fixture, $environment);
$probe = (string) file_get_contents($base . '/php-calls');
checkFixture($code === 0 && str_contains($out, '跳过下载') && !file_exists($base . '/curl-calls') && str_contains($probe, '--home=' . $base . '/私有 home') && str_contains($probe, '--mode=source'), 'locked private PHP without system PHP; exact Unicode arguments; verified cache', $out);
$extracted = $fixture . '/dist/installer-inputs/macos-materials-' . $digest . '/bin/php';
file_put_contents($extracted, '# invalid cached PHP');
[$code, $out] = runFixture($command, $fixture, $environment);
checkFixture($code === 0 && str_contains($out, '解压') && str_contains($out, 'locked PHP fixture invoked'), 'modified extracted PHP is replaced by a fresh verified generation', $out);
$before = filesize($base . '/php-calls');
file_put_contents($archive, 'bad cached archive');
[$code, $out] = runFixture($command, $fixture, $environment);
clearstatcache(true, $base . '/php-calls');
$curlArgs = (string) file_get_contents($base . '/curl-calls');
checkFixture($code === 65 && filesize($base . '/php-calls') === $before && str_contains($out, 'SHA-256 不符') && str_contains($out, 'issues'), 'bad archive/digest refuses execution with nonzero result and recovery', $out);
checkFixture(str_contains($curlArgs, "--no-progress-bar\n--no-silent\n--progress-meter") && !str_contains($curlArgs, "-q\n"), 'download command preserves user configuration and selects readable statistics', $curlArgs);
$environment['CURL_MODE'] = 'fail';
[$code, $out] = runFixture($command, $fixture, $environment);
checkFixture($code === 6 && str_contains($out, 'DNS') && str_contains($out, 'fixture DNS failure'), 'network failure retains real curl exit and original diagnostic', $out);
fwrite(STDOUT, "{$count} source bootstrap checks passed; log: {$log}\n");
