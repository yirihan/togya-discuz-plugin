<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_rating_database_missing'); }
$lockName = 'pintogya:v8:'.substr(hash('sha256', $database), 0, 20);
if((string)DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)") !== '1') {
    throw new RuntimeException('pintogya_rating_migration_lock_unavailable');
}
$logical = 'pintogya_activity_rating';
$table = DB::table($logical);
$created = false;
try {
    if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_rating_table_name_invalid'); }
    $escaped = addslashes($table);
    $exists = (int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if($exists === 0) {
        if(DB::query("CREATE TABLE `".$table."` (
            `rating_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
            `activity_pk` bigint unsigned NOT NULL,
            `tid` int unsigned NOT NULL,
            `pid` int unsigned NOT NULL,
            `rater_uid` mediumint unsigned NOT NULL,
            `credit_id` tinyint unsigned NOT NULL,
            `score` int unsigned NOT NULL,
            `ratetimes` int unsigned NOT NULL,
            `ratelog_dateline` int unsigned NOT NULL,
            `state_code` enum('active','reversed') NOT NULL DEFAULT 'active',
            `created_at` int unsigned NOT NULL,
            `reversed_at` int unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`rating_pk`),
            UNIQUE KEY `uq_activity_rater_credit` (`activity_pk`,`rater_uid`,`credit_id`),
            KEY `ix_activity_state` (`activity_pk`,`state_code`,`rating_pk`),
            KEY `ix_post_rater` (`pid`,`rater_uid`,`credit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC") === false) {
            throw new RuntimeException('pintogya_rating_table_create_failed');
        }
        $created = true;
    }
    $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
        strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' ||
        strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
        throw new RuntimeException('pintogya_rating_table_options_invalid');
    }
    $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
    $columns = array();
    foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
    if($columns !== array('rating_pk','activity_pk','tid','pid','rater_uid','credit_id','score','ratetimes',
        'ratelog_dateline','state_code','created_at','reversed_at')) {
        throw new RuntimeException('pintogya_rating_columns_invalid');
    }
    $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
    $indexes = array();
    foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
    ksort($indexes, SORT_STRING);
    $expected = array(
        'PRIMARY' => array(0, 'rating_pk'),
        'ix_activity_state' => array(1, 'activity_pk,state_code,rating_pk'),
        'ix_post_rater' => array(1, 'pid,rater_uid,credit_id'),
        'uq_activity_rater_credit' => array(0, 'activity_pk,rater_uid,credit_id'),
    );
    ksort($expected, SORT_STRING);
    if($indexes !== $expected) { throw new RuntimeException('pintogya_rating_indexes_invalid'); }
} catch(Throwable $error) {
    if($created) { DB::query('DROP TABLE IF EXISTS `'.$table.'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
