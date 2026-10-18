<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once DISCUZ_ROOT.'./source/plugin/pintogya/lib/native_task.php';

class task_togya_youtube_import_three_participants {
    var $version = '1.0';
    var $name = 'Reach three participants';
    var $description = 'Reach three participants on the exact activity completed by the prerequisite task.';
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
            return pintogya_native_result(pintogya_native_completion('youtube_import_three_participants', $task));
        } catch(Throwable $error) {
            pintogya_native_log_error('youtube_participants_claim', $error);
            return pintogya_native_incomplete();
        }
    }

    function view($task, $taskvars = array()) { return pintogya_native_render_view('youtube_import_three_participants', $task); }
}
?>
