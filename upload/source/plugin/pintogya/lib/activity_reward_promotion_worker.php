<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/pintogya.php';
require_once __DIR__.'/activity_reward_promotion.php';

final class PintogyaActivityRewardPromotionWorker {
    const CURSOR_KEY = 'pintogya_activity_reward_promotion_cursor';

    public static function run($limit = 20, $maxSeconds = 30) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 20 ||
            !is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 30) {
            throw new InvalidArgumentException('activity_completion_batch_invalid');
        }
        $extcredits = $_G['setting']['extcredits'] ?? array();
        if(!is_array($extcredits)) { throw new RuntimeException('activity_completion_credit_config_invalid'); }
        if(empty($_G['setting']['pintogya_activity_posts_enabled']) ||
            (string)($_G['setting']['pintogya_activity_reviews_enabled'] ?? '0') !== '1') {
            return array('skipped' => 'activity_reviews_disabled');
        }
        $deadline = hrtime(true) / 1000000000 + min(24, $maxSeconds);
        $settlementEnabled = (string)($_G['setting']['pintogya_activity_settlement_enabled'] ?? '0') === '1';
        if($settlementEnabled) { require_once __DIR__.'/activity_settlement.php'; }
        $database = DB::result_first('SELECT DATABASE()');
        if(!is_string($database) || $database === '') {
            throw new RuntimeException('activity_completion_database_missing');
        }
        $lockName = 'pintogya_promote_'.substr(hash('sha256', $database), 0, 20);
        if((int)DB::result_first('SELECT GET_LOCK(%s, 0)', array($lockName)) !== 1) {
            return array('state' => 'locked', 'scanned' => 0, 'recorded' => 0);
        }
        try {
            $services = pintogya_services(null, null, null, $deadline);
            $repo = $services['repository'];
            $promotion = new PintogyaActivityRewardPromotion($repo,
                $services['activity_thread'], $services['activity_review_evidence']);
            $cursor = self::loadCursor();
            $originalCursor = $cursor;
            $rows = $repo->approvedTimeoutCompletionCases($cursor, $limit);
            if(!$rows && $cursor > 0) {
                $cursor = 0;
                $rows = $repo->approvedTimeoutCompletionCases(0, $limit);
            }
            $result = array('state' => 'scanned', 'scanned' => 0, 'recorded' => 0,
                'existing' => 0, 'held' => 0, 'posted' => 0, 'errors' => array());
            foreach($rows as $row) {
                if(hrtime(true) / 1000000000 >= $deadline) { break; }
                $reviewPk = (int)$row['review_pk'];
                if($reviewPk <= $cursor) { throw new RuntimeException('activity_completion_cursor_invalid'); }
                $result['scanned']++;
                try {
                    $state = $promotion->promoteTimeoutCompletion($reviewPk, $extcredits);
                    if($state === 'recorded') { $result['recorded']++; }
                    elseif($state === 'already_recorded') { $result['existing']++; }
                    else { $result['held']++; }
                    if(hrtime(true) / 1000000000 >= $deadline) { break; }
                    if($settlementEnabled && in_array($state, array('recorded', 'already_recorded'), true)) {
                        $settled = PintogyaActivitySettlement::settleTimeout($reviewPk,
                            $services['activity_review_evidence']);
                        if($settled['state'] === 'posted') { $result['posted']++; }
                    }
                    if(hrtime(true) / 1000000000 >= $deadline) { break; }
                    $cursor = $reviewPk;
                } catch(Throwable $error) {
                    $code = $error->getMessage();
                    $result['errors'][] = array('review_pk' => $reviewPk,
                        'code' => in_array($code, array('settlement_commit_outcome_unknown',
                            'settlement_rollback_outcome_unknown'), true) ? $code : 'completion_promotion_failed');
                    if(in_array($code, array('settlement_commit_outcome_unknown',
                        'settlement_rollback_outcome_unknown'), true)) {
                        $result['state'] = 'outcome_unknown';
                        $cursor = $originalCursor;
                        break;
                    }
                    break;
                }
            }
            if($cursor !== $originalCursor) { self::saveCursor($cursor); }
            $result['cursor'] = $cursor;
            return $result;
        } finally {
            try { DB::result_first('SELECT RELEASE_LOCK(%s)', array($lockName)); }
            catch(Throwable $error) { error_log('pintogya_promotion_lock_release_failed'); }
        }
    }

    private static function loadCursor() {
        $row = DB::fetch_first('SELECT svalue FROM '.DB::table('common_setting')
            ." WHERE skey='".self::CURSOR_KEY."' LIMIT 1");
        if(!$row) { return 0; }
        $value = (string)$row['svalue'];
        if(!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value) || (string)(int)$value !== $value) {
            throw new RuntimeException('activity_completion_cursor_invalid');
        }
        return (int)$value;
    }

    private static function saveCursor($cursor) {
        DB::insert('common_setting', array('skey' => self::CURSOR_KEY, 'svalue' => (string)$cursor), false, true);
        if(DB::affected_rows() < 1) { throw new RuntimeException('activity_completion_cursor_write_failed'); }
    }
}
