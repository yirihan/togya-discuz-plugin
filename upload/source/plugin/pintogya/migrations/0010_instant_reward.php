<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('instant_migration_database_missing'); }
$lock = 'pintogya:v10:'.substr(hash('sha256', $database), 0, 20);
if((string)DB::result_first('SELECT GET_LOCK(%s, 10)', array($lock)) !== '1') {
    throw new RuntimeException('instant_migration_lock_unavailable');
}
$definitions = array(
    'pintogya_activity_instant_rule'=>array(
        'ddl'=>"(
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `rule_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `rule_json` mediumtext NOT NULL,
          `source_json` mediumtext NOT NULL,
          `state_code` enum('active','held','archived') NOT NULL DEFAULT 'held',
          `created_at` int unsigned NOT NULL,
          `published_at` int unsigned NOT NULL DEFAULT '0',
          PRIMARY KEY (`activity_id`)
        )",
        'columns'=>array('activity_id','rule_hash','rule_json','source_json','state_code','created_at','published_at'),
        'indexes'=>array('PRIMARY'=>array(0,'activity_id')),
    ),
    'pintogya_activity_instant_reward'=>array(
        'ddl'=>"(
          `reward_pk` int unsigned NOT NULL AUTO_INCREMENT,
          `reward_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `togya_user_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `kind` enum('stage','base') NOT NULL,
          `stage_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `credit_id` tinyint unsigned NOT NULL,
          `amount` int unsigned NOT NULL,
          `rule_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `fact_json` text NOT NULL,
          `occurred_at` int unsigned NOT NULL,
          `observed_at` int unsigned NOT NULL,
          `state_code` enum('eligible','posted','held_policy','held_auth','no_reward','quarantined') NOT NULL DEFAULT 'eligible',
          `credit_log_id` int unsigned DEFAULT NULL,
          `native_entry_json` text NOT NULL,
          `posted_at` int unsigned NOT NULL DEFAULT '0',
          `last_error` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `retry_count` tinyint unsigned NOT NULL DEFAULT '0',
          `next_attempt_at` int unsigned NOT NULL DEFAULT '0',
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`reward_pk`),
          UNIQUE KEY `uq_reward_key` (`reward_key`),
          UNIQUE KEY `uq_credit_log` (`credit_log_id`),
          KEY `ix_due` (`state_code`,`next_attempt_at`,`reward_pk`),
          KEY `ix_activity_uid` (`activity_id`,`uid`,`reward_pk`)
        )",
        'columns'=>array('reward_pk','reward_key','activity_id','togya_user_id','uid','binding_generation',
            'kind','stage_id','credit_id','amount','rule_hash','fact_json','occurred_at','observed_at',
            'state_code','credit_log_id','native_entry_json','posted_at','last_error','retry_count','next_attempt_at','updated_at'),
        'indexes'=>array('PRIMARY'=>array(0,'reward_pk'),'uq_reward_key'=>array(0,'reward_key'),
            'uq_credit_log'=>array(0,'credit_log_id'),'ix_due'=>array(1,'state_code,next_attempt_at,reward_pk'),
            'ix_activity_uid'=>array(1,'activity_id,uid,reward_pk')),
    ),
    'pintogya_activity_instant_sync'=>array(
        'ddl'=>"(
          `sync_pk` int unsigned NOT NULL AUTO_INCREMENT,
          `participant_pk` bigint unsigned NOT NULL,
          `activity_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `togya_user_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `state_code` enum('ready','retry','blocked_auth','held_source','quarantined') NOT NULL DEFAULT 'ready',
          `retry_count` tinyint unsigned NOT NULL DEFAULT '0',
          `next_attempt_at` int unsigned NOT NULL DEFAULT '0',
          `checked_at` int unsigned NOT NULL DEFAULT '0',
          `last_error` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `history_json` text NOT NULL,
          PRIMARY KEY (`sync_pk`),
          UNIQUE KEY `uq_participant` (`participant_pk`),
          KEY `ix_due` (`state_code`,`next_attempt_at`,`sync_pk`)
        )",
        'columns'=>array('sync_pk','participant_pk','activity_id','uid','togya_user_id','binding_generation',
            'state_code','retry_count','next_attempt_at','checked_at','last_error','history_json'),
        'indexes'=>array('PRIMARY'=>array(0,'sync_pk'),'uq_participant'=>array(0,'participant_pk'),
            'ix_due'=>array(1,'state_code,next_attempt_at,sync_pk')),
    ),
);
$created = array();
try {
    foreach($definitions as $logical=>$definition) {
        $table = DB::table($logical);
        if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('instant_migration_table_invalid'); }
        $exists = (int)DB::result_first('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array($table));
        if(!$exists) {
            DB::query('CREATE TABLE `'.$table.'` '.$definition['ddl'].' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
            $created[] = $logical;
        }
        $meta = DB::fetch_first('SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array($table));
        if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
            strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' || strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
            throw new RuntimeException('instant_migration_options_invalid:'.$logical);
        }
        $columns = DB::fetch_all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION', array($table));
        if(array_column($columns, 'COLUMN_NAME') !== $definition['columns']) { throw new RuntimeException('instant_migration_columns_invalid:'.$logical); }
        $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME", array($table));
        $indexes = array();
        foreach($rows as $row) { $indexes[$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], $row['columns_list']); }
        $expected = $definition['indexes']; ksort($expected, SORT_STRING); ksort($indexes, SORT_STRING);
        if($indexes !== $expected) { throw new RuntimeException('instant_migration_indexes_invalid:'.$logical); }
    }
} catch(Throwable $error) {
    foreach(array_reverse($created) as $logical) { DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`'); }
    throw $error;
} finally {
    DB::result_first('SELECT RELEASE_LOCK(%s)', array($lock));
}
