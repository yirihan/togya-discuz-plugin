<?php
define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE');
if(!$source||!is_file($source.'/lib/maintenance_runner.php'))exit(2);
require $source.'/lib/maintenance_runner.php';
$cases=array();
function supervisorCase($name,$callback){global $cases;try{$callback();$cases[]=array('case'=>$name,'passed'=>true);}catch(Throwable $error){$cases[]=array('case'=>$name,'passed'=>false,'class'=>get_class($error));}}
function supervisorCheck($value){if(!$value)throw new RuntimeException('supervisor_check_failed');}
function supervisorRun($code,$seconds=2){return PintogyaMaintenanceRunner::run('pintogya.display_cache',1,$seconds,array(PHP_BINARY,'-r',$code));}

supervisorCase('valid_counter_response',static function(){
    $result=supervisorRun('echo json_encode(["ret"=>0,"result"=>["state"=>"observed","scanned"=>2,"provider_body"=>"synthetic"]]);');
    supervisorCheck($result===array('state'=>'observed','scanned'=>2));
});
supervisorCase('invalid_json_fails_closed',static function(){
    $result=supervisorRun('echo "not-json";');
    supervisorCheck(($result['state']??null)==='outcome_unknown'&&($result['errors'][0]['code']??null)==='pintogya_worker_response_invalid');
});
supervisorCase('failed_child_not_success',static function(){
    $result=supervisorRun('exit(7);');
    supervisorCheck(($result['state']??null)==='outcome_unknown'&&($result['errors'][0]['code']??null)==='pintogya_worker_exit_unknown');
});
supervisorCase('bounded_output',static function(){
    $result=supervisorRun('echo str_repeat("x",70000);');
    supervisorCheck(($result['state']??null)==='outcome_unknown'&&in_array($result['errors'][0]['code']??null,array('pintogya_worker_output_limit','pintogya_worker_exit_unknown'),true));
});
supervisorCase('wall_clock_timeout',static function(){
    $start=hrtime(true)/1000000000;
    $result=supervisorRun('usleep(3000000);echo json_encode(["ret"=>0,"result"=>[]]);',1);
    $elapsed=hrtime(true)/1000000000-$start;
    supervisorCheck($elapsed<2.0&&($result['errors'][0]['code']??null)==='pintogya_worker_time_limit');
});
supervisorCase('unknown_task_rejected',static function(){
    try{PintogyaMaintenanceRunner::run('unknown.task',1,1,array(PHP_BINARY,'-r','exit;'));}
    catch(InvalidArgumentException $expected){return;}
    throw new RuntimeException('unknown_task_not_rejected');
});
$passed=!in_array(false,array_column($cases,'passed'),true);
echo json_encode(array('scope'=>'isolated supervisor child-process checks; no DX install, SQL lock, cron or live source proof','cases'=>$cases,'passed'=>$passed)).PHP_EOL;
exit($passed?0:1);
