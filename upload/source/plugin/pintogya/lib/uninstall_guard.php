<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaUninstallGuard {
    public static function assertCanUninstall() {
        foreach(array('pintogya_binding','pintogya_challenge') as $logical) {
            $table=DB::table($logical);
            $present=DB::result_first('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table));
            if(!(int)$present) { continue; }
            $sql=$logical==='pintogya_binding' ? "SELECT COUNT(*) FROM %t WHERE state_code='active'" :
                "SELECT COUNT(*) FROM %t WHERE state_code='pending' AND expires_at>".time();
            if((int)DB::result_first($sql,array($logical))>0) {
                throw new RuntimeException('togya_uninstall_requires_unlink_and_expired_challenges');
            }
        }
    }
}
