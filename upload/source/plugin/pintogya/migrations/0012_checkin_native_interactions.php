<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }
$table=DB::table('pintogya_checkin_post');
if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)) { throw new RuntimeException('checkin_post_table_invalid'); }
$lock='pintogya:v12:'.substr(hash('sha256',(string)DB::result_first('SELECT DATABASE()')),0,20);
if((int)DB::result_first('SELECT GET_LOCK(%s,10)',array($lock))!==1) { throw new RuntimeException('checkin_post_migration_locked'); }
try {
    if(!(int)DB::result_first('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table))) {
        DB::query('CREATE TABLE `'.$table.'` (
            `interaction_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
            `activity_pk` bigint unsigned NOT NULL,
            `tid` int unsigned NOT NULL,
            `stage_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `stage_version` int unsigned NOT NULL,
            `owner_uid` mediumint unsigned NOT NULL,
            `togya_user_id` varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `binding_generation` int unsigned NOT NULL,
            `checkin_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `source_cursor` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\',
            `pid` int unsigned NOT NULL,
            `state_code` enum(\'active\',\'retired\') NOT NULL DEFAULT \'active\',
            `created_at` int unsigned NOT NULL,
            `updated_at` int unsigned NOT NULL,
            PRIMARY KEY (`interaction_pk`),
            UNIQUE KEY `uq_owner_stage` (`activity_pk`,`stage_id`,`stage_version`,`owner_uid`),
            UNIQUE KEY `uq_source_stage` (`activity_pk`,`stage_id`,`stage_version`,`togya_user_id`),
            UNIQUE KEY `uq_pid` (`pid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
    }
    $meta=DB::fetch_first('SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table));
    if(!$meta || strtoupper((string)$meta['ENGINE'])!=='INNODB' || $meta['TABLE_COLLATION']!=='utf8mb4_bin' || strtoupper((string)$meta['ROW_FORMAT'])!=='DYNAMIC') {
        throw new RuntimeException('checkin_post_table_options_invalid');
    }
    $columns=DB::fetch_all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',array($table));
    if(array_column($columns,'COLUMN_NAME')!==array('interaction_pk','activity_pk','tid','stage_id','stage_version','owner_uid',
        'togya_user_id','binding_generation','checkin_id','source_cursor','pid','state_code','created_at','updated_at')) {
        throw new RuntimeException('checkin_post_table_columns_invalid');
    }
    $indexes=array();
    foreach(DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME",array($table)) as $row) {
        $indexes[$row['INDEX_NAME']]=array((int)$row['NON_UNIQUE'],$row['columns_list']);
    }
    if($indexes!==array('PRIMARY'=>array(0,'interaction_pk'),'uq_owner_stage'=>array(0,'activity_pk,stage_id,stage_version,owner_uid'),
        'uq_pid'=>array(0,'pid'),'uq_source_stage'=>array(0,'activity_pk,stage_id,stage_version,togya_user_id'))) {
        throw new RuntimeException('checkin_post_table_indexes_invalid');
    }
} finally { DB::result_first('SELECT RELEASE_LOCK(%s)',array($lock)); }
