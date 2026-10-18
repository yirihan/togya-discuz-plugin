<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

// Candidate only: not registered in install/upgrade until a backed-up DB installation is approved.
$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_photo_removal_database_missing'); }
$lockName = 'pintogya:v9:'.substr(hash('sha256', $database), 0, 20);
if((string)DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)") !== '1') {
    throw new RuntimeException('pintogya_photo_removal_migration_lock_unavailable');
}
$table = DB::table('pintogya_photo_removal_request');
try {
    if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_photo_removal_table_name_invalid'); }
    $escaped = addslashes($table);
    if((int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'") === 0) {
        DB::query('CREATE TABLE `'.$table.'` (
          `request_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_pk` bigint unsigned NOT NULL,
          `stage_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `stage_version` int unsigned NOT NULL,
          `checkin_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `media_ref` enum(\'primary\') NOT NULL,
          `evidence_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `requested_by_uid` mediumint unsigned NOT NULL,
          `requested_at` int unsigned NOT NULL,
          `state_code` enum(\'pending_provider\') NOT NULL DEFAULT \'pending_provider\',
          PRIMARY KEY (`request_pk`),
          UNIQUE KEY `uq_evidence` (`activity_pk`,`stage_id`,`stage_version`,`checkin_id`,`media_ref`,`evidence_digest`),
          KEY `ix_activity_pending` (`activity_pk`,`state_code`,`request_pk`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
    }
    $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
        strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' ||
        strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
        throw new RuntimeException('pintogya_photo_removal_table_options_invalid');
    }
    $rows = DB::fetch_all("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
    $columns = array();
    foreach($rows as $row) { $columns[(string)$row['COLUMN_NAME']] = strtolower((string)$row['COLUMN_TYPE']); }
    if($columns !== array('request_pk'=>'bigint unsigned', 'activity_pk'=>'bigint unsigned',
        'stage_id'=>'char(36)', 'stage_version'=>'int unsigned', 'checkin_id'=>'char(36)',
        'media_ref'=>"enum('primary')", 'evidence_digest'=>'char(64)',
        'requested_by_uid'=>'mediumint unsigned', 'requested_at'=>'int unsigned',
        'state_code'=>"enum('pending_provider')")) {
        throw new RuntimeException('pintogya_photo_removal_columns_invalid');
    }
    $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
    $indexes = array();
    foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
    $expected = array('PRIMARY'=>array(0, 'request_pk'),
        'ix_activity_pending'=>array(1, 'activity_pk,state_code,request_pk'),
        'uq_evidence'=>array(0, 'activity_pk,stage_id,stage_version,checkin_id,media_ref,evidence_digest'));
    ksort($indexes, SORT_STRING);
    ksort($expected, SORT_STRING);
    if($indexes !== $expected) {
        throw new RuntimeException('pintogya_photo_removal_indexes_invalid');
    }
} finally { DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')"); }
