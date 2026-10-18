<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityReadAccess {
    public static function assertCurrent($tid) {
        global $_G;
        if(!is_int($tid) || $tid < 1) { throw new InvalidArgumentException('activity_tid_invalid'); }
        if(!function_exists('loadforum')) { require_once libfile('function/forum'); }
        // Reuse DX's current-user forum/access-mask/moderator context, not the page's formhash as a grant.
        loadforum(null, $tid);
        $thread = $_G['forum_thread'] ?? null;
        $forum = $_G['forum'] ?? null;
        if(!is_array($thread) || !is_array($forum) || (int)($thread['tid'] ?? 0) !== $tid ||
            (int)($thread['fid'] ?? 0) !== (int)($forum['fid'] ?? 0) || (int)($forum['fid'] ?? 0) < 1 ||
            (int)($thread['special'] ?? 0) !== 127 || (int)($thread['displayorder'] ?? -1) < 0 ||
            !in_array((int)($forum['status'] ?? 0), array(1,3), true) || !empty($forum['redirect'])) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        if((int)$forum['status'] === 3) {
            if(!function_exists('groupperm')) { require_once libfile('function/group'); }
            if(in_array(groupperm($forum, (int)$_G['uid']), array(1,2,3), true)) {
                throw new RuntimeException('activity_thread_unavailable');
            }
        }
        $allow = (int)($forum['allowview'] ?? 0);
        if($allow === -1 || ($allow === 0 && (empty($forum['viewperm'])
            ? empty($_G['group']['readaccess']) : !forumperm($forum['viewperm'])))) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        // Native formulaperm may return false for an allowed exempt group; denial terminates natively.
        if(!empty($forum['formulaperm'])) { formulaperm($forum['formulaperm']); }
        if(!empty($forum['password']) && (!is_string($_G['cookie']['fidpw'.$forum['fid']] ?? null) ||
            !hash_equals((string)$forum['password'], $_G['cookie']['fidpw'.$forum['fid']]))) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        if(!empty($forum['price']) && empty($forum['ismoderator']) &&
            table_common_member_forum_buylog::t()->get_credits((int)$_G['uid'], (int)$forum['fid']) < $forum['price']) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        if((int)($thread['readperm'] ?? 0) > (int)($_G['group']['readaccess'] ?? 0) &&
            empty($forum['ismoderator']) && (int)$thread['authorid'] !== (int)$_G['uid']) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        $post = table_forum_post::t()->fetch_threadpost_by_tid_invisible($tid);
        if(!is_array($post) || (int)($post['first'] ?? 0) !== 1 ||
            (int)($post['tid'] ?? 0) !== $tid || (int)($post['invisible'] ?? -1) !== 0) {
            throw new RuntimeException('activity_thread_unavailable');
        }
    }
}
