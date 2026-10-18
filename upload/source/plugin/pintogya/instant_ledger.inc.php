<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }
require_once __DIR__.'/lib/activity_instant_admin.php';

// TOGYA_PLUGIN pintogya_instant_admin_readonly: GET only, no legacy ledger or reward command.
$base=(isset($_GET['app']) && $_GET['app']==='admin' ? '?app=admin&platform=system&action=plugins' : ADMINSCRIPT.'?action=plugins')
    .'&operation=config&do='.(int)$plugin['pluginid'].'&identifier=pintogya&pmod=admin';
$ledgerUrl=$base.'&view=instantledger';
echo '<p><a href="'.dhtmlspecialchars($base.'&view=instant').'">两类金额与官方示例配置</a></p>';
try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { throw new RuntimeException('instant_admin_readonly_required'); }
    $filters=PintogyaActivityInstantAdmin::filters($_GET);
    echo '<form method="get" action="'.dhtmlspecialchars(isset($_GET['app']) && $_GET['app']==='admin' ? '?' : ADMINSCRIPT).'">';
    $route=array('action'=>'plugins','operation'=>'config','do'=>(string)$plugin['pluginid'],
        'identifier'=>'pintogya','pmod'=>'admin','view'=>'instantledger');
    if(isset($_GET['app']) && $_GET['app']==='admin') { $route=array('app'=>'admin','platform'=>'system')+$route; }
    foreach($route as $key=>$value) { echo '<input type="hidden" name="'.$key.'" value="'.dhtmlspecialchars($value).'" />'; }
    showtableheader('筛选即时奖励与同步状态');
    showsetting('活动 ID','activity_id',$filters['activity_id'],'text');
    showsetting('本站会员 UID','uid',$filters['uid'] ?: '','text');
    foreach(array('reward_state'=>array('奖励状态',PintogyaActivityInstantAdmin::REWARD_STATES),
        'sync_state'=>array('同步状态',PintogyaActivityInstantAdmin::SYNC_STATES)) as $key=>$spec) {
        $options=array(array('','全部'));
        foreach($spec[1] as $code=>$label) { $options[]=array($code,$label); }
        showsetting($spec[0],array($key,$options),$filters[$key],'select');
    }
    showsubmit('instantfilter','筛选'); showtablefooter(); echo '</form>';
    echo PintogyaActivityInstantAdmin::render($ledgerUrl,$_GET);
} catch(Throwable $error) {
    echo '<p role="status">无法显示：请检查筛选条件或本地只读表结构；此页没有修改数据、重试或发奖。</p>';
}
return true;
