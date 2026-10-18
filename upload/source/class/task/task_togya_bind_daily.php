<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once DISCUZ_ROOT.'./source/plugin/pintogya/lib/native_task.php';

class task_togya_bind_daily {
    var $version = '1.0';
    var $name = 'Connect Togya for this task period';
    var $description = 'Scan a fresh QR code, review the forum request in Togya, and approve it for this task period.';
    var $copyright = 'Forum / Togya';
    var $icon = 'togya.png';
    var $period = 1;
    var $periodtype = 1;
    var $conditions = array();

    function preprocess($task) {
        global $_G;
        try {
            $taskId = pintogya_native_task_id($task, 'togya_bind_daily');
            $instance = pintogya_native_current_instance($_G['uid'], $taskId, 0);
            pintogya_native_services()['binding']->startChallenge($_G['uid'], $taskId, $instance, pintogya_session_digest(), pintogya_api_locale());
        } catch(Throwable $error) {
            pintogya_native_log_error('task_preprocess', $error);
        }
    }

    function csc($task = array()) {
        global $_G;
        if(!pintogya_native_is_draw_post()) { return pintogya_native_incomplete(); }
        try {
            pintogya_assert_post();
            return pintogya_native_result(pintogya_native_completion('bind_daily', $task));
        } catch(Throwable $ignored) { return pintogya_native_incomplete(); }
    }

    function view($task, $taskvars = array()) { return pintogya_native_render_view('bind_daily', $task); }
}
?>
