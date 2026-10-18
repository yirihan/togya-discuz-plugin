<?php
define('IN_DISCUZ',true);define('PINTOGYA_STATIC_TEST',true);
define('DISCUZ_ROOT',dirname(__DIR__).'/upload/');
function dhtmlspecialchars($value) { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
$keys=array('bind_daily','youtube_connected','youtube_import_public','youtube_import_three_participants',
    'answer_other_question','created_activity_three_completions');
$lang=include DISCUZ_ROOT.'source/plugin/pintogya/lang/en-US.php';$cases=array();
try {
    if(!is_file(DISCUZ_ROOT.'static/image/task/togya.png'))throw new RuntimeException('task_icon_missing');
    foreach($keys as$key) {
        $name='task_togya_'.$key;require DISCUZ_ROOT.'source/class/task/'.$name.'.php';$task=new $name();
        if($task->icon!=='togya.png'||!is_callable(array($task,'csc'))||!is_callable(array($task,'view')))throw new RuntimeException('task_contract_missing');
        if($key==='bind_daily'&&($task->period!==1||$task->periodtype!==1))throw new RuntimeException('daily_period_changed');
        if($key!=='bind_daily'&&($task->period!==''||$task->periodtype!==0||!is_callable(array($task,'condition'))))throw new RuntimeException('native_task_period_changed');
        $html=pintogya_native_render_guide($key,$lang);
        preg_match_all('~<img src="([^"?]+)(?:\?[^"]*)?"~',$html,$matches);
        if(!$matches[1])throw new RuntimeException('task_guide_missing');
        foreach($matches[1] as$path)if(!is_file(DISCUZ_ROOT.$path))throw new RuntimeException('guide_asset_missing');
        $cases[]=array('task'=>$key,'guide_images'=>count($matches[1]),'passed'=>true);
    }
    echo json_encode(array('passed'=>true,'cases'=>$cases,'scope'=>'Six task-class contracts and actual guide asset paths; no native AdminCP registration, task completion, permissions or payout execution proof.')).PHP_EOL;
}catch(Throwable $error){echo json_encode(array('passed'=>false,'cases'=>$cases,'symbol'=>$error->getMessage())).PHP_EOL;exit(1);}
