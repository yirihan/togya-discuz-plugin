<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once __DIR__.'/activity_settlement.php';
require_once __DIR__.'/activity_close.php';

final class PintogyaActivityTopupWorker {
    const CURSOR_KEY = 'pintogya_activity_topup_cursor';
    const MAX_POOLS_PER_RUN = 5;

    public static function run($limit = 20, $maxSeconds = 30, $now = 0) {
        self::number($limit, 1, 20, 'topup_limit_invalid');
        self::number($maxSeconds, 1, 30, 'topup_time_limit_invalid');
        $now = $now ?: time();
        self::number($now, 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'topup_time_invalid');
        $database = DB::result_first('SELECT DATABASE()');
        if(!is_string($database) || $database === '') { throw new RuntimeException('topup_database_missing'); }
        $lockName = 'pintogya_topup_'.substr(hash('sha256', $database), 0, 20);
        if((int)DB::result_first('SELECT GET_LOCK(%s, 0)', array($lockName)) !== 1) {
            return array('state' => 'locked', 'scanned' => 0, 'posted' => 0, 'pools' => 0, 'errors' => array());
        }
        try {
            $cursor = self::loadCursor();
            $original = $cursor;
            $result = array('state' => 'scanned', 'scanned' => 0, 'posted' => 0,
                'pools' => 0, 'closed' => 0, 'errors' => array());
            $started = microtime(true);
            $pool = null;
            $seen = array();
            while($result['scanned'] < $limit && $result['pools'] < self::MAX_POOLS_PER_RUN &&
                microtime(true) - $started < $maxSeconds) {
                if($pool === null) {
                    $pool = $cursor['reward_pk'] > 0
                        ? self::currentPool($cursor['pool_pk']) : self::nextPool($cursor['pool_pk']);
                    if(!$pool) {
                        if($cursor['reward_pk'] > 0) {
                            $cursor['reward_pk'] = 0;
                            continue;
                        }
                        break;
                    }
                    $poolPk = self::number($pool['pool_pk'] ?? null, 1, PHP_INT_MAX, 'topup_pool_invalid');
                    if(isset($seen[$poolPk])) { break; }
                    $seen[$poolPk] = true;
                    $cursor['pool_pk'] = $poolPk;
                    $result['pools']++;
                }
                $activityPk = self::number($pool['activity_pk'] ?? null, 1, PHP_INT_MAX,
                    'topup_activity_invalid');
                $batch = PintogyaActivitySettlement::topUpDueBatch($activityPk, $cursor['reward_pk'], 1, $now);
                $result['scanned'] += $batch['scanned'];
                $result['posted'] += $batch['posted'];
                foreach($batch['errors'] as $error) {
                    $result['errors'][] = array('activity_pk' => $activityPk) + $error;
                }
                if($batch['state'] === 'outcome_unknown') {
                    $result['state'] = 'outcome_unknown';
                    $cursor = $original;
                    break;
                }
                if($batch['has_more']) {
                    if($batch['scanned'] < 1 || $batch['next_cursor'] <= $cursor['reward_pk']) {
                        throw new RuntimeException('topup_cursor_not_advancing');
                    }
                    $cursor['reward_pk'] = $batch['next_cursor'];
                } else {
                    if(!$batch['errors']) {
                        try {
                            $close = PintogyaActivityClose::reconcile($activityPk, $now);
                            if($close['state'] === 'closed' && $close['change']) { $result['closed']++; }
                        } catch(Throwable $error) {
                            $result['errors'][] = array('activity_pk' => $activityPk,
                                'error' => $error->getMessage());
                            if(in_array($error->getMessage(), array('activity_close_commit_outcome_unknown',
                                'activity_close_rollback_outcome_unknown'), true)) {
                                $result['state'] = 'outcome_unknown';
                                $cursor = $original;
                                break;
                            }
                        }
                    }
                    $cursor['reward_pk'] = 0;
                    $pool = null;
                }
            }
            if($cursor !== $original) { self::saveCursor($cursor); }
            $result['cursor'] = $cursor;
            return $result;
        } finally {
            try { DB::result_first('SELECT RELEASE_LOCK(%s)', array($lockName)); }
            catch(Throwable $error) { error_log('pintogya_topup_lock_release_failed'); }
        }
    }

    private static function currentPool($poolPk) {
        $row = DB::fetch_first('SELECT pool_pk,activity_pk,state_code FROM '.DB::table('pintogya_activity_reward_pool')
            .' WHERE pool_pk='.$poolPk.' LIMIT 1');
        return $row && in_array($row['state_code'], array('open', 'closing'), true) ? $row : null;
    }

    private static function nextPool($afterPoolPk) {
        $base = 'SELECT pool_pk,activity_pk,state_code FROM '.DB::table('pintogya_activity_reward_pool')
            .' WHERE pool_pk>';
        $tail = " AND state_code IN ('open','closing') ORDER BY pool_pk LIMIT 1";
        $row = DB::fetch_first($base.$afterPoolPk.$tail);
        return $row ?: ($afterPoolPk > 0 ? DB::fetch_first($base.'0'.$tail) : null);
    }

    private static function loadCursor() {
        $row = DB::fetch_first('SELECT svalue FROM '.DB::table('common_setting')
            ." WHERE skey='".self::CURSOR_KEY."' LIMIT 1");
        if(!$row) { return array('pool_pk' => 0, 'reward_pk' => 0); }
        try { $cursor = json_decode($row['svalue'], true, 4, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('topup_cursor_invalid', 0, $error); }
        if(!is_array($cursor) || array_keys($cursor) !== array('pool_pk', 'reward_pk') ||
            !is_int($cursor['pool_pk']) || !is_int($cursor['reward_pk']) ||
            $cursor['pool_pk'] < 0 || $cursor['reward_pk'] < 0 ||
            ($cursor['pool_pk'] === 0 && $cursor['reward_pk'] !== 0)) {
            throw new RuntimeException('topup_cursor_invalid');
        }
        return $cursor;
    }

    private static function saveCursor($cursor) {
        $value = json_encode($cursor, JSON_THROW_ON_ERROR);
        if(DB::insert('common_setting', array('skey' => self::CURSOR_KEY, 'svalue' => $value), false, true) === false) {
            throw new RuntimeException('topup_cursor_save_failed');
        }
    }

    private static function number($value, $min, $max, $error) {
        if(!is_int($value)) {
            if(!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
                throw new RuntimeException($error);
            }
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new RuntimeException($error); }
            $value = $parsed;
        }
        if($value < $min || $value > $max) { throw new RuntimeException($error); }
        return $value;
    }
}
