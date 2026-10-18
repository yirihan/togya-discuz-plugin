<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }

$database = (string)DB::result_first('SELECT DATABASE()');
if($database === '') { throw new RuntimeException('pintogya_reward_ledger_database_missing'); }
$lockName = 'pintogya:v6:'.substr(hash('sha256', $database), 0, 20);
$locked = DB::result_first("SELECT GET_LOCK('".addslashes($lockName)."', 10)");
if((string)$locked !== '1') { throw new RuntimeException('pintogya_reward_ledger_migration_lock_unavailable'); }

$definitions = array(
    'pintogya_activity_reward_pool' => array(
        'ddl' => "(
          `pool_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_pk` bigint unsigned NOT NULL,
          `rule_pk` bigint unsigned NOT NULL,
          `rule_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `credit_id` tinyint unsigned NOT NULL,
          `joined_count` int unsigned NOT NULL DEFAULT '0',
          `completed_count` int unsigned NOT NULL DEFAULT '0',
          `score_total` int unsigned NOT NULL DEFAULT '0',
          `target_amount` int unsigned NOT NULL DEFAULT '0',
          `paid_total` bigint unsigned NOT NULL DEFAULT '0',
          `state_code` enum('open','closing','closed','quarantined') NOT NULL DEFAULT 'open',
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`pool_pk`),
          UNIQUE KEY `uq_activity` (`activity_pk`),
          UNIQUE KEY `uq_rule` (`rule_pk`),
          KEY `ix_state_updated` (`state_code`,`updated_at`,`pool_pk`)
        )",
        'columns' => array('pool_pk','activity_pk','rule_pk','rule_hash','credit_id','joined_count',
            'completed_count','score_total','target_amount','paid_total','state_code','updated_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'pool_pk'),
            'ix_state_updated' => array(1, 'state_code,updated_at,pool_pk'),
            'uq_activity' => array(0, 'activity_pk'),
            'uq_rule' => array(0, 'rule_pk'),
        ),
    ),
    'pintogya_activity_reward_member' => array(
        'ddl' => "(
          `reward_pk` bigint unsigned NOT NULL AUTO_INCREMENT,
          `activity_pk` bigint unsigned NOT NULL,
          `participant_pk` bigint unsigned NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `rule_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `credit_id` tinyint unsigned NOT NULL,
          `decision_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `approved_by_uid` mediumint unsigned NOT NULL,
          `receipt_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `receipt_state` enum('pending','verified') NOT NULL DEFAULT 'pending',
          `state_code` enum('awaiting_receipt','eligible','held','disqualified') NOT NULL DEFAULT 'awaiting_receipt',
          `target_amount` int unsigned NOT NULL DEFAULT '0',
          `paid_amount` int unsigned NOT NULL DEFAULT '0',
          `approved_at` int unsigned NOT NULL,
          `receipt_verified_at` int unsigned NOT NULL DEFAULT '0',
          `updated_at` int unsigned NOT NULL,
          PRIMARY KEY (`reward_pk`),
          UNIQUE KEY `uq_activity_uid` (`activity_pk`,`uid`),
          UNIQUE KEY `uq_participant` (`participant_pk`),
          UNIQUE KEY `uq_decision` (`decision_digest`),
          KEY `ix_activity_state` (`activity_pk`,`state_code`,`reward_pk`)
        )",
        'columns' => array('reward_pk','activity_pk','participant_pk','uid','rule_hash','credit_id',
            'decision_digest','approved_by_uid','receipt_digest','receipt_state','state_code',
            'target_amount','paid_amount','approved_at','receipt_verified_at','updated_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'reward_pk'),
            'ix_activity_state' => array(1, 'activity_pk,state_code,reward_pk'),
            'uq_activity_uid' => array(0, 'activity_pk,uid'),
            'uq_decision' => array(0, 'decision_digest'),
            'uq_participant' => array(0, 'participant_pk'),
        ),
    ),
    'pintogya_activity_reward_payment' => array(
        'ddl' => "(
          `payment_pk` int unsigned NOT NULL AUTO_INCREMENT,
          `reward_pk` bigint unsigned NOT NULL,
          `activity_pk` bigint unsigned NOT NULL,
          `uid` mediumint unsigned NOT NULL,
          `credit_id` tinyint unsigned NOT NULL,
          `settlement_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `operation` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `amount` int unsigned NOT NULL,
          `paid_total_after` int unsigned NOT NULL,
          `balance_before` int NOT NULL,
          `balance_after` int NOT NULL,
          `credit_log_id` bigint unsigned DEFAULT NULL,
          `state_code` enum('reserved','posted','quarantined') NOT NULL DEFAULT 'reserved',
          `created_at` int unsigned NOT NULL,
          `posted_at` int unsigned NOT NULL DEFAULT '0',
          PRIMARY KEY (`payment_pk`),
          UNIQUE KEY `uq_settlement` (`settlement_digest`),
          UNIQUE KEY `uq_reward_paid` (`reward_pk`,`paid_total_after`),
          UNIQUE KEY `uq_credit_log` (`credit_log_id`),
          KEY `ix_activity_uid` (`activity_pk`,`uid`,`payment_pk`),
          KEY `ix_state_created` (`state_code`,`created_at`)
        )",
        'columns' => array('payment_pk','reward_pk','activity_pk','uid','credit_id','settlement_digest',
            'operation','amount','paid_total_after','balance_before','balance_after','credit_log_id',
            'state_code','created_at','posted_at'),
        'indexes' => array(
            'PRIMARY' => array(0, 'payment_pk'),
            'ix_activity_uid' => array(1, 'activity_pk,uid,payment_pk'),
            'ix_state_created' => array(1, 'state_code,created_at'),
            'uq_credit_log' => array(0, 'credit_log_id'),
            'uq_reward_paid' => array(0, 'reward_pk,paid_total_after'),
            'uq_settlement' => array(0, 'settlement_digest'),
        ),
    ),
);

$created = array();
try {
    foreach($definitions as $logical => $definition) {
        $table = DB::table($logical);
        if(!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { throw new RuntimeException('pintogya_reward_ledger_table_name_invalid'); }
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
            throw new RuntimeException('pintogya_reward_ledger_table_options_invalid:'.$logical);
        }
        $rows = DB::fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' ORDER BY ORDINAL_POSITION");
        $columns = array();
        foreach($rows as $row) { $columns[] = (string)$row['COLUMN_NAME']; }
        if($columns !== $definition['columns']) { throw new RuntimeException('pintogya_reward_ledger_columns_invalid:'.$logical); }
        $rows = DB::fetch_all("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$escaped."' GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME");
        $indexes = array();
        foreach($rows as $row) { $indexes[(string)$row['INDEX_NAME']] = array((int)$row['NON_UNIQUE'], (string)$row['columns_list']); }
        ksort($indexes, SORT_STRING);
        $expected = $definition['indexes'];
        ksort($expected, SORT_STRING);
        if($indexes !== $expected) { throw new RuntimeException('pintogya_reward_ledger_indexes_invalid:'.$logical); }
    }
} catch(Throwable $error) {
    foreach(array_reverse($created) as $logical) { DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`'); }
    throw $error;
} finally {
    DB::result_first("SELECT RELEASE_LOCK('".addslashes($lockName)."')");
}
