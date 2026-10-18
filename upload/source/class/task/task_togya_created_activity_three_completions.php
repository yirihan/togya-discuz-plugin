<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once DISCUZ_ROOT.'./source/plugin/pintogya/lib/native_task.php';

class task_togya_created_activity_three_completions {
    var $version = '1.0';
    var $name = 'Reach three activity completions';
    var $description = 'Have at least three participants complete an activity that you created.';
    var $copyright = 'Togya';
    var $icon = 'togya.png';
    var $period = '';
    var $periodtype = 0;
    var $conditions = array();

    function condition() { pintogya_native_require_admin(); }

    function csc($task = array()) {
        global $_G;
        if(!pintogya_native_is_admin() || !pintogya_native_is_draw_post()) { return pintogya_native_incomplete(); }
        try {
            return pintogya_native_result(pintogya_native_completion('created_activity_three_completions', $task));
        } catch(Throwable $error) {
            pintogya_native_log_error('activity_completions_claim', $error);
            return pintogya_native_incomplete();
        }
    }

    function view($task, $taskvars = array()) { return pintogya_native_render_view('created_activity_three_completions', $task); }
}
?>
