<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/pintogya.php';
require_once __DIR__.'/activity_membership_failure.php';

final class PintogyaActivityMembershipWorker {
    const CURSOR_KEY = 'pintogya_activity_membership_cursor';

    public static function run($limit = 5, $services = null) {
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 5) {
            throw new InvalidArgumentException('activity_membership_batch_invalid');
        }
        $deadline = hrtime(true) / 1000000000 + 24.0;
        $database = (string)DB::result_first('SELECT DATABASE()');
        $lock = 'pintogya_members_'.substr(hash('sha256', $database), 0, 20);
        if($database === '' || (int)DB::result_first('SELECT GET_LOCK(%s,0)', array($lock)) !== 1) {
            return array('state' => 'locked', 'scanned' => 0, 'deactivated' => 0, 'held' => 0);
        }
        try {
            $raw = DB::result_first('SELECT svalue FROM '.DB::table('common_setting').' WHERE skey=%s', array(self::CURSOR_KEY));
            $cursor = $raw === null || $raw === false || $raw === '' ? array(0, 0) : json_decode((string)$raw, true);
            if(!is_array($cursor) || array_keys($cursor) !== array(0, 1) ||
                !is_int($cursor[0]) || !is_int($cursor[1]) || $cursor[0] < 0 || $cursor[1] < 0) {
                throw new RuntimeException('activity_membership_cursor_invalid');
            }
            $row = self::activity($cursor[0]);
            if(!$row && $cursor[0] > 0) { $cursor = array(0, 0); $row = self::activity(0); }
            if(!$row) { return array('state' => 'scanned', 'scanned' => 0, 'deactivated' => 0, 'held' => 0); }
            if((int)$row['activity_pk'] !== $cursor[0]) { $cursor = array((int)$row['activity_pk'], 0); }
            if($services === null) {
                if(function_exists('loadcache')) { loadcache('plugin'); }
                $services = pintogya_services(null, null, null, $deadline);
            }
            // One activity and at most five members per invocation; source checks stay in the repair service.
            try {
                $page = $services['activity_membership_repair']->runPage((int)$row['tid'], $cursor[1], $limit, pintogya_api_locale());
            } catch(Throwable $error) {
                $fault = PintogyaMembershipFailure::classify($error,true);
                $faults = PintogyaMembershipFailure::counters(); $faults[$fault['scope']]=1;
                $page = array('scanned'=>0,'deactivated'=>0,'unresolved'=>1,'faults'=>$faults,
                    'hasMore'=>false,'nextCursor'=>$cursor[1],'errors'=>array(array('code'=>$fault['code'])));
            }
            $faults = $page['faults'] ?? array('isolated'=>0,'deferred'=>0,'service'=>0,'unknown'=>(int)$page['unresolved']);
            if(!is_array($faults) || array_keys($faults) !== array('isolated','deferred','service','unknown')) { throw new RuntimeException('activity_membership_fault_profile_invalid'); }
            foreach($faults as $count) if(!is_int($count) || $count < 0 || $count > 5) { throw new RuntimeException('activity_membership_fault_profile_invalid'); }
            if(array_sum($faults) !== $page['unresolved']) { throw new RuntimeException('activity_membership_fault_profile_invalid'); }
            $result = array('state' => 'scanned', 'scanned' => $page['scanned'],
                'deactivated' => $page['deactivated'], 'held' => $page['unresolved'],'errors'=>$page['errors'],
                'membership'=>array('schema'=>1)+$faults);
            if(hrtime(true) / 1000000000 >= $deadline) {
                $result['state']='time_bound';
                if(!$faults['service'] && !$faults['unknown']) {
                    $result['held']++; $result['membership']['service']++;
                    $result['errors'][]=array('code'=>'membership_service_unavailable');
                }
                return $result;
            }
            if($faults['service'] || $faults['unknown'] || ($page['errors'] && !$page['unresolved'])) { return $result; }
            $next = $page['hasMore'] ? array($cursor[0], $page['nextCursor']) : array($cursor[0] + 1, 0);
            DB::insert('common_setting', array('skey' => self::CURSOR_KEY, 'svalue' => json_encode($next)), false, true);
            return $result;
        } finally { DB::result_first('SELECT RELEASE_LOCK(%s)', array($lock)); }
    }

    private static function activity($pk) {
        return DB::fetch_first('SELECT activity_pk,tid FROM '.DB::table('pintogya_activity').
            ' WHERE state_code=%s AND activity_pk>=%d ORDER BY activity_pk LIMIT 1', array('active', $pk));
    }
}
