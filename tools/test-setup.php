<?php

declare(strict_types=1);

// Behavioral checks run actual generated launchers with private archives and an explicit PATH transport fixture.
// The transport fixture never enters production templates; no TLS/hash bypass is exposed by the product.
if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
    fwrite(STDERR, "This setup behavior suite requires macOS ARM64; Windows native acceptance remains separate.\n");
    exit(78);
}
$root = dirname(__DIR__);
$base = $argv[1] ?? sys_get_temp_dir() . '/webman-aot-setup-test-' . bin2hex(random_bytes(6));
if (file_exists($base)) { throw new RuntimeException('test directory must be new'); }
mkdir($base, 0700, true);
$count = 0;
function check(bool $condition, string $description): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
    fwrite(STDOUT, 'PASS ' . ++$count . ': ' . $description . "\n");
}
function invoke(array $command, array $env = [], string $input = ''): array {
    global $base;
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]], $pipes, null, array_merge(getenv(), ['LANG'=>'C.UTF-8','LC_CTYPE'=>'C.UTF-8'], $env));
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $text = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
    $code = proc_close($process);
    if (preg_match('//u', $text) !== 1) { throw new RuntimeException('human output contains invalid UTF-8'); }
    file_put_contents($base . '/results.log', $text . "\n[exit {$code}]\n", FILE_APPEND);
    return ['code'=>$code,'text'=>$text];
}
function fixture(string $directory, string $flavor, string $version='0.2.3', string $revision='fixture123', int $padding=0): array {
    global $root;
    mkdir($directory, 0700, true);
    $name='webman-aot-builder-' . $version . ($flavor==='complete'?'-full':'') . '-macos-arm64.tar.gz';
    $tarPath=$directory . '/fixture.tar';
    $tar=new PharData($tarPath);
    $tar->addFromString('package.json', json_encode(['schema'=>'webman-aot-builder-installer-package-v1','version'=>$version,'revision'=>$revision,'platform'=>'macos-arm64','flavor'=>$flavor]));
    $runtime= <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$SETUP_CAPTURE"
printf 'Shared fixture reached.\n'
exit "${SETUP_PAYLOAD_EXIT:-0}"
SH;
    $tar->addFromString('payload/runtime/bin/php', $runtime);
    $tar['payload/runtime/bin/php']->chmod(0700);
    $tar->addFromString('payload/app/tools/guided.php', '<?php // shared fixture');
    if ($padding > 0) { $tar->addFromString('payload/padding.bin', random_bytes($padding)); }
    $tar->addFromString('install.command', (string) file_get_contents($root . '/installer/macos/install.command'));
    $tar['install.command']->chmod(0700);
    $tar->compress(Phar::GZ); unset($tar);
    $path=$directory . '/' . $name;
    rename($tarPath . '.gz', $path);
    $result=['schema'=>'webman-aot-builder-installer-package-result-v1','revision'=>$revision,'packages'=>[['platform'=>'macos-arm64','path'=>$path,'sha256'=>hash_file('sha256',$path),'size'=>filesize($path)]]];
    file_put_contents($directory . '/result.json', json_encode($result));
    return ['path'=>$path,'result'=>$directory . '/result.json','data'=>$result];
}
$small=fixture($base . '/small','small');
$full=fixture($base . '/full','complete');
$output=$base . '/generated';
function generate(string $small, string $full, string $url='https://example.invalid/releases/v0.2.3'): array {
    global $root,$output;
    return invoke([PHP_BINARY,$root . '/tools/package-setup.php','--small-result='.$small,'--full-result='.$full,'--platform=macos-arm64','--base-url='.$url,'--output='.$output]);
}
try {
    $r=generate($small['result'],$full['result']);
    check($r['code']===0,'verified actual small/full result files produce a standalone setup');
    $setup=$output . '/webman-aot-builder-0.2.3-macos-arm64-setup.command';
    check(is_executable($setup),'generated macOS command has executable permission');
    $download=$output . '/webman-aot-builder-0.2.3-macos-arm64-setup.zip';
    $zip=new ZipArchive();$zip->open($download);$opsys=0;$attributes=0;
    $zip->getExternalAttributesIndex(0,$opsys,$attributes);
    check($zip->numFiles===1 && $zip->getNameIndex(0)===basename($setup) && $opsys===ZipArchive::OPSYS_UNIX && (($attributes>>16)&0777)===0755,'download ZIP contains only launcher and preserves UNIX 0755 execution mode');
    $zip->close();
    $unpacked=$base.'/unpacked';mkdir($unpacked,0700);
    $r=invoke(['/usr/bin/ditto','-x','-k',$download,$unpacked]);
    $unpackedSetup=$unpacked.'/'.basename($setup);
    check($r['code']===0 && is_executable($unpackedSetup),'macOS ditto extraction preserves launcher execution mode');
    $r=invoke([$unpackedSetup],['HOME'=>$base.'/zip-home','PATH'=>'/usr/bin:/bin:/usr/sbin:/sbin']);
    check($r['code']===0 && str_contains($r['text'],'取消'),'extracted setup executes directly without chmod and cancels safely');
    $other=fixture($base . '/other-version','complete','0.2.4');
    check(generate($small['result'],$other['result'])['code']!==0,'mismatched version rejected');
    check(generate($full['result'],$small['result'])['code']!==0,'swapped flavor rejected');
    foreach (['http://example.invalid','https://example.invalid/$(touch-pwn)','https://example.invalid/path?x=1',"https://example.invalid/path\nnext", "https://user:pass@example.invalid/path"] as $url) {
        check(generate($small['result'],$full['result'],$url)['code']!==0,'unsafe base URL rejected');
    }
    $bad=$small['data'];$bad['packages'][0]['sha256']=str_repeat('0',64);
    file_put_contents($base.'/bad.json',json_encode($bad));
    check(generate($base.'/bad.json',$full['result'])['code']!==0,'archive/result digest mismatch rejected');
    $bad=$small['data'];$bad['revision']='bad;command';
    file_put_contents($base.'/bad.json',json_encode($bad));
    check(generate($base.'/bad.json',$full['result'])['code']!==0,'unsafe revision identity rejected');
    $openProcess=proc_open(['/bin/bash',$setup],[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$openPipes,null,array_merge(getenv(),['LANG'=>'C.UTF-8','LC_CTYPE'=>'C.UTF-8','HOME'=>$base.'/open-pipe-home','PATH'=>'/usr/bin:/bin:/usr/sbin:/sbin']));
    stream_set_blocking($openPipes[1],false);$started=microtime(true);$openText='';
    do {
        $openText.=(string)stream_get_contents($openPipes[1]);$status=proc_get_status($openProcess);
        if (!$status['running']) { break; }
        usleep(10000);
    } while (microtime(true)-$started<1.5);
    if ($status['running']) { proc_terminate($openProcess); }
    $openText.=(string)stream_get_contents($openPipes[1]);fclose($openPipes[0]);fclose($openPipes[1]);$closed=proc_close($openProcess);
    check(!$status['running'] && $status['exitcode']===0 && str_contains($openText,'未指定包类型') && preg_match('//u',$openText)===1,'non-TTY open pipe without flavor cancels promptly with valid UTF-8');
    $capture=$base.'/capture';
    $env=['HOME'=>$base.'/private home 中文','PATH'=>'/usr/bin:/bin:/usr/sbin:/sbin','SETUP_CAPTURE'=>$capture];
    mkdir($env['HOME'],0700,true);
    $r=invoke(['/bin/bash',$setup],$env);
    check($r['code']===0 && !file_exists($capture),'non-TTY EOF cancels before download or payload execution');
    $ptyDriver= <<<'PYCODE'
import os,pty,subprocess,select,sys,time
master,slave=pty.openpty()
child=subprocess.Popen(['/bin/bash',sys.argv[1]],stdin=slave,stdout=slave,stderr=slave)
os.close(slave)
output=b'';sent=False;deadline=time.monotonic()+5
try:
    while time.monotonic()<deadline:
        ready,_,_=select.select([master],[],[],.05)
        if ready:
            try: chunk=os.read(master,65536)
            except OSError: chunk=b''
            if chunk:
                output+=chunk;sys.stdout.buffer.write(chunk);sys.stdout.buffer.flush()
                if not sent and '请输入'.encode() in output:
                    os.write(master,b'bad\n0\n\n');sent=True
        if child.poll() is not None: break
    if child.poll() is None: raise RuntimeError('TTY fixture timed out')
    sys.exit(child.returncode)
finally:
    if child.poll() is None: child.terminate();child.wait(timeout=2)
    os.close(master)
PYCODE;
    $r=invoke(['/usr/bin/python3','-c',$ptyDriver,$setup],$env);
    check($r['code']===0 && str_contains($r['text'],'请选择') && str_contains($r['text'],'0.2.3：选择安装包') && !file_exists($capture),'invalid menu input retries and cancellation is safe');
    $r=invoke(['/bin/bash',$setup,'--flavor=small','--archive='.$small['path'],'--install','--home='.$base.'/安装 home','--bin-dir='.$base.'/bin 空格','--no-path','--project='.$base.'/项目 空格'],$env);
    $arguments=(string) file_get_contents($capture);
    check($r['code']===0 && str_contains($arguments,'--mode=install') && str_contains($arguments,'--home='.$base.'/安装 home') && str_contains($arguments,'--project='.$base.'/项目 空格'),'verified offline archive reaches shared flow with Chinese/space arguments intact');
    check(str_contains($r['text'],'外层 SHA-256 校验成功') && str_contains($r['text'],'准备 0.2.3 / macos-arm64 / small（'),'outer hash success, version and flavor are valid visible UTF-8');
    $r=invoke(['/bin/bash',$setup,'--flavor=small','--archive='.$small['path']],array_merge($env,['SETUP_PAYLOAD_EXIT'=>'23']));
    check($r['code']===23 && str_contains($r['text'],'退出码 23') && preg_match('//u',$r['text'])===1,'shared child failure preserves exit code and UTF-8 Chinese status');
    unlink($capture);
    $direct=$base.'/direct package 中文';mkdir($direct,0700);
    $r=invoke(['/usr/bin/tar','-xzf',$small['path'],'-C',$direct]);
    check($r['code']===0,'direct package entry fixture extracted');
    $r=invoke([$direct.'/install.command','--install','--home='.$base.'/direct home','--bin-dir='.$base.'/direct bin','--no-path'],array_merge($env,['SETUP_PAYLOAD_EXIT'=>'23']));
    check($r['code']===23 && str_contains($r['text'],'退出码 23') && preg_match('//u',$r['text'])===1,'direct install.command preserves child exit code and valid UTF-8 without non-TTY pause');
    unlink($capture);
    $corrupt=$base.'/corrupt.tar.gz';file_put_contents($corrupt,'bad archive');
    $r=invoke(['/bin/bash',$setup,'--flavor=small','--archive='.$corrupt],$env);
    check($r['code']===65 && !file_exists($capture) && str_contains($r['text'],'不会执行包内代码'),'corrupt archive fails before any payload execution');
    $shim=$base.'/transport';mkdir($shim,0700);
    $curl= <<<'SH'
#!/bin/sh
printf '%s\n' "$@" >> "$SETUP_CURL_ARGS"
if [ "${SETUP_CURL_FAIL:-0}" != 0 ]; then echo 'curl fixture connection failed' >&2; exit 7; fi
while [ "$#" -gt 0 ]; do
  if [ "$1" = --output ]; then output=$2; shift; fi
  shift
done
printf 'fixture download bytes/s/time\n' >&2
cp "$SETUP_CURL_ARCHIVE" "$output"
SH;
    file_put_contents($shim.'/curl',$curl);chmod($shim.'/curl',0700);
    $env['PATH']=$shim.':/usr/bin:/bin:/usr/sbin:/sbin';
    $env['SETUP_CURL_ARGS']=$base.'/curl-args';$env['SETUP_CURL_ARCHIVE']=$small['path'];
    $r=invoke(['/bin/bash',$setup,'--flavor=small'],array_merge($env,['SETUP_CURL_FAIL'=>'1']));
    check($r['code']===7 && !file_exists($capture) && str_contains($r['text'],'connection failed'),'download failure preserves original exit code and never executes payload');
    $r=invoke(['/bin/bash',$setup,'--flavor=small'], $env);
    check($r['code']===0 && file_exists($capture) && str_contains($r['text'],'fixture download bytes/s/time'),'download fixture shows live progress and enters payload only after hash verification');
    $args=(string)file_get_contents($base.'/curl-args');
    check(str_contains($args,'--no-progress-bar') && str_contains($args,'--no-silent') && str_contains($args,'--progress-meter') && str_contains($args,'=https'),'native downloader enforces HTTPS and readable progress despite curlrc defaults');
    unlink($capture);
    $r=invoke(['/bin/bash',$setup,'--flavor=small'],array_merge($env,['SETUP_CURL_FAIL'=>'1']));
    check($r['code']===0 && file_exists($capture) && str_contains($r['text'],'复用已校验安装包'),'verified cache reuse succeeds without another download');
    unlink($capture);
    // Independent outer defense: a task-only copy binds a deliberately hostile archive hash.
    // Producer rejects such inputs; this additionally checks the consumer before extraction/execution.
    $hostile=$base.'/hostile.tar';$tar=new PharData($hostile);$tar->addFromString('../escape','bad');$tar->compress(Phar::GZ);unset($tar);$hostile.='.gz';
    $text=(string)file_get_contents($setup);
    $text=str_replace($small['data']['packages'][0]['sha256'],hash_file('sha256',$hostile),$text);
    $text=str_replace("small_size='".$small['data']['packages'][0]['size']."'","small_size='".filesize($hostile)."'",$text);
    file_put_contents($base.'/hostile.command',$text);
    $r=invoke(['/bin/bash',$base.'/hostile.command','--flavor=small','--archive='.$hostile],$env);
    check($r['code']===65 && !file_exists($capture) && !file_exists($base.'/escape'),'hash-matching hostile path rejected before extraction or payload execution');
    $linkStage=$base.'/link-stage';mkdir($linkStage,0700);
    file_put_contents($linkStage.'/package.json',json_encode(['schema'=>'webman-aot-builder-installer-package-v1','version'=>'0.2.3','revision'=>'fixture123','platform'=>'macos-arm64','flavor'=>'small']));
    symlink('../outside',$linkStage.'/escape-link');
    $badDir=$base.'/link-package';mkdir($badDir,0700);
    $linkArchive=$badDir.'/'.basename($small['path']);
    check(invoke(['/usr/bin/tar','-czf',$linkArchive,'-C',$linkStage,'package.json','escape-link'])['code']===0,'link archive fixture created');
    $bad=$small['data'];$bad['packages'][0]['path']=$linkArchive;$bad['packages'][0]['size']=filesize($linkArchive);$bad['packages'][0]['sha256']=hash_file('sha256',$linkArchive);
    file_put_contents($badDir.'/result.json',json_encode($bad));
    $r=generate($badDir.'/result.json',$full['result']);
    check($r['code']!==0 && str_contains($r['text'],'link or special'),'producer rejects a symlink archive with matching external hash');
    $text=(string)file_get_contents($setup);
    $text=str_replace($small['data']['packages'][0]['sha256'],hash_file('sha256',$linkArchive),$text);
    $text=str_replace("small_size='".$small['data']['packages'][0]['size']."'","small_size='".filesize($linkArchive)."'",$text);
    file_put_contents($base.'/link.command',$text);
    $r=invoke(['/bin/bash',$base.'/link.command','--flavor=small','--archive='.$linkArchive],$env);
    check($r['code']===65 && !file_exists($capture) && str_contains($r['text'],'链接或特殊'),'consumer rejects hash-matching symlink archive before extraction');
    $duplicate=$base.'/duplicate.tar';
    invoke(['/usr/bin/tar','-cf',$duplicate,'-C',$linkStage,'package.json']);
    invoke(['/usr/bin/tar','-rf',$duplicate,'-C',$linkStage,'package.json']);
    invoke(['/usr/bin/gzip',$duplicate]);$duplicate.='.gz';
    $text=(string)file_get_contents($setup);
    $text=str_replace($small['data']['packages'][0]['sha256'],hash_file('sha256',$duplicate),$text);
    $text=str_replace("small_size='".$small['data']['packages'][0]['size']."'","small_size='".filesize($duplicate)."'",$text);
    file_put_contents($base.'/duplicate.command',$text);
    $r=invoke(['/bin/bash',$base.'/duplicate.command','--flavor=small','--archive='.$duplicate],$env);
    check($r['code']===65 && !file_exists($capture) && str_contains($r['text'],'重复'),'consumer rejects duplicate archive overlay before extraction');
    $windows=[];
    foreach (['small','complete'] as $flavor) {
        $directory=$base.'/windows-'.$flavor;mkdir($directory,0700);
        $archive=$directory.'/webman-aot-builder-0.2.3'.($flavor==='complete'?'-full':'').'-windows-x86_64.zip';
        $zip=new ZipArchive();$zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL);
        $zip->addFromString('package.json',json_encode(['schema'=>'webman-aot-builder-installer-package-v1','version'=>'0.2.3','revision'=>'fixture123','platform'=>'windows-x86_64','flavor'=>$flavor]));$zip->close();
        $data=['schema'=>'webman-aot-builder-installer-package-result-v1','revision'=>'fixture123','packages'=>[['platform'=>'windows-x86_64','path'=>$archive,'size'=>filesize($archive),'sha256'=>hash_file('sha256',$archive)]]];
        file_put_contents($directory.'/result.json',json_encode($data));$windows[$flavor]=$directory.'/result.json';
    }
    $r=invoke([PHP_BINARY,$root.'/tools/package-setup.php','--small-result='.$windows['small'],'--full-result='.$windows['complete'],'--platform=windows-x86_64','--base-url=https://example.invalid/v0.2.3','--output='.$output]);
    check($r['code']===0 && is_file($output.'/webman-aot-builder-0.2.3-windows-x86_64-setup.cmd'),'Windows producer emits a standalone CMD from actual ZIP result fixtures');
    $large=fixture($base . '/large-full','complete','0.2.3','fixture123',16*1048576);
    $r=invoke([PHP_BINARY,'-d','memory_limit=8M',$root.'/tools/package-setup.php','--small-result='.$small['result'],'--full-result='.$large['result'],'--platform=macos-arm64','--base-url=https://example.invalid/v0.2.3','--output='.$output]);
    check($r['code']===0,'archive larger than PHP memory limit is inspected without loading the full archive');
    fwrite(STDOUT,"All {$count} setup behavior checks passed. Raw log: {$base}/results.log\n");
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\nRaw log: {$base}/results.log\n");exit(1);
}
