<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_participation_database_missing'); }
$lockName = 'pintogya:v4:'.substr(hash('sha256', $database), 0, 20);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_participation_migration_lock_unavailable'); }

$logical = 'pintogya_participation';
$table = DB::table($logical);
$created = false;
try {
    if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_participation_table_name_invalid'); }
    $escaped = addslashes($table);
    $exists = (int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if($exists === 0) {
        DB::query('CREATE TABLE `'.$table.'` (
          `participant_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_pk` bigint unsigned NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `togya_user_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `stage_version` int unsigned NOT NULL,
          `state_code` enum(\'active\',\'inactive\',\'revoked\') NOT NULL DEFAULT \'active\',
          `first_joined_at` int unsigned NOT NULL,
          `last_verified_at` int unsigned NOT NULL,
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`participant_pk`),
          UNIQUE KEY `uq_activity_uid` (`activity_pk`,`uid`),
          UNIQUE KEY `uq_activity_togya_user` (`activity_pk`,`togya_user_id`),
          KEY `ix_activity_state` (`activity_pk`,`state_code`),
          KEY `ix_uid_state` (`uid`,`state_code`,`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
        $created = true;
    }
    $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
        strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' ||
        strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
        throw new RuntimeException('pintogya_participation_table_options_invalid');
    }
    $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
    $columns = array();
    foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
    if($columns !== array('participant_pk','activity_pk','uid','togya_user_id','binding_generation','stage_version',
        'state_code','first_joined_at','last_verified_at','updated_at')) {
        throw new RuntimeException('pintogya_participation_columns_invalid');
    }
    $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
    $indexes = array();
    foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
    ksort($indexes, SORT_STRING);
    $expected = array(
        'PRIMARY' => array(0, 'participant_pk'),
        'ix_activity_state' => array(1, 'activity_pk,state_code'),
        'ix_uid_state' => array(1, 'uid,state_code,updated_at'),
        'uq_activity_togya_user' => array(0, 'activity_pk,togya_user_id'),
        'uq_activity_uid' => array(0, 'activity_pk,uid'),
    );
    ksort($expected, SORT_STRING);
    if($indexes !== $expected) { throw new RuntimeException('pintogya_participation_indexes_invalid'); }
} catch(Throwable $error) {
    if($created) { DB::query('DROP TABLE IF EXISTS `'.$table.'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
