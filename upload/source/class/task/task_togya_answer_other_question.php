<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once DISCUZ_ROOT.'./source/plugin/pintogya/lib/native_task.php';

class task_togya_answer_other_question {
    var $version = '1.0';
    var $name = 'Answer another member question';
    var $description = 'Answer a question asked by another Togya account.';
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
            return pintogya_native_result(pintogya_native_completion('answer_other_question', $task));
        } catch(Throwable $error) {
            pintogya_native_log_error('answer_other_question_claim', $error);
            return pintogya_native_incomplete();
        }
    }

    function view($task, $taskvars = array()) { return pintogya_native_render_view('answer_other_question', $task); }
}
?>
