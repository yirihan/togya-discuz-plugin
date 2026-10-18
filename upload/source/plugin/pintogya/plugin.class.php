<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
class plugin_pintogya {
    public function global_header() {
        return '<link rel="stylesheet" href="source/plugin/pintogya/static/pintogya.css?1.0.0-alpha.1-x5" />'
            .'<script defer src="source/plugin/pintogya/static/pintogya.js?1.0.0-alpha.1-x5"></script>';
    }
}

class mobileplugin_pintogya extends plugin_pintogya {}

class plugin_pintogya_forum extends plugin_pintogya {
    public function forumdisplay_top() { return $this->listAssets(); }
    public function forumdisplay_top_mobile() { return $this->listAssets(); }

    public function forumdisplay_thread() { return $this->listMarkers(false); }
    public function forumdisplay_thread_content_mobile() { return $this->listMarkers(false, true); }
    public function forumdisplay_thread_mobile() { return $this->listMarkers(true); }

    public function forumdisplay_output() {
        global $_G;
        foreach(array(
            'forumdisplay_thread' => $this->listMarkers(false),
            'forumdisplay_thread_content_mobile' => $this->listMarkers(false, true),
            'forumdisplay_thread_mobile' => $this->listMarkers(true),
        ) as $hook => $markers) {
            foreach($markers as $key => $marker) {
                $current = (string)($_G['setting']['pluginhooks'][$hook][$key] ?? '');
                if(strpos($current, 'pintogya-list-marker') === false) {
                    $_G['setting']['pluginhooks'][$hook][$key] = $current.$marker;
                }
            }
        }
        return '';
    }

    private function listAssets() {
        if(!$this->listEnabled()) { return ''; }
        return '<link rel="stylesheet" href="source/plugin/pintogya/static/activity-list.css?card-title-20261005&amp;participants=1.0.0-alpha.1-x5" />'
            .'<script defer src="source/plugin/pintogya/static/activity-list.js?card-title-20261005&amp;participants=1.0.0-alpha.1-x5"></script>';
    }

    private function listMarkers($stickyOnly, $nonStickyOnly = false) {
        global $_G;
        if(!$this->listEnabled() || !is_array($_G['forum_threadlist'] ?? null)) { return array(); }
        require_once dirname(__FILE__).'/lib/pintogya.php';
        $lang = pintogya_lang_pack();
        $english = pintogya_task_lang_pack();
        $forumParticipantsLabel = htmlspecialchars((string)($lang['activity_participants_title'] ?? $english['activity_participants_title']), ENT_QUOTES, 'UTF-8');
        $qualifiedLabel = htmlspecialchars((string)$lang['activity_card_qualified_participants'], ENT_QUOTES, 'UTF-8');
        $completedLabel = htmlspecialchars((string)$lang['activity_card_confirmed_completions'], ENT_QUOTES, 'UTF-8');
        $rewardLabel = htmlspecialchars((string)$lang['activity_card_per_completer'], ENT_QUOTES, 'UTF-8');
        $instantLabel = htmlspecialchars((string)($lang['activity_instant_total'] ?? $english['activity_instant_total']), ENT_QUOTES, 'UTF-8');
        $instantPausedLabel = htmlspecialchars((string)($lang['activity_instant_paused'] ?? $english['activity_instant_paused']), ENT_QUOTES, 'UTF-8');
        $rewardShortLabel = htmlspecialchars((string)lang('forum/template', 'thread_reward'), ENT_QUOTES, 'UTF-8');
        $openLabel = htmlspecialchars((string)$lang['activity_card_status_open'], ENT_QUOTES, 'UTF-8');
        $closingLabel = htmlspecialchars((string)$lang['activity_card_status_closing'], ENT_QUOTES, 'UTF-8');
        $closedLabel = htmlspecialchars((string)$lang['activity_card_status_closed'], ENT_QUOTES, 'UTF-8');
        $pausedLabel = htmlspecialchars((string)$lang['activity_card_status_paused'], ENT_QUOTES, 'UTF-8');
        $hash = htmlspecialchars((string)formhash(), ENT_QUOTES, 'UTF-8');
        $markers = array();
        foreach($_G['forum_threadlist'] as $key => $thread) {
            if((int)($thread['special'] ?? 0) !== 127 ||
                (int)($thread['fid'] ?? 0) !== (int)$_G['fid'] ||
                (int)($thread['displayorder'] ?? -1) < 0 ||
                (!empty($thread['threadpluginid']) && $thread['threadpluginid'] !== 'pintogya')) { continue; }
            $sticky = in_array((int)$thread['displayorder'], array(1, 2, 3, 4), true);
            if(($stickyOnly && !$sticky) || ($nonStickyOnly && $sticky)) { continue; }
            $tid = (int)($thread['tid'] ?? 0);
            if($tid < 1) { continue; }
            $markers[$key] = '<span class="pintogya-list-marker" data-tid="'.$tid.'"'
                .' data-formhash="'.$hash.'" data-label-qualified="'.$qualifiedLabel.'"'
                .' data-label-completed="'.$completedLabel.'"'
                .' data-label-forum-participants="'.$forumParticipantsLabel.'"'
                .' data-label-reward="'.$rewardLabel.'" data-label-open="'.$openLabel.'"'
                .' data-label-instant-reward="'.$instantLabel.'" data-label-instant-paused="'.$instantPausedLabel.'"'
                .' data-label-reward-short="'.$rewardShortLabel.'"'
                .' data-label-closing="'.$closingLabel.'" data-label-closed="'.$closedLabel.'"'
                .' data-label-paused="'.$pausedLabel.'" hidden></span>';
        }
        return $markers;
    }

    private function listEnabled() {
        global $_G;
        return (string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1' &&
            (int)($_G['setting']['pintogya_activity_forum_fid'] ?? 0) > 0 &&
            (int)($_G['setting']['pintogya_activity_forum_fid'] ?? 0) === (int)($_G['fid'] ?? 0);
    }
}

class mobileplugin_pintogya_forum extends plugin_pintogya_forum {}
