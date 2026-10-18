<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }
$table=DB::table('pintogya_activity_membership_sync');
if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)) { throw new RuntimeException('instant_discovery_table_invalid'); }
$database=(string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('instant_discovery_database_missing'); }
$lock='pintogya:v11:'.substr(hash('sha256',$database),0,20);
if((int)DB::result_first('SELECT GET_LOCK(%s,10)',array($lock)) !== 1) {
    throw new RuntimeException('instant_discovery_migration_locked');
}
$created=false;
try {
    if(!(int)DB::result_first('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table))) {
        DB::query('CREATE TABLE `'.$table.'` (
            `sync_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
            `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `uid` mediumint unsigned NOT NULL,
            `togya_user_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `binding_generation` int unsigned NOT NULL,
            `state_code` enum(\'ready\',\'retry\',\'joined\',\'blocked_auth\',\'held_source\',\'quarantined\') NOT NULL DEFAULT \'ready\',
            `retry_count` tinyint unsigned NOT NULL DEFAULT \'0\',
            `checked_at` int unsigned NOT NULL DEFAULT \'0\',
            `next_attempt_at` int unsigned NOT NULL DEFAULT \'0\',
            `last_error` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\',
            `updated_at` int unsigned NOT NULL,
            PRIMARY KEY (`sync_pk`),
            UNIQUE KEY `uq_activity_uid` (`activity_id`,`uid`),
            KEY `ix_due` (`state_code`,`next_attempt_at`,`sync_pk`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
        $created=true;
    }
    $meta=DB::fetch_first('SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table));
    if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' || $meta['TABLE_COLLATION'] !== 'utf8mb4_bin' ||
        strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') { throw new RuntimeException('instant_discovery_table_options_invalid'); }
    $columns=DB::fetch_all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',array($table));
    if(array_column($columns,'COLUMN_NAME') !== array('sync_pk','activity_id','uid','togya_user_id','binding_generation',
        'state_code','retry_count','checked_at','next_attempt_at','last_error','updated_at')) {
        throw new RuntimeException('instant_discovery_table_columns_invalid');
    }
    $indexes=array();
    foreach(DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME",array($table)) as $row) {
        $indexes[$row['INDEX_NAME']]=array((int)$row['NON_UNIQUE'],$row['columns_list']);
    }
    ksort($indexes, SORT_STRING);
    if($indexes !== array('PRIMARY'=>array(0,'sync_pk'),'ix_due'=>array(1,'state_code,next_attempt_at,sync_pk'),
        'uq_activity_uid'=>array(0,'activity_id,uid'))) { throw new RuntimeException('instant_discovery_table_indexes_invalid'); }
} catch(Throwable $error) {
    if($created) { DB::query('DROP TABLE `'.$table.'`'); }
    throw $error;
} finally {
    DB::result_first('SELECT RELEASE_LOCK(%s)',array($lock));
}
