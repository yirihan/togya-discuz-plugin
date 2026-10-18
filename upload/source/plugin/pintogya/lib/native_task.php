<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once dirname(__FILE__).'/pintogya.php';

function pintogya_native_incomplete() {
    return array('csc' => 0, 'remaintime' => 0);
}

function pintogya_native_result($complete) {
    return $complete === true ? true : pintogya_native_incomplete();
}

function pintogya_native_services() {
    if(defined('PINTOGYA_STATIC_TEST') && isset($GLOBALS['PINTOGYA_NATIVE_TEST_SERVICES'])) {
        return $GLOBALS['PINTOGYA_NATIVE_TEST_SERVICES'];
    }
    return pintogya_services();
}

function pintogya_native_log_error($stage, $error) {
    $stage = preg_replace('/[^a-z0-9_:-]/i', '', (string)$stage);
    $code = $error instanceof Throwable ? $error->getMessage() : 'unknown_error';
    $code = preg_replace('/[^a-z0-9_:-]/i', '', (string)$code);
    error_log('Togya Plugin '.$stage.' failed: '.($code !== '' ? $code : 'unknown_error'));
}

function pintogya_native_is_draw_post() {
    return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '') === 'POST'
        && isset($_POST['drawsubmit']);
}

function pintogya_native_is_admin() {
    global $_G;
    return isset($_G['uid'], $_G['adminid']) && intval($_G['uid']) > 0 && intval($_G['adminid']) > 0;
}

function pintogya_native_require_admin() {
    if(!pintogya_native_is_admin()) { throw new RuntimeException('native_task_admin_required'); }
}

function pintogya_native_current_row($uid, $taskId, $status) {
    $uid = intval($uid);
    $taskId = intval($taskId);
    $status = intval($status);
    if($uid < 1 || $taskId < 1 || !in_array($status, array(0, 1), true)) { throw new InvalidArgumentException('native_task_row_input'); }
    if(defined('PINTOGYA_STATIC_TEST') && isset($GLOBALS['PINTOGYA_NATIVE_TEST_CURRENT_ROW']) && is_callable($GLOBALS['PINTOGYA_NATIVE_TEST_CURRENT_ROW'])) {
        $row = call_user_func($GLOBALS['PINTOGYA_NATIVE_TEST_CURRENT_ROW'], $uid, $taskId, $status);
        if(!is_array($row)) { throw new RuntimeException('native_task_row_not_unique'); }
        return $row;
    }
    $rows = DB::fetch_all('SELECT uid,taskid,status,dateline,csc FROM '.DB::table('common_mytask').' WHERE uid='.$uid.' AND taskid='.$taskId.' AND status='.$status.' LIMIT 2');
    if(!is_array($rows) || count($rows) !== 1) { throw new RuntimeException('native_task_row_not_unique'); }
    return reset($rows);
}

function pintogya_native_current_instance($uid, $taskId, $status = 0) {
    $row = pintogya_native_current_row($uid, $taskId, $status);
    $instance = (string)(isset($row['dateline']) ? $row['dateline'] : '');
    if(!preg_match('/^[1-9][0-9]{0,10}$/D', $instance)) { throw new RuntimeException('native_task_instance_invalid'); }
    return $instance;
}

function pintogya_native_apply_task_id($expectedScript) {
    $taskId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if($taskId < 1 || !pintogya_native_task_matches($taskId, $expectedScript)) { throw new RuntimeException('native_task_identity_mismatch'); }
    return $taskId;
}

function pintogya_native_task_meta($taskId) {
    if(defined('PINTOGYA_STATIC_TEST') && isset($GLOBALS['PINTOGYA_NATIVE_TEST_TASK_META']) && is_callable($GLOBALS['PINTOGYA_NATIVE_TEST_TASK_META'])) {
        $row = call_user_func($GLOBALS['PINTOGYA_NATIVE_TEST_TASK_META'], intval($taskId));
    } else {
        $rows = DB::fetch_all('SELECT taskid,scriptname,relatedtaskid FROM '.DB::table('common_task').' WHERE taskid='.intval($taskId).' LIMIT 2');
        $row = is_array($rows) && count($rows) === 1 ? reset($rows) : null;
    }
    if(!is_array($row) || intval($row['taskid']) !== intval($taskId) || !isset($row['scriptname'], $row['relatedtaskid'])) { throw new RuntimeException('native_task_not_unique'); }
    return $row;
}

function pintogya_native_related_instance($uid, $task) {
    $taskId = pintogya_native_task_id($task, 'togya_youtube_import_three_participants');
    $meta = pintogya_native_task_meta($taskId);
    $relatedTaskId = intval($meta['relatedtaskid']);
    if($relatedTaskId < 1 || !pintogya_native_task_matches($relatedTaskId, 'togya_youtube_import_public')) { throw new RuntimeException('related_task_invalid'); }
    return array($relatedTaskId, pintogya_native_current_instance($uid, $relatedTaskId, 1));
}

function pintogya_native_task_matches($taskId, $expectedScript) {
    try { $row = pintogya_native_task_meta($taskId); }
    catch(Throwable $ignored) { return false; }
    return hash_equals((string)$expectedScript, (string)$row['scriptname']);
}

function pintogya_native_task_id($task, $expectedScript) {
    $taskId = is_array($task) && isset($task['taskid']) ? intval($task['taskid']) : 0;
    if($taskId < 1 || !isset($task['scriptname']) || !hash_equals($expectedScript, (string)$task['scriptname']) || !pintogya_native_task_matches($taskId, $expectedScript)) {
        throw new RuntimeException('native_task_identity_mismatch');
    }
    return $taskId;
}

function pintogya_native_completion($taskKey, $task) {
    global $_G;
    $uid = isset($_G['uid']) ? intval($_G['uid']) : 0;
    if($uid < 1) { throw new RuntimeException('native_task_login_required'); }
    if($taskKey !== 'bind_daily') { pintogya_native_require_admin(); }

    $taskId = pintogya_native_task_id($task, 'togya_'.$taskKey);
    $services = pintogya_native_services();
    $locale = pintogya_api_locale();
    if($taskKey === 'bind_daily') {
        $instance = pintogya_native_current_instance($uid, $taskId, 0);
        return $services['binding']->pollLatest($uid, $taskId, $instance, pintogya_session_digest()) === true;
    }
    if($taskKey === 'youtube_connected') {
        return $services['facts']->youtubeConnected($uid) === true;
    }
    if($taskKey === 'youtube_import_public') {
        $instance = pintogya_native_current_instance($uid, $taskId, 0);
        return $services['facts']->youtubeImportPublic($uid, $taskId, $instance, $locale) === true;
    }
    if($taskKey === 'youtube_import_three_participants') {
        list($importTaskId, $importTaskInstance) = pintogya_native_related_instance($uid, $task);
        return $services['facts']->youtubeImportThreeParticipants($uid, $importTaskId, $importTaskInstance, $locale) === true;
    }
    if($taskKey === 'answer_other_question') {
        return $services['facts']->answeredOtherQuestion($uid, $locale) === true;
    }
    if($taskKey === 'created_activity_three_completions') {
        return $services['facts']->createdActivityThreeCompletions($uid, $locale) === true;
    }
    throw new RuntimeException('native_task_key_invalid');
}

function pintogya_native_row_progress($row) {
    $parts = explode("\t", isset($row['csc']) ? (string)$row['csc'] : '0', 2);
    return isset($parts[0]) && floatval($parts[0]) >= 100 ? 100 : 0;
}

function pintogya_native_write_progress($uid, $taskId, $progress) {
    $uid = intval($uid);
    $taskId = intval($taskId);
    $progress = intval($progress) >= 100 ? 100 : 0;
    if(defined('PINTOGYA_STATIC_TEST') && isset($GLOBALS['PINTOGYA_NATIVE_TEST_PROGRESS_WRITER']) && is_callable($GLOBALS['PINTOGYA_NATIVE_TEST_PROGRESS_WRITER'])) {
        call_user_func($GLOBALS['PINTOGYA_NATIVE_TEST_PROGRESS_WRITER'], $uid, $taskId, $progress);
        return;
    }
    DB::query('UPDATE '.DB::table('common_mytask')." SET csc='".$progress."\t".intval(TIMESTAMP)."' WHERE uid=".$uid.' AND taskid='.$taskId." AND status='0'");
}

function pintogya_native_sync_progress($taskKey, $task) {
    global $_G;
    $uid = isset($_G['uid']) ? intval($_G['uid']) : 0;
    $taskId = pintogya_native_task_id($task, 'togya_'.$taskKey);
    $row = pintogya_native_current_row($uid, $taskId, 0);
    $stored = pintogya_native_row_progress($row);
    try {
        $progress = pintogya_native_completion($taskKey, $task) ? 100 : 0;
        if($progress !== $stored) { pintogya_native_write_progress($uid, $taskId, $progress); }
        return $progress;
    } catch(Throwable $error) {
        pintogya_native_log_error('task_progress_'.$taskKey, $error);
        return $stored;
    }
}

function pintogya_native_progress_request() {
    return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '') === 'POST'
        && isset($_POST['pintogya_action'])
        && hash_equals('progress', (string)$_POST['pintogya_action']);
}

function pintogya_native_maybe_send_progress($taskKey, $task) {
    if(!pintogya_native_progress_request()) { return false; }
    pintogya_assert_post();
    $taskId = pintogya_native_task_id($task, 'togya_'.$taskKey);
    $payload = array('ok' => true, 'taskId' => $taskId, 'csc' => pintogya_native_sync_progress($taskKey, $task));
    if(defined('PINTOGYA_STATIC_TEST')) {
        $GLOBALS['PINTOGYA_NATIVE_TEST_PROGRESS_RESPONSE'] = $payload;
        return true;
    }
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pintogya_native_progress_form($taskId, $taskKey, $lang, $interval = 15000, $expiresAt = 0) {
    $interval = max(2000, min(60000, intval($interval)));
    return '<form method="post" action="home.php?mod=task&amp;do=view&amp;id='.intval($taskId).'" data-pintogya-progress-sync="1" data-pintogya-task-id="'.intval($taskId).'" data-pintogya-interval="'.$interval.'" data-pintogya-expires-at="'.intval($expiresAt).'" data-pintogya-ready="'.dhtmlspecialchars($lang['approval_ready']).'" data-pintogya-waiting="'.dhtmlspecialchars($lang['task_auto_waiting']).'" data-pintogya-retrying="'.dhtmlspecialchars($lang['task_auto_retrying']).'" data-pintogya-expired="'.dhtmlspecialchars($lang['task_auto_expired']).'" style="display:none">'
        .'<input type="hidden" name="formhash" value="'.dhtmlspecialchars(formhash()).'" />'
        .'<input type="hidden" name="pintogya_action" value="progress" /></form>';
}

function pintogya_native_render_guide($taskKey, $lang) {
    if($taskKey !== 'bind_daily') { return ''; }
    return '<section class="pintogya-task-guide"><h4>'.dhtmlspecialchars($lang['task_guide_label']).'</h4>'
        .'<ol><li>'.dhtmlspecialchars($lang['task_guide_step_me']).'</li>'
        .'<li>'.dhtmlspecialchars($lang['task_guide_step_scan']).'</li></ol>'
        .'<p>'.dhtmlspecialchars($lang['task_guide_same_phone']).'</p></section>';
}

function pintogya_native_render_platform_links($lang) {
    $links = pintogya_public_links();
    $assetBase = 'source/plugin/pintogya/static/images/platforms/';
    $version = '1.0.0-alpha.1-x5';
    $html = '<div class="pintogya-platform-links" role="group" aria-label="'.dhtmlspecialchars($lang['get_togya_label']).'">';
    $html .= '<a class="pintogya-platform-link pintogya-platform-apple" href="'.dhtmlspecialchars($links['apple']).'" aria-label="'.dhtmlspecialchars($lang['get_togya_apple']).'" title="'.dhtmlspecialchars($lang['get_togya_apple']).'">'
        .'<img src="'.dhtmlspecialchars($assetBase.'app-store-badge.svg?'.$version).'" alt="'.dhtmlspecialchars($lang['get_togya_apple']).'" width="120" height="40" />'
        .'</a>';
    $html .= '<a class="pintogya-platform-link pintogya-platform-google" href="'.dhtmlspecialchars($links['android']).'" target="_blank" rel="noopener noreferrer" aria-label="'.dhtmlspecialchars($lang['get_togya_android']).'" title="'.dhtmlspecialchars($lang['get_togya_android']).'">'
        .'<img src="'.dhtmlspecialchars($assetBase.'google-play-badge.png?'.$version).'" alt="'.dhtmlspecialchars($lang['get_togya_android']).'" width="646" height="250" />'
        .'</a></div>';
    return $html;
}

function pintogya_native_render_view($taskKey, $task) {
    global $_G;
    if($taskKey !== 'bind_daily' && !pintogya_native_is_admin()) { return ''; }
    $lang = pintogya_task_lang_pack();
    try { $taskId = pintogya_native_task_id($task, 'togya_'.$taskKey); }
    catch(Throwable $ignored) { return ''; }
    if(pintogya_native_maybe_send_progress($taskKey, $task)) { return ''; }
    $version = '1.0.0-alpha.1-x5';
    $titleKey = 'task_title_'.$taskKey;
    $title = isset($lang[$titleKey]) ? $lang[$titleKey] : 'Togya';
    $html = '<link rel="stylesheet" href="source/plugin/pintogya/static/pintogya.css?'.$version.'" />'
        .'<script defer src="source/plugin/pintogya/static/pintogya.js?'.$version.'"></script>'
        .'<div class="pintogya-task-view"><h3>'.dhtmlspecialchars($title).'</h3>';
    $html .= pintogya_native_render_guide($taskKey, $lang);
    if($taskKey === 'bind_daily') {
        $html .= '<p class="pintogya-entry-note"><strong>'.dhtmlspecialchars($lang['task_entry_label']).'</strong> '.dhtmlspecialchars($lang['task_entry_note']).'</p>';
        $html .= pintogya_native_render_binding($taskId, $lang);
    } else {
        $ruleKey = 'task_'.$taskKey;
        $stepsKey = 'task_steps_'.$taskKey;
        $html .= '<p><strong>'.dhtmlspecialchars($lang['task_steps_label']).'</strong> '.dhtmlspecialchars(isset($lang[$stepsKey]) ? $lang[$stepsKey] : '').'</p>';
        $html .= '<p><strong>'.dhtmlspecialchars($lang['task_rule_label']).'</strong> '.dhtmlspecialchars(isset($lang[$ruleKey]) ? $lang[$ruleKey] : '').'</p>';
        $html .= pintogya_native_render_platform_links($lang);
        try {
            pintogya_native_current_instance($_G['uid'], $taskId, 0);
            $html .= pintogya_native_progress_form($taskId, $taskKey, $lang);
        } catch(Throwable $ignored) {}
    }
    $html .= '<p class="xg1"><strong>'.dhtmlspecialchars($lang['task_privacy_label']).'</strong> '.dhtmlspecialchars($lang['privacy_notice_activity_cache'] ?? $lang['privacy_notice']).'</p></div>';
    return $html;
}

function pintogya_native_render_binding($taskId, $lang) {
    global $_G;
    $uid = isset($_G['uid']) ? intval($_G['uid']) : 0;
    $instance = '';
    try { $instance = pintogya_native_current_instance($uid, $taskId, 0); } catch(Throwable $ignored) {}
    $challenge = null;
    $binding = null;
    $error = false;
    $errorCode = '';
    $retryAfter = 0;
    if($uid > 0 && $instance !== '') {
        try {
            $services = pintogya_native_services();
            if(strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '') === 'POST'
                && isset($_POST['pintogya_action']) && $_POST['pintogya_action'] === 'refresh') {
                pintogya_assert_post();
                $services['binding']->startChallenge($uid, $taskId, $instance, pintogya_session_digest(), pintogya_api_locale());
            }
            $challenge = $services['binding']->latestChallenge($uid, $taskId, $instance, pintogya_session_digest());
            $binding = $services['binding']->activeBinding($uid);
        } catch(Throwable $ignored) {
            pintogya_native_log_error('task_binding', $ignored);
            $error = true;
            $errorCode = (string)$ignored->getMessage();
            if(preg_match('/^api_http_429_([0-9]{1,3})$/D', $errorCode, $retryMatch)) {
                $retryAfter = max(1, min(900, intval($retryMatch[1])));
            }
        }
    }

    $html = '<p><strong>'.dhtmlspecialchars($lang['task_steps_label']).'</strong> '.dhtmlspecialchars($lang['task_steps_bind_daily']).'</p>';
    $html .= '<p><strong>'.dhtmlspecialchars($lang['task_rule_label']).'</strong> '.dhtmlspecialchars($lang['task_bind_daily']).'</p>';
    $html .= pintogya_native_render_platform_links($lang);
    if(pintogya_site_profile()['show_account_link'] === '1') {
        $accountLabel = $lang['account_manage_link'] ?? 'Account management';
        $html .= '<p><a href="plugin.php?id=pintogya:account">'.dhtmlspecialchars($accountLabel).'</a></p>';
    }
    if($instance === '') {
        $html .= '<p class="xg1">'.dhtmlspecialchars($lang['apply_first']).'</p>';
        return $html;
    }
    if($error) {
        $message = $retryAfter > 0 ? str_replace('{seconds}', (string)$retryAfter, $lang['rate_limited']) : $lang['unavailable'];
        $html .= '<p class="xi1">'.dhtmlspecialchars($message).'</p>';
    }
    if($binding) { $html .= '<p><strong>'.dhtmlspecialchars($lang['state_label']).':</strong> '.dhtmlspecialchars($lang['state_connected']).'</p>'; }
    if($challenge && $challenge['state'] === 'pending') {
        $qr = pintogya_qr_data_uri($challenge['qrUrl']);
        $html .= '<p><strong>'.dhtmlspecialchars($lang['scan_prompt']).'</strong></p>';
        if($qr !== '') { $html .= '<p><img src="'.dhtmlspecialchars($qr).'" alt="'.dhtmlspecialchars($lang['qr_alt']).'" width="220" height="220" /></p>'; }
        $html .= '<p class="xg1">'.dhtmlspecialchars($lang['expires_label']).': '.dhtmlspecialchars(dgmdate($challenge['expiresAt'])).'</p>';
        $html .= pintogya_native_progress_form($taskId, 'bind_daily', $lang, 2500, $challenge['expiresAt']);
        $html .= '<p class="xg1 pintogya-auto-status" data-pintogya-auto-status="1" aria-live="polite">'.dhtmlspecialchars($lang['task_auto_waiting']).'</p>';
    } elseif($challenge && $challenge['state'] === 'approved') {
        $html .= '<p><strong>'.dhtmlspecialchars($lang['approval_ready']).'</strong></p>';
        $html .= pintogya_native_progress_form($taskId, 'bind_daily', $lang, 2500, max(TIMESTAMP + 60, intval($challenge['expiresAt'])));
        $html .= '<p class="xg1 pintogya-auto-status" data-pintogya-auto-status="1" aria-live="polite">'.dhtmlspecialchars($lang['task_auto_waiting']).'</p>';
    }
    if(!$challenge || !in_array($challenge['state'], array('pending', 'approved'), true)) {
        $html .= pintogya_native_form(
            'home.php?mod=task&do=view&id='.$taskId,
            'refresh',
            'pintogya_submit',
            $lang['new_qr'],
            false,
            $retryAfter,
            !$error
        );
    }
    return $html;
}

function pintogya_native_form($action, $taskAction, $submitName, $label, $primary, $cooldown = 0, $autoStart = false) {
    $cooldown = max(0, min(900, intval($cooldown)));
    return '<form method="post" action="'.dhtmlspecialchars($action).'" data-pintogya-submit-guard="1"'.($cooldown ? ' data-pintogya-cooldown="'.$cooldown.'"' : '').($autoStart ? ' data-pintogya-autostart="1"' : '').' style="display:inline-block;margin:6px 8px 6px 0">'
        .'<input type="hidden" name="formhash" value="'.dhtmlspecialchars(formhash()).'" />'
        .'<input type="hidden" name="pintogya_action" value="'.dhtmlspecialchars($taskAction).'" />'
        .'<input type="hidden" name="'.dhtmlspecialchars($submitName).'" value="1" />'
        .'<button type="submit" class="pn'.($primary ? ' pnc' : '').'"'.($cooldown ? ' disabled="disabled"' : '').'><strong>'.dhtmlspecialchars($label).'</strong></button></form>';
}
