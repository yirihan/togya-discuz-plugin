<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$lockName = 'togya_install_v2_'.substr(hash('sha256', (string)DB::result_first('SELECT DATABASE()').'|'.DB::table('pintogya_task_context')), 0, 24);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_context_migration_lock_unavailable'); }

$created = false;
try {
    $table = DB::table('pintogya_task_context');
    $exists = intval(DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes($table)."'"));
    if($exists === 0) {
        DB::query("CREATE TABLE `".$table."` (
          `context_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `uid` mediumint unsigned NOT NULL,
          `task_id` mediumint unsigned NOT NULL,
          `task_instance` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `task_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `state_code` enum('prepared','active','completed','invalid') NOT NULL DEFAULT 'prepared',
          `baseline_json` mediumtext NOT NULL,
          `matched_activity_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `activity_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `stage_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `stage_version` int unsigned NOT NULL DEFAULT '0',
          `question_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `assigned_at` int unsigned NOT NULL DEFAULT '0',
          `created_at` int unsigned NOT NULL,
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`context_pk`),
          UNIQUE KEY `uq_task_instance` (`uid`,`task_id`,`task_instance`),
          KEY `ix_task_state` (`task_key`,`state_code`,`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC");
        $created = true;
    }

    $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes($table)."'");
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' || strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' || strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
        throw new RuntimeException('pintogya_context_table_options_invalid');
    }
    $expectedColumns = array('context_pk','uid','task_id','task_instance','task_key','binding_generation','state_code','baseline_json','matched_activity_id','activity_id','stage_id','stage_version','question_id','assigned_at','created_at','updated_at');
    $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes($table)."' ORDER BY ORDINAL_POSITION");
    $actualColumns = array();
    foreach($rows as $row) { $actualColumns[] = (string)$row['COLUMN_NAME']; }
    if($actualColumns !== $expectedColumns) { throw new RuntimeException('pintogya_context_columns_invalid'); }
    $indexes = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".addslashes($table)."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
    $actualIndexes = array();
    foreach($indexes as $index) { $actualIndexes[(string)$index['INDEX_NAME']] = array(intval($index['NON_UNIQUE']), (string)$index['columns_list']); }
    ksort($actualIndexes, SORT_STRING);
    $expectedIndexes = array(
        'PRIMARY' => array(0, 'context_pk'),
        'ix_task_state' => array(1, 'task_key,state_code,updated_at'),
        'uq_task_instance' => array(0, 'uid,task_id,task_instance'),
    );
    ksort($expectedIndexes, SORT_STRING);
    if($actualIndexes !== $expectedIndexes) { throw new RuntimeException('pintogya_context_indexes_invalid'); }
    $engineViolations = intval(DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '".addslashes(substr($table, 0, -strlen('pintogya_task_context')))."pintogya\\_%' ESCAPE '\\\\' AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'"));
    if($engineViolations !== 0) { throw new RuntimeException('pintogya_non_innodb_table'); }
} catch(Throwable $error) {
    if($created) { DB::query('DROP TABLE IF EXISTS `'.DB::table('pintogya_task_context').'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
