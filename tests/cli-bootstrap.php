<?php
// Synthetic CLI bootstrap regression, not an installation or scheduler proof.
$source=getenv('TOGYA_PLUGIN_SOURCE')?:dirname(__DIR__).'/upload/source/plugin/pintogya';
if(!is_file($source.'/maintenance.php'))exit(2);
$root=sys_get_temp_dir().'/togya-cli-'.bin2hex(random_bytes(8));
mkdir($root.'/source/class',0700,true);mkdir($root.'/source/plugin/pintogya/lib',0700,true);
$files=array('maintenance.php','lib/maintenance.php','lib/installation.php','lib/crypto.php','lib/capabilities.php','lib/maintenance_runner.php','lib/site_profile.php');
foreach($files as$file)copy($source.'/'.$file,$root.'/source/plugin/pintogya/'.$file);
$fake= <<<'CORE'
<?php
define('IN_DISCUZ',true);
define('DISCUZ_ROOT',dirname(__DIR__,2).'/');
foreach(array_keys($GLOBALS)as$key){if(!in_array($key,array('GLOBALS','_SERVER','_GET','_POST','_COOKIE','_FILES','_ENV','_REQUEST','_SESSION'),true)){ $GLOBALS[$key]=null;unset($GLOBALS[$key]); }}
class TogyaQaApp {
 public $init_cron;public $init_session;public $init_user;
 public function init(){global $_G;$_G=array('setting'=>array('siteurl'=>'https://'.getenv('TOGYA_QA_HOST').'/'));}
}
class C {public static function app(){return new TogyaQaApp();}}
class DB {public static function fetch_first($sql,$args=array()){return null;}}
CORE;
file_put_contents($root.'/source/class/class_core.php',$fake);
$cases=array(
 array('name'=>'alpha_cleared_globals','host'=>'forum.example.org','args'=>array('status','--host=forum.example.org'),'ret'=>0,'exit'=>0),
 array('name'=>'beta_cleared_globals','host'=>'community.example.net','args'=>array('status','--host=community.example.net'),'ret'=>0,'exit'=>0),
 array('name'=>'origin_mismatch','host'=>'forum.example.org','args'=>array('status','--host=other.example.net'),'ret'=>1,'exit'=>1),
 array('name'=>'duplicate_host','host'=>'forum.example.org','args'=>array('status','--host=forum.example.org','--host=other.example.net'),'ret'=>null,'exit'=>2),
 array('name'=>'invalid_limit','host'=>'forum.example.org','args'=>array('status','--host=forum.example.org','--limit=21'),'ret'=>null,'exit'=>2),
);
$results=array();
try {
 foreach($cases as$case){
  $command=array_merge(array(PHP_BINARY,$root.'/source/plugin/pintogya/maintenance.php'),$case['args']);
  $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,array('TOGYA_QA_HOST'=>$case['host']));
  if(!is_resource($process))throw new RuntimeException('fixture_process_failed');
  fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  $payload=json_decode($stdout,true);$passed=$exit===$case['exit'];
  if($case['ret']!==null)$passed=$passed&&is_array($payload)&&($payload['ret']??null)===$case['ret'];
  $results[]=array('case'=>$case['name'],'passed'=>$passed);
 }
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
$passed=!in_array(false,array_column($results,'passed'),true);
echo json_encode(array('scope'=>'synthetic bootstrap and negative arguments only','checks'=>$results,'passed'=>$passed)).PHP_EOL;
exit($passed?0:1);
