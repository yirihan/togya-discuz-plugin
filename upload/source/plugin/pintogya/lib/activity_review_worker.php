<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/pintogya.php';
require_once __DIR__.'/activity_review_timeout.php';

final class PintogyaActivityReviewWorker {
    const CURSOR_KEY = 'pintogya_activity_review_timeout_cursor';

    public static function run($limit = 20, $maxSeconds = 30) {
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 20 ||
            !is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 30) {
            throw new InvalidArgumentException('activity_review_batch_invalid');
        }
        $deadline = hrtime(true) / 1000000000 + min(24, $maxSeconds);
        $database = DB::result_first('SELECT DATABASE()');
        if(!is_string($database) || $database === '') {
            throw new RuntimeException('activity_review_database_missing');
        }
        $lockName = 'pintogya_review_'.substr(hash('sha256', $database), 0, 20);
        if((int)DB::result_first('SELECT GET_LOCK(%s, 0)', array($lockName)) !== 1) {
            return array('state' => 'locked', 'scanned' => 0, 'approved' => 0, 'held' => 0);
        }
        try {
            $services = pintogya_services(null, null, null, $deadline);
            $repo = $services['repository'];
            $processor = new PintogyaActivityReviewTimeout($repo, $services['activity_thread'],
                new PintogyaActivityReviewEvidence($services['session']));
            $cursor = self::loadCursor();
            $originalCursor = $cursor;
            $now = $repo->currentUnixTime();
            $rows = $repo->dueReviewCases($now, $cursor['due'], $cursor['pk'], $limit);
            if(!$rows && ($cursor['due'] > 0 || $cursor['pk'] > 0)) {
                $cursor = array('due' => 0, 'pk' => 0);
                $rows = $repo->dueReviewCases($now, 0, 0, $limit);
            }
            $result = array('state' => 'scanned', 'scanned' => 0, 'approved' => 0,
                'held' => 0, 'errors' => array());
            foreach($rows as $row) {
                if(hrtime(true) / 1000000000 >= $deadline) { break; }
                $reviewPk = (int)$row['review_pk'];
                $result['scanned']++;
                try {
                    $outcome = $processor->decide($reviewPk);
                    if($outcome === 'approved') { $result['approved']++; }
                    elseif($outcome === 'source_unavailable') { $result['held']++; }
                    if(hrtime(true) / 1000000000 >= $deadline) { break; }
                    $cursor = array('due' => (int)$row['auto_due_at'], 'pk' => $reviewPk);
                } catch(Throwable $error) {
                    $result['errors'][] = array('review_pk' => $reviewPk, 'code' => 'review_decision_failed');
                    break;
                }
            }
            if($cursor !== $originalCursor) { self::saveCursor($cursor); }
            $result['cursor'] = $cursor;
            return $result;
        } finally {
            try { DB::result_first('SELECT RELEASE_LOCK(%s)', array($lockName)); }
            catch(Throwable $error) { error_log('pintogya_review_lock_release_failed'); }
        }
    }

    private static function loadCursor() {
        $row = DB::fetch_first('SELECT svalue FROM '.DB::table('common_setting')
            ." WHERE skey='".self::CURSOR_KEY."' LIMIT 1");
        if(!$row) { return array('due' => 0, 'pk' => 0); }
        try { $cursor = json_decode($row['svalue'], true, 4, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('activity_review_cursor_invalid', 0, $error); }
        if(!is_array($cursor) || array_keys($cursor) !== array('due', 'pk') ||
            !is_int($cursor['due']) || $cursor['due'] < 0 || $cursor['due'] > 4294967295 ||
            !is_int($cursor['pk']) || $cursor['pk'] < 0 || ($cursor['due'] === 0 && $cursor['pk'] !== 0)) {
            throw new RuntimeException('activity_review_cursor_invalid');
        }
        return $cursor;
    }

    private static function saveCursor($cursor) {
        $json = json_encode($cursor, JSON_THROW_ON_ERROR);
        DB::insert('common_setting', array('skey' => self::CURSOR_KEY, 'svalue' => $json), false, true);
        if(DB::affected_rows() < 1) { throw new RuntimeException('activity_review_cursor_write_failed'); }
    }
}
