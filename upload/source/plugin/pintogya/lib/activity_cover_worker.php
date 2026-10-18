<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/pintogya.php';
require_once __DIR__.'/activity_cover.php';

final class PintogyaActivityCoverWorker {
    const CURSOR_KEY = 'pintogya_activity_cover_cursor';

    public static function run($limit = 5, $services = null) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 5) {
            throw new InvalidArgumentException('activity_cover_batch_invalid');
        }
        $database = (string)DB::result_first('SELECT DATABASE()');
        $lock = 'pintogya_cover_'.substr(hash('sha256', $database), 0, 20);
        if($database === '' || (int)DB::result_first('SELECT GET_LOCK(%s,0)', array($lock)) !== 1) {
            return array('state' => 'locked', 'scanned' => 0, 'cached' => 0);
        }
        try {
            $cursor = (int)DB::result_first('SELECT svalue FROM '.DB::table('common_setting').
                ' WHERE skey=%s', array(self::CURSOR_KEY));
            $rows = self::rows($cursor, $limit);
            if(!$rows && $cursor > 0) { $cursor = 0; $rows = self::rows(0, $limit); }
            $result = array('scanned' => 0, 'cached' => 0, 'held' => 0);
            if($services === null) {
                if(function_exists('loadcache')) { loadcache('plugin'); }
                $config = pintogya_config();
                if(!$config['enabled']) { throw new RuntimeException('feature_disabled'); }
                // Public projection needs no member session or token-encryption key.
                $reader = new PintogyaActivityRead(null, new PintogyaApiClient($config['api_base_url']));
                $services = array('activity_thread' => new PintogyaActivityThread(null, new PintogyaRepository(), $reader));
            }
            $started = microtime(true);
            foreach($rows as $row) {
                if(microtime(true) - $started >= 20) { break; }
                $cursor = (int)$row['activity_pk'];
                $result['scanned']++;
                try {
                    // This rechecks thread mapping, public visibility and the frozen source version.
                    $projection = $services['activity_thread']->publicProjection((int)$row['tid'], pintogya_api_locale());
                    $url = (string)$projection['activity']['thumbnailUrl'];
                    if(PintogyaActivityCover::cacheName($url) !== '' &&
                        PintogyaActivityCover::populate($url, $_G['setting']['attachdir'] ?: 'data/attachment')) {
                        $result['cached']++;
                    }
                } catch(Throwable $error) { $result['held']++; }
            }
            DB::insert('common_setting', array('skey' => self::CURSOR_KEY, 'svalue' => (string)$cursor), false, true);
            return $result;
        } finally { DB::result_first('SELECT RELEASE_LOCK(%s)', array($lock)); }
    }

    private static function rows($cursor, $limit) {
        $query = DB::query('SELECT activity_pk,tid FROM '.DB::table('pintogya_activity').
            ' WHERE state_code=%s AND activity_pk>%d ORDER BY activity_pk LIMIT %d', array('active', $cursor, $limit));
        $rows = array();
        while($row = DB::fetch($query)) { $rows[] = $row; }
        return $rows;
    }
}
