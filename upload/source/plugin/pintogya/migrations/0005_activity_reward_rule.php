<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_reward_rule_database_missing'); }
$lockName = 'pintogya:v5:'.substr(hash('sha256', $database), 0, 20);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_reward_rule_migration_lock_unavailable'); }

$table = DB::table('pintogya_activity_reward_rule');
$created = false;
try {
    if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_reward_rule_table_name_invalid'); }
    $escaped = addslashes($table);
    $exists = (int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if($exists === 0) {
        DB::query('CREATE TABLE `'.$table.'` (
          `rule_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `action_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `credit_id` tinyint unsigned NOT NULL,
          `rules_json` varchar(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `rule_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `created_at` int unsigned NOT NULL,
          PRIMARY KEY (`rule_pk`),
          UNIQUE KEY `uq_action_digest` (`action_digest`),
          KEY `ix_activity` (`activity_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
        $created = true;
    }
    $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
        strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' ||
        strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
        throw new RuntimeException('pintogya_reward_rule_table_options_invalid');
    }
    $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
    $columns = array();
    foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
    if($columns !== array('rule_pk','action_digest','activity_id','credit_id','rules_json','rule_hash','created_at')) {
        throw new RuntimeException('pintogya_reward_rule_columns_invalid');
    }
    $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
    $indexes = array();
    foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
    ksort($indexes, SORT_STRING);
    $expected = array(
        'PRIMARY' => array(0, 'rule_pk'),
        'ix_activity' => array(1, 'activity_id,created_at'),
        'uq_action_digest' => array(0, 'action_digest'),
    );
    ksort($expected, SORT_STRING);
    if($indexes !== $expected) { throw new RuntimeException('pintogya_reward_rule_indexes_invalid'); }
} catch(Throwable $error) {
    if($created) { DB::query('DROP TABLE IF EXISTS `'.$table.'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
