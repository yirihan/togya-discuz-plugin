<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_review_database_missing'); }
$lockName = 'pintogya:v7:'.substr(hash('sha256', $database), 0, 20);
if((string)DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)") !== '1') {
    throw new RuntimeException('pintogya_review_migration_lock_unavailable');
}

$definitions = array(
    'pintogya_activity_review_case' => array(
        'ddl' => "(
          `review_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_pk` bigint unsigned NOT NULL,
          `participant_pk` bigint unsigned NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `kind` enum('checkin','completion') NOT NULL,
          `source_ref` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `source_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `source_page_cursor` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `stage_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `stage_version` int unsigned NOT NULL,
          `binding_generation` int unsigned NOT NULL,
          `submitted_at` int unsigned NOT NULL,
          `auto_due_at` int unsigned NOT NULL DEFAULT '0',
          `state_code` enum('pending','approved','rejected','invalid') NOT NULL DEFAULT 'pending',
          `decision_source` enum('none','moderator','timeout') NOT NULL DEFAULT 'none',
          `decided_by_uid` mediumint unsigned DEFAULT NULL,
          `decided_at` int unsigned NOT NULL DEFAULT '0',
          `decision_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`review_pk`),
          UNIQUE KEY `uq_evidence` (`activity_pk`,`uid`,`kind`,`source_ref`,`source_digest`),
          UNIQUE KEY `uq_decision` (`decision_digest`),
          KEY `ix_due` (`state_code`,`auto_due_at`,`review_pk`),
          KEY `ix_activity_pending` (`activity_pk`,`state_code`,`review_pk`),
          KEY `ix_promotion` (`kind`,`state_code`,`decision_source`,`review_pk`),
          KEY `ix_participant` (`participant_pk`,`kind`,`review_pk`)
        )",
        'columns' => array('review_pk','activity_pk','participant_pk','uid','kind','source_ref',
            'source_digest','source_page_cursor','stage_id','stage_version','binding_generation','submitted_at','auto_due_at',
            'state_code','decision_source','decided_by_uid','decided_at','decision_digest','updated_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'review_pk'),
            'ix_activity_pending' => array(1, 'activity_pk,state_code,review_pk'),
            'ix_promotion' => array(1, 'kind,state_code,decision_source,review_pk'),
            'ix_due' => array(1, 'state_code,auto_due_at,review_pk'),
            'ix_participant' => array(1, 'participant_pk,kind,review_pk'),
            'uq_decision' => array(0, 'decision_digest'),
            'uq_evidence' => array(0, 'activity_pk,uid,kind,source_ref,source_digest'),
        ),
    ),
    'pintogya_activity_review_decision' => array(
        'ddl' => "(
          `decision_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `review_pk` bigint unsigned NOT NULL,
          `activity_pk` bigint unsigned NOT NULL,
          `participant_pk` bigint unsigned NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `kind` enum('checkin','completion') NOT NULL,
          `source_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `decision` enum('approved','rejected','invalid') NOT NULL,
          `decision_source` enum('moderator','timeout') NOT NULL,
          `decided_by_uid` mediumint unsigned DEFAULT NULL,
          `decided_at` int unsigned NOT NULL,
          `decision_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          PRIMARY KEY (`decision_pk`),
          UNIQUE KEY `uq_review` (`review_pk`),
          UNIQUE KEY `uq_decision` (`decision_digest`),
          KEY `ix_activity_uid` (`activity_pk`,`uid`,`decision_pk`)
        )",
        'columns' => array('decision_pk','review_pk','activity_pk','participant_pk','uid','kind',
            'source_digest','decision','decision_source','decided_by_uid','decided_at','decision_digest'),
        'indexes' => array(
            'PRIMARY' => array(0, 'decision_pk'),
            'ix_activity_uid' => array(1, 'activity_pk,uid,decision_pk'),
            'uq_decision' => array(0, 'decision_digest'),
            'uq_review' => array(0, 'review_pk'),
        ),
    ),
);

$created = array();
try {
    foreach($definitions as $logical => $definition) {
        $table = DB::table($logical);
        if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_review_table_name_invalid'); }
        $escaped = addslashes($table);
        $exists = (int)DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
        if($exists === 0) {
            DB::query('CREATE TABLE `'.$table.'` '.$definition['ddl'].' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
            $created[] = $logical;
        }
        $meta = DB::fetch_first("SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."'");
        if(!$meta || strtoupper((string)$meta['ENGINE']) !== 'INNODB' ||
            strtolower((string)$meta['TABLE_COLLATION']) !== 'utf8mb4_bin' ||
            strtoupper((string)$meta['ROW_FORMAT']) !== 'DYNAMIC') {
            throw new RuntimeException('pintogya_review_table_options_invalid:'.$logical);
        }
        $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
        $columns = array();
        foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
        if($columns !== $definition['columns']) { throw new RuntimeException('pintogya_review_columns_invalid:'.$logical); }
        $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
        $indexes = array();
        foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
        ksort($indexes, SORT_STRING);
        $expected = $definition['indexes'];
        ksort($expected, SORT_STRING);
        if($indexes !== $expected) { throw new RuntimeException('pintogya_review_indexes_invalid:'.$logical); }
    }
} catch(Throwable $error) {
    foreach(array_reverse($created) as $logical) { DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
