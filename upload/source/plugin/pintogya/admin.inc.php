<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

// TOGYA_PLUGIN pintogya_site_profile_common: site differences use native settings, not forked task code.
$pintogyaProfileUrl = (isset($_GET['app']) && $_GET['app'] === 'admin' ? '?app=admin&platform=system&action=plugins' : ADMINSCRIPT.'?action=plugins')
    .'&operation=config&do='.(int)$plugin['pluginid'].'&identifier=pintogya&pmod=admin&view=site';
if(($_GET['view'] ?? '') === 'site') { return require dirname(__FILE__).'/site_profile.inc.php'; }
if(($_GET['view'] ?? '') === 'instantledger') { return require dirname(__FILE__).'/instant_ledger.inc.php'; }

// TOGYA_PLUGIN pintogya_instant_config_native: separate v3 amounts; never import legacy pool defaults.
// TOGYA_PLUGIN pintogya_instant_default_admin: new default, historical GET-only views.
$pintogyaView=$_GET['view'] ?? '';
if($pintogyaView === '' || $pintogyaView === 'instant') {
    foreach(array('activitysubmit','rewardsubmit','reviewsubmit','activitystatussubmit','reviewstatussubmit') as $retired) {
        if(isset($_POST[$retired])) { cpmsg('旧奖池、截止和审核配置入口已停用，请使用两类即时奖励配置。',str_replace('&view=site','&view=instant',$pintogyaProfileUrl),'error'); }
    }
    return require dirname(__FILE__).'/instant_config.inc.php';
}
if(!in_array($pintogyaView,array('ledger','reconcile','orphan'),true) ||
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !empty($_POST)) { exit('Access Denied'); }

$pluginid = (int)$plugin['pluginid'];
$x5 = isset($_GET['app']) && $_GET['app'] === 'admin';
$url = $x5 ? '?app=admin&platform=system&action=plugins' : ADMINSCRIPT.'?action=plugins';
$url .= '&operation=config&do='.$pluginid.'&identifier=pintogya&pmod=admin';
// TOGYA_PLUGIN pintogya_admin_post_before_output: all native saves are dispatched before output.
echo '<p><a href="'.dhtmlspecialchars($pintogyaProfileUrl).'">站点差异设置</a> | <a href="'.$url.'&view=instant">两类奖励与发布设置</a> | <a href="'.$url.'&view=instantledger">两类奖励对账（只读）</a></p>';
echo '<p><a href="'.$url.'&view=reconcile">待定主题对账（只读）</a> | <a href="'.$url.'&view=orphan">隐藏草稿扫描（只读）</a> | <a href="'.$url.'&view=ledger">历史账本（只读）</a></p>';
if(($_GET['view'] ?? '') === 'ledger') {
    // TOGYA_PLUGIN pintogya_admin_include_truthy: DX requires a successful include result.
    // TOGYA_PLUGIN BEGIN pintogya_activity_ledger_readonly
    require_once dirname(__FILE__).'/lib/activity_admin_ledger.php';
    try { echo PintogyaActivityAdminLedger::render($url, $_GET); }
    catch(InvalidArgumentException $error) { echo '<p>筛选条件无效，请返回活动账本重新选择。</p>'; }
    // TOGYA_PLUGIN END pintogya_activity_ledger_readonly
    return true;
}
if(($_GET['view'] ?? '') === 'reconcile') {
    // TOGYA_PLUGIN BEGIN pintogya_activity_reconcile_readonly
    require_once dirname(__FILE__).'/lib/repository.php';
    require_once dirname(__FILE__).'/lib/activity_publish.php';
    require_once dirname(__FILE__).'/lib/activity_reconcile.php';
    echo '<h3>待定主题对账（只读）</h3><p>仅显示过期且已绑定 TID 的映射；可见主题也不代表原生发布和积分步骤全部完成。此页不会重放发布或激活映射。</p>';
    $table = DB::table('pintogya_activity');
    $exists = DB::fetch_first('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array($table));
    if(!$exists) {
        echo '<p>活动映射表尚未安装。</p>';
        return true;
    }
    $afterExpires = max(0, (int)($_GET['after_exp'] ?? 0));
    $afterPk = max(0, (int)($_GET['after_pk'] ?? 0));
    $repo = new PintogyaRepository();
    $rows = $repo->expiredBoundActivities(time(), $afterExpires, $afterPk, 20);
    $classifier = new PintogyaActivityReconcile($repo);
    $labels = array(
        'thread_missing' => '主题不存在', 'thread_mismatch' => '主题身份不符',
        'first_post_mismatch' => '首帖不符', 'hidden_draft' => '隐藏草稿',
        'moderation_pending' => '原生审核中', 'publication_uncertain' => '发布结果待核验',
        'thread_unavailable' => '主题不可用', 'inspection_error' => '读取失败',
    );
    echo '<table class="tb tb2"><tr><th>活动 ID</th><th>TID</th><th>UID</th><th>版块</th><th>到期时间</th><th>对账分类</th></tr>';
    foreach($rows as $row) {
        $status = $classifier->inspect($row);
        echo '<tr><td>'.htmlspecialchars((string)$row['activity_id'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td>'.(int)$row['tid'].'</td><td>'.(int)$row['owner_uid'].'</td><td>'.(int)$row['fid'].'</td>';
        echo '<td>'.date('Y-m-d H:i', (int)$row['pending_expires_at']).'</td><td>'.$labels[$status].'</td></tr>';
    }
    echo '</table>';
    if(!$rows) { echo '<p>当前没有已过期且绑定 TID 的待定映射。</p>'; }
    if(count($rows) === 20) {
        $last = end($rows);
        echo '<p><a href="'.$url.'&view=reconcile&after_exp='.(int)$last['pending_expires_at'].'&after_pk='.(int)$last['activity_pk'].'">下一页</a></p>';
    }
    // TOGYA_PLUGIN END pintogya_activity_reconcile_readonly
    return true;
}

if(($_GET['view'] ?? '') === 'orphan') {
    // TOGYA_PLUGIN BEGIN pintogya_activity_orphan_scan_readonly
    require_once dirname(__FILE__).'/lib/repository.php';
    require_once dirname(__FILE__).'/lib/activity_publish.php';
    require_once dirname(__FILE__).'/lib/activity_reconcile.php';
    echo '<h3>隐藏草稿扫描（只读）</h3><p>按 TID 每次扫描最多 1000 个主题。只有专属摘要完全匹配的 Togya 草稿才会列出；不会自动绑定、发布或发放积分。</p>';
    $table = DB::table('pintogya_activity');
    $exists = DB::fetch_first('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array($table));
    if(!$exists) { echo '<p>活动映射表尚未安装。</p>'; return true; }
    $maxTid = (int)DB::result_first('SELECT MAX(tid) FROM '.DB::table('forum_thread'));
    $afterTid = isset($_GET['after_tid']) ? max(0, min($maxTid, (int)$_GET['after_tid'])) : max(0, $maxTid - 1000);
    $endTid = min($maxTid, $afterTid + 1000);
    $repo = new PintogyaRepository();
    $classifier = new PintogyaActivityReconcile($repo);
    $labels = array('unbound_draft' => '过期未绑定', 'reservation_pending' => '预留仍有效',
        'reservation_missing' => '预留记录缺失', 'mapping_mismatch' => '映射身份或状态不符',
        'inspection_error' => '读取失败');
    echo '<p>扫描 TID '.($afterTid + 1).' 至 '.$endTid.'。<a href="'.$url.'&view=orphan&after_tid=0">从头扫描</a></p>';
    echo '<table class="tb tb2"><tr><th>TID</th><th>活动 ID</th><th>UID</th><th>版块</th><th>对账分类</th></tr>';
    $found = 0;
    $drafts = $endTid > $afterTid ? $repo->hiddenDraftsInTidWindow($afterTid, $endTid - $afterTid) : array();
    foreach($drafts as $thread) {
        $result = $classifier->inspectUnboundDraft($thread, time());
        if($result === null) { continue; }
        $found++;
        $map = $result['map'] ?? array();
        echo '<tr><td>'.(int)$thread['tid'].'</td><td>'.htmlspecialchars((string)($map['activity_id'] ?? ''), ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td>'.(int)$thread['authorid'].'</td><td>'.(int)$thread['fid'].'</td><td>'.$labels[$result['status']].'</td></tr>';
    }
    echo '</table>';
    if($found === 0) { echo '<p>本段未发现可识别的异常草稿。</p>'; }
    if($endTid < $maxTid) { echo '<p><a href="'.$url.'&view=orphan&after_tid='.$endTid.'">扫描下一段</a></p>'; }
    // TOGYA_PLUGIN END pintogya_activity_orphan_scan_readonly
    return true;
}

return true;
