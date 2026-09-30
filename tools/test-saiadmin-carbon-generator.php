#!/usr/bin/env php
<?php
declare(strict_types=1);
use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay;
$repo=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($repo):void{if(str_starts_with($class,'WebmanAotBuilder\\'))require $repo.'/src/'.str_replace('\\','/',substr($class,17)).'.php';});
function checkCarbonGenerator(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$generatorRoot=$argv[1]??'';$project=$argv[2]??'';
checkCarbonGenerator(is_file($generatorRoot.'/src/Compiler/ProjectGenerator.php')&&is_file($project.'/composer.lock'),'Pass locked generator root and installed Carbon project');
$lock=json_decode(file_get_contents($repo.'/compatibility/locks/webman-workerman-2026-09-25.json'),true,flags:JSON_THROW_ON_ERROR);
$policy=$lock['optionalAdaptations']['nesbot/carbon'];$rule=$policy['versions']['3.14.1'];
$root=sys_get_temp_dir().'/webman-aot-carbon-generator-test-'.bin2hex(random_bytes(6));
mkdir($root.'/mirror/'.dirname($rule['sourcePath']),0700,true);
copy($project.'/'.$rule['sourcePath'],$root.'/mirror/'.$rule['sourcePath']);
$source=$generatorRoot.'/src/Compiler/ProjectGenerator.php';$sourceSha=$lock['generator']['sourceSha256'];$stubSha=$lock['generator']['mainStubSha256'];
$apply=static fn()=>(new SaiAdminGeneratorOverlay())->prepare($source,$sourceSha,$stubSha,$root.'/cache',$root.'/mirror',$policy);
$writeLock=static function(string $version,string $reference)use($root):void{file_put_contents($root.'/mirror/composer.lock',json_encode(['packages'=>[['name'=>'nesbot/carbon','version'=>$version,'source'=>['reference'=>$reference]]]]));};
$started=microtime(true);
$writeLock('3.14.1',$rule['reference']);$result=$apply();
require $result['path'];
$reflection=new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class);
$replacements=$reflection->getConstant('SWITCH_TERMINAL_REPLACEMENTS');
checkCarbonGenerator(($replacements[$rule['sourcePath']]['CarbonInterval::year()']??null)==="CarbonInterval::__callStatic('year', [])",'new year rule must preserve macro dispatch');
echo "PASS exact 3.14.1 rule registered with explicit macro dispatch\n";
$writeLock('3.14.1',str_repeat('0',40));
try{$apply();throw new RuntimeException('reference drift must fail');}catch(ConfigurationException $error){checkCarbonGenerator(str_contains($error->getMessage(),'reference drifted'),'wrong reference failure');}
echo "PASS Carbon reference drift rejected\n";
$writeLock('3.14.1',$rule['reference']);file_put_contents($root.'/mirror/'.$rule['sourcePath'],"\n// drift",FILE_APPEND);
try{$apply();throw new RuntimeException('source drift must fail');}catch(ConfigurationException $error){checkCarbonGenerator(str_contains($error->getMessage(),'source drifted'),'wrong source failure');}
echo "PASS Carbon source drift rejected\n";
foreach(['3.13.2','3.14.0'] as $version){$writeLock($version,'unrelated');$old=$apply();checkCarbonGenerator($old['sha256']!==$result['sha256'],'older versions must preserve previous generator');echo 'PASS '.$version." generator unchanged\n";}
checkCarbonGenerator(hash_file('sha256',$source)===$sourceSha,'original generator changed');
echo 'PASS locked generator unchanged; elapsed '.number_format(microtime(true)-$started,2)."s\n";
