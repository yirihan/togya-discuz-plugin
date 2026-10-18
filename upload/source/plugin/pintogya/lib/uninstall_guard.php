<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaUninstallGuard {
    const TABLES = array('pintogya_binding','pintogya_challenge','pintogya_task_context','pintogya_activity',
        'pintogya_activity_action','pintogya_participation','pintogya_activity_reward_rule',
        'pintogya_activity_reward_pool','pintogya_activity_reward_member','pintogya_activity_reward_payment',
        'pintogya_photo_removal_request','pintogya_checkin_post');

    public static function assertCanUninstall() {
        foreach(self::TABLES as $logical) {
            $table=DB::table($logical);
            $present=DB::result_first('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table));
            if(!(int)$present) { continue; }
            if((int)DB::result_first('SELECT COUNT(*) FROM %t',array($logical))>0) {
                throw new RuntimeException('pintogya_uninstall_requires_verified_remote_revoke');
            }
        }
    }
}
