#!/usr/bin/env php
<?php
declare(strict_types=1);
use WebmanAotBuilder\Compatibility\SaiAdminProfileOverlay;
use WebmanAotBuilder\Cli\ConfigurationException;
$repo=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($repo):void{
    if(str_starts_with($class,'WebmanAotBuilder\\')) require $repo.'/src/'.str_replace('\\','/',substr($class,17)).'.php';
});
function checkProfile(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$source=$argv[1]??'';
checkProfile(is_file($source),'Pass the locked upstream SaiAdminProfile.php');
$policy=json_decode(file_get_contents($repo.'/compatibility/locks/webman-workerman-2026-09-25.json'),true,flags:JSON_THROW_ON_ERROR);
$expected=$policy['generator']['profileSha256'];
$carbonPolicy=$policy['optionalAdaptations']['nesbot/carbon'];
checkProfile(hash_file('sha256',$source)===$expected,'upstream profile SHA differs');
$root=sys_get_temp_dir().'/webman-aot-profile-test-'.bin2hex(random_bytes(6));
mkdir($root.'/plugin/saiadmin',0700,true);
mkdir($root.'/vendor/saithink/saiadmin/src/plugin/saiadmin',0700,true);
$started=microtime(true);
$probe=$root.'/probe.php';
file_put_contents($probe, <<<'PROBE'
<?php
require $argv[1];
try { $result=(new Tinywan\Typephp\Compiler\Profile\SaiAdminProfile($argv[2]))->assertSupported(); echo $result['nesbot/carbon']; }
catch(Throwable $error){fwrite(STDERR,$error->getMessage());exit(70);}
PROBE);
function runProfile(string $profile,string $root,string $probe):array{
    $process=proc_open([PHP_BINARY,$probe,$profile,$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    checkProfile(is_resource($process),'cannot start profile probe');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
foreach(['3.13.2','3.14.0','3.14.1','3.14.2'] as $version){
    file_put_contents($root.'/composer.lock',json_encode(['packages'=>[
        ['name'=>'nesbot/carbon','version'=>$version],['name'=>'saithink/saiadmin','version'=>'v6.0.0'],['name'=>'topthink/think-orm','version'=>'v3.0.34']
    ]]));
    if($version==='3.14.1'){
        [$exit,,$error]=runProfile($source,$root,$probe);
        checkProfile($exit===70&&str_contains($error,'Unsupported nesbot/carbon version 3.14.1'),'original Carbon rejection not reproduced');
        echo "PASS original 3.14.1 rejection reproduced\n";
    }
    $adapted=(new SaiAdminProfileOverlay())->prepare($source,$expected,$root.'/composer.lock',$root.'/cache',$carbonPolicy);
    [$exit,$out,$error]=runProfile($adapted,$root,$probe);
    if($version==='3.14.2') checkProfile($exit===70&&str_contains($error,'Unsupported nesbot/carbon version 3.14.2'),'unknown Carbon version must remain rejected');
    else checkProfile($exit===0&&$out===$version,'known Carbon profile rejected: '.$error);
    echo 'PASS exact Carbon '.$version.($version==='3.14.2'?' remains rejected':' accepted')."\n";
}
file_put_contents($root.'/composer.lock',json_encode(['packages'=>[['name'=>'nesbot/carbon','version'=>'3.14.1']]]));
$drift=$root.'/drift.php';file_put_contents($drift,file_get_contents($source)."\n// drift\n");
try{(new SaiAdminProfileOverlay())->prepare($drift,$expected,$root.'/composer.lock',$root.'/cache',$carbonPolicy);throw new RuntimeException('source drift must fail');}
catch(ConfigurationException $error){checkProfile(str_contains($error->getMessage(),'source drifted'),'wrong drift failure');}
echo 'PASS source drift rejected; original profile unchanged; elapsed '.number_format(microtime(true)-$started,2)."s\n";
checkProfile(hash_file('sha256',$source)===$expected,'original profile changed');
