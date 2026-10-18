<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_activity_database_missing'); }
$lockName = 'pintogya:v3:'.substr(hash('sha256', $database), 0, 20);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_activity_migration_lock_unavailable'); }

$definitions = array(
    'pintogya_activity' => array(
        'ddl' => "(
          `activity_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `fid` mediumint unsigned NOT NULL,
          `tid` int unsigned DEFAULT NULL,
          `owner_uid` mediumint unsigned NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `stage_version` int unsigned NOT NULL,
          `source_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `reservation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `state_code` enum('pending','active','hidden','invalid') NOT NULL DEFAULT 'pending',
          `pending_expires_at` int unsigned NOT NULL,
          `created_at` int unsigned NOT NULL,
          `updated_at` int unsigned NOT NULL,
          `published_at` int unsigned NOT NULL DEFAULT '0',
          PRIMARY KEY (`activity_pk`),
          UNIQUE KEY `uq_activity_id` (`activity_id`),
          UNIQUE KEY `uq_tid` (`tid`),
          UNIQUE KEY `uq_reservation` (`reservation_digest`),
          KEY `ix_pending` (`state_code`,`pending_expires_at`),
          KEY `ix_owner` (`owner_uid`,`updated_at`)
        )",
        'columns' => array('activity_pk','activity_id','fid','tid','owner_uid','binding_generation','stage_version','source_hash','reservation_digest','state_code','pending_expires_at','created_at','updated_at','published_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'activity_pk'),
            'ix_owner' => array(1, 'owner_uid,updated_at'),
            'ix_pending' => array(1, 'state_code,pending_expires_at'),
            'uq_activity_id' => array(0, 'activity_id'),
            'uq_reservation' => array(0, 'reservation_digest'),
            'uq_tid' => array(0, 'tid'),
        ),
    ),
    'pintogya_activity_action' => array(
        'ddl' => "(
          `action_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `action_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `fid` mediumint unsigned NOT NULL,
          `session_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `qr_challenge_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `stage_version` int unsigned NOT NULL,
          `source_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `purpose` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `state_code` enum('prepared','consumed','invalid') NOT NULL DEFAULT 'prepared',
          `created_at` int unsigned NOT NULL,
          `expires_at` int unsigned NOT NULL,
          `updated_at` int unsigned NOT NULL,
          `consumed_at` int unsigned NOT NULL DEFAULT '0',
          PRIMARY KEY (`action_pk`),
          UNIQUE KEY `uq_action_digest` (`action_digest`),
          UNIQUE KEY `uq_qr_challenge_id` (`qr_challenge_id`),
          KEY `ix_uid_state` (`uid`,`state_code`,`updated_at`),
          KEY `ix_expiry` (`state_code`,`expires_at`)
        )",
        'columns' => array('action_pk','action_digest','uid','fid','session_digest','qr_challenge_id','binding_generation','activity_id','stage_version','source_hash','purpose','state_code','created_at','expires_at','updated_at','consumed_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'action_pk'),
            'ix_expiry' => array(1, 'state_code,expires_at'),
            'ix_uid_state' => array(1, 'uid,state_code,updated_at'),
            'uq_action_digest' => array(0, 'action_digest'),
            'uq_qr_challenge_id' => array(0, 'qr_challenge_id'),
        ),
    ),
);

$created = array();
try {
    foreach($definitions as $logical => $definition) {
        $table = DB::table($logical);
        if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_activity_table_name_invalid'); }
        $escaped = addslashes($table);
        $exists = (int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
        if($exists === 0) {
            DB::query('CREATE TABLE `'.$table.'` '.$definition['ddl'].' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
            $created[] = $logical;
        }
        $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
        if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' || strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' || strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
            throw new RuntimeException('pintogya_activity_table_options_invalid:'.$logical);
        }
        $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
        $columns = array();
        foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
        if($columns !== $definition['columns']) { throw new RuntimeException('pintogya_activity_columns_invalid:'.$logical); }
        $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
        $indexes = array();
        foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
        ksort($indexes, SORT_STRING);
        $expected = $definition['indexes'];
        ksort($expected, SORT_STRING);
        if($indexes !== $expected) { throw new RuntimeException('pintogya_activity_indexes_invalid:'.$logical); }
    }
} catch(Throwable $error) {
    foreach(array_reverse($created) as $logical) { DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
