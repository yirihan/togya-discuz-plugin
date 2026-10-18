<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_settings.php';

final class PintogyaActivityForumGate {
    public static function assertNewThread($fid, $settings, $special, $specialextra, $module, $forumPlugins, $groupPlugins, $uid, $pluginAvailable, $groupId) {
        $target = self::targetForum($settings, $pluginAvailable);
        if($target === 0 || (int)$fid !== $target) { return false; }
        if((int)$uid < 1 || (int)$special !== 127 || $specialextra !== 'pintogya' || $module !== 'pintogya/thread') {
            throw new RuntimeException('activity_thread_required');
        }
        if(!is_array($forumPlugins) || !in_array('pintogya', $forumPlugins, true) ||
            !is_array($groupPlugins) || !in_array('pintogya', $groupPlugins, true)) {
            throw new RuntimeException('activity_thread_not_permitted');
        }
        if(!PintogyaActivitySettings::isPublisherGroupAllowed($groupId, $settings)) {
            throw new RuntimeException('activity_publisher_group_not_permitted');
        }
        return true;
    }

    public static function assertTopicAdmin($action, $sourceFid, $destinationFid, $operations, $settings, $pluginAvailable) {
        $target = self::targetForum($settings, $pluginAvailable);
        if($target === 0) { return false; }
        if(($action === 'copy' && (int)$destinationFid === $target) ||
            ($action === 'bulk_move' && (int)$destinationFid === $target) ||
            ($action === 'moderate' && is_array($operations) &&
                (in_array('move', $operations, true) || in_array('recommend_group', $operations, true)) &&
                (int)$sourceFid !== $target && (int)$destinationFid === $target) ||
            ($action === 'split' && (int)$sourceFid === $target)) {
            throw new RuntimeException('activity_topicadmin_disallowed');
        }
        return false;
    }

    public static function assertForumMerge($sourceFid, $destinationFid, $settings, $pluginAvailable) {
        $target = self::targetForum($settings, $pluginAvailable);
        if($target !== 0 && ((int)$sourceFid === $target || (int)$destinationFid === $target)) {
            throw new RuntimeException('activity_forum_merge_disallowed');
        }
        return false;
    }

    public static function assertGroupMerge($sourceFids, $destinationFid, $settings, $pluginAvailable) {
        $target = self::targetForum($settings, $pluginAvailable);
        if($target === 0) { return false; }
        if((int)$destinationFid === $target) { throw new RuntimeException('activity_group_merge_disallowed'); }
        if(is_string($sourceFids)) { $sourceFids = explode(',', $sourceFids); }
        if(!is_array($sourceFids)) { throw new RuntimeException('activity_group_merge_source_invalid'); }
        foreach($sourceFids as $fid) {
            if((!is_string($fid) && !is_int($fid)) ||
                !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$fid)) {
                throw new RuntimeException('activity_group_merge_source_invalid');
            }
            if((int)$fid === $target) { throw new RuntimeException('activity_group_merge_disallowed'); }
        }
        return false;
    }

    public static function assertOrdinaryThreadDestination($fid, $settings, $pluginAvailable) {
        $target = self::targetForum($settings, $pluginAvailable);
        if($target !== 0 && (int)$fid === $target) {
            throw new RuntimeException('activity_ordinary_thread_destination_disallowed');
        }
        return false;
    }

    private static function targetForum($settings, $pluginAvailable) {
        if(!$pluginAvailable || !is_array($settings) || !isset($settings['pintogya_activity_posts_enabled']) ||
            !(is_string($settings['pintogya_activity_posts_enabled']) || is_int($settings['pintogya_activity_posts_enabled'])) ||
            (string)$settings['pintogya_activity_posts_enabled'] !== '1') {
            return 0;
        }
        $target = isset($settings['pintogya_activity_forum_fid']) &&
            (is_string($settings['pintogya_activity_forum_fid']) || is_int($settings['pintogya_activity_forum_fid'])) ?
            (string)$settings['pintogya_activity_forum_fid'] : '';
        return preg_match('/^[1-9][0-9]{0,9}$/D', $target) && (int)$target <= 2147483647 ? (int)$target : 0;
    }
}
