<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once DISCUZ_ROOT.'./source/plugin/pintogya/lib/native_task.php';

class task_togya_youtube_import_public {
    var $version = '1.0';
    var $name = 'Import a public YouTube activity';
    var $description = 'Create a new public Togya activity from YouTube after applying.';
    var $copyright = 'Togya';
    var $icon = 'togya.png';
    var $period = '';
    var $periodtype = 0;
    var $conditions = array();

    function condition() {
        global $_G;
        try {
            pintogya_native_require_admin();
            $taskId = pintogya_native_apply_task_id('togya_youtube_import_public');
            pintogya_native_services()['facts']->prepareYoutubeImport($_G['uid'], $taskId, (string)TIMESTAMP, pintogya_api_locale());
        } catch(Throwable $error) {
            pintogya_native_log_error('youtube_import_prepare', $error);
            $lang = pintogya_task_lang_pack();
            showmessage($lang['unavailable']);
        }
    }

    function preprocess($task) {
        global $_G;
        pintogya_native_require_admin();
        $taskId = pintogya_native_task_id($task, 'togya_youtube_import_public');
        $instance = pintogya_native_current_instance($_G['uid'], $taskId, 0);
        pintogya_native_services()['contexts']->activate($_G['uid'], $taskId, $instance);
    }

    function csc($task = array()) {
        global $_G;
        if(!pintogya_native_is_admin() || !pintogya_native_is_draw_post()) { return pintogya_native_incomplete(); }
        try {
            return pintogya_native_result(pintogya_native_completion('youtube_import_public', $task));
        } catch(Throwable $error) {
            pintogya_native_log_error('youtube_import_claim', $error);
            return pintogya_native_incomplete();
        }
    }

    function sufprocess($task) {
        global $_G;
        pintogya_native_require_admin();
        $taskId = pintogya_native_task_id($task, 'togya_youtube_import_public');
        $oldInstance = (string)$task['dateline'];
        $completedInstance = pintogya_native_current_instance($_G['uid'], $taskId, 1);
        pintogya_native_services()['contexts']->completeAndRekey($_G['uid'], $taskId, $oldInstance, $completedInstance);
    }

    function view($task, $taskvars = array()) { return pintogya_native_render_view('youtube_import_public', $task); }
}
?>
