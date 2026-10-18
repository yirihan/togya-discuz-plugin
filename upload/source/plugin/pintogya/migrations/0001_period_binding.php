<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$lockName = 'togya_install_v1_'.substr(hash('sha256', (string)DB::result_first('SELECT DATABASE()').'|'.DB::table('pintogya_binding')), 0, 24);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_migration_lock_unavailable'); }

$created = array();
try {
    $bindingTable = DB::table('pintogya_binding');
    $prefix = substr($bindingTable, 0, -strlen('pintogya_binding'));
    if($prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) { throw new RuntimeException('pintogya_table_prefix_invalid'); }

    $listTables = function() use ($prefix) {
        $rows = DB::fetch_all("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '".addslashes($prefix)."pintogya\\_%' ESCAPE '\\\\' ORDER BY TABLE_NAME");
        $names = array();
        foreach($rows as $row) { $names[] = substr((string)$row['TABLE_NAME'], strlen($prefix)); }
        sort($names, SORT_STRING);
        return $names;
    };
    $expected = array('pintogya_binding', 'pintogya_challenge');
    sort($expected, SORT_STRING);
    $allowed = array_merge($expected, array('pintogya_task_context', 'pintogya_activity', 'pintogya_activity_action', 'pintogya_participation', 'pintogya_activity_reward_rule', 'pintogya_activity_reward_pool', 'pintogya_activity_reward_member', 'pintogya_activity_reward_payment', 'pintogya_activity_review_case', 'pintogya_activity_review_decision', 'pintogya_activity_rating', 'pintogya_photo_removal_request', 'pintogya_activity_instant_rule', 'pintogya_activity_instant_reward', 'pintogya_activity_instant_sync', 'pintogya_activity_membership_sync', 'pintogya_checkin_post'));
    $actual = $listTables();
    if($actual !== array() && (array_diff($actual, $allowed) || array_diff($expected, $actual))) {
        throw new RuntimeException('pintogya_table_set_unknown');
    }

    if($actual === array()) {
        DB::query("CREATE TABLE `".DB::table('pintogya_binding')."` (
          `binding_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `uid` mediumint unsigned NOT NULL,
          `togya_user_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `generation` int unsigned NOT NULL,
          `state_code` enum('active','revoked') NOT NULL DEFAULT 'active',
          `access_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `refresh_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `access_expires_at` int unsigned NOT NULL,
          `created_at` int unsigned NOT NULL,
          `updated_at` int unsigned NOT NULL,
          `revoked_at` int unsigned NOT NULL DEFAULT '0',
          PRIMARY KEY (`binding_pk`),
          UNIQUE KEY `uq_uid` (`uid`),
          UNIQUE KEY `uq_togya_user` (`togya_user_id`),
          KEY `ix_state_updated` (`state_code`,`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC");
        $created[] = 'pintogya_binding';

        DB::query("CREATE TABLE `".DB::table('pintogya_challenge')."` (
          `challenge_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `uid` mediumint unsigned NOT NULL,
          `task_id` mediumint unsigned NOT NULL,
          `task_instance` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `session_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `challenge_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `verifier_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `device_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `qr_url` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `state_code` enum('pending','approved','denied','expired','superseded') NOT NULL DEFAULT 'pending',
          `created_at` int unsigned NOT NULL,
          `expires_at` int unsigned NOT NULL,
          `approved_at` int unsigned NOT NULL DEFAULT '0',
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`challenge_pk`),
          UNIQUE KEY `uq_challenge_id` (`challenge_id`),
          KEY `ix_latest_instance` (`uid`,`task_id`,`task_instance`,`challenge_pk`),
          KEY `ix_expiry` (`state_code`,`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC");
        $created[] = 'pintogya_challenge';
    }

    $current = $listTables();
    if(array_diff($current, $allowed) || array_diff($expected, $current)) { throw new RuntimeException('pintogya_table_set_incomplete'); }
    foreach($expected as $logical) {
        $table = addslashes(DB::table($logical));
        $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."'");
        if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' || strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' || strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
            throw new RuntimeException('pintogya_table_options_invalid:'.$logical);
        }
    }
    $bindingUnique = intval(DB::result_first("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes(DB::table('pintogya_binding'))."' AND NON_UNIQUE=0 AND INDEX_NAME IN ('uq_uid','uq_togya_user')"));
    $challengeUnique = intval(DB::result_first("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes(DB::table('pintogya_challenge'))."' AND NON_UNIQUE=0 AND INDEX_NAME='uq_challenge_id'"));
    if($bindingUnique !== 2 || $challengeUnique !== 1) { throw new RuntimeException('pintogya_unique_index_invalid'); }
} catch(Throwable $e) {
    foreach(array_reverse($created) as $logical) { DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`'); }
    throw $e;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
