<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/installation.php';
require_once __DIR__.'/capabilities.php';
require_once __DIR__.'/maintenance_runner.php';

final class PintogyaMaintenance {
    const CACHE_KEY = 'pintogya_maintenance_status';

    public static function tasks() {
        return array(
            'pintogya.display_cache'=>array('interval'=>60, 'file'=>'display_worker.php', 'class'=>'PintogyaDisplayWorker'),
            'pintogya.public_cover'=>array('interval'=>300, 'file'=>'activity_cover_worker.php', 'class'=>'PintogyaActivityCoverWorker'),
            'pintogya.membership_reconcile'=>array('interval'=>300, 'file'=>'activity_membership_worker.php', 'class'=>'PintogyaActivityMembershipWorker'),
            'pintogya.session_keepalive'=>array('interval'=>300, 'file'=>'session_keepalive.php', 'class'=>'PintogyaSessionKeepalive'),
            'pintogya.instant_rewards'=>array('interval'=>300, 'file'=>'activity_instant_worker.php', 'class'=>'PintogyaActivityInstantWorker', 'capability'=>'rewards'),
            'pintogya.review_timeout'=>array('interval'=>300, 'file'=>'activity_review_worker.php', 'class'=>'PintogyaActivityReviewWorker', 'capability'=>'native_interactions'),
            'pintogya.reward_promotion'=>array('interval'=>300, 'file'=>'activity_reward_promotion_worker.php', 'class'=>'PintogyaActivityRewardPromotionWorker', 'capability'=>'rewards'),
            'pintogya.reward_topup'=>array('interval'=>300, 'file'=>'activity_topup_worker.php', 'class'=>'PintogyaActivityTopupWorker', 'capability'=>'rewards'),
        );
    }

    public static function execute($task, $limit) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 20 || !isset(self::tasks()[$task])) {
            throw new InvalidArgumentException('maintenance_arguments_invalid');
        }
        $definition = self::tasks()[$task];
        if(!empty($definition['capability']) && !PintogyaCapabilities::available($definition['capability'])) {
            return array('skipped'=>'extension_not_verified');
        }
        if(function_exists('loadcache')) { loadcache('plugin'); }
        if((string)($_G['cache']['plugin']['pintogya']['enabled'] ?? '') !== '1' ||
            (int)DB::result_first('SELECT available FROM %t WHERE identifier=%s', array('common_plugin','pintogya')) !== 1) {
            return array('skipped'=>'plugin_disabled');
        }
        $scope = PintogyaInstallation::scope();
        require_once __DIR__.'/'.$definition['file'];
        $class = $definition['class'];
        if($task === 'pintogya.session_keepalive' || $task === 'pintogya.instant_rewards') {
            return $class::run($scope, $limit);
        }
        if($task === 'pintogya.display_cache') { return $class::run($limit); }
        if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
            return array('skipped'=>'activity_posts_disabled');
        }
        if(in_array($task, array('pintogya.public_cover','pintogya.membership_reconcile'), true)) {
            return $class::run(min($limit, 5));
        }
        return $class::run($limit, 24);
    }

    public static function status() {
        $row = DB::fetch_first('SELECT data FROM %t WHERE cname=%s', array('common_syscache',self::CACHE_KEY));
        if(!$row) { return array(); }
        $value = @unserialize($row['data'], array('allowed_classes'=>false));
        if(!is_array($value)) { throw new RuntimeException('maintenance_state_invalid'); }
        $safe = array();
        foreach(self::tasks() as $key=>$definition) {
            $item = $value[$key] ?? null;
            if(!is_array($item) || !is_int($item['finished_at'] ?? null)) { continue; }
            $safe[$key] = array('finished_at'=>$item['finished_at'], 'result'=>PintogyaMaintenanceRunner::publicResult($item['result'] ?? array()));
        }
        return $safe;
    }

    public static function runDue($limit = 5) {
        if(PHP_SAPI !== 'cli') { throw new RuntimeException('maintenance_cli_only'); }
        $scope = PintogyaInstallation::scope();
        $lock = 'togya_scheduler_'.substr(hash('sha256',$scope),0,30);
        if((int)DB::result_first('SELECT GET_LOCK(%s,0)',array($lock)) !== 1) { return array('state'=>'locked'); }
        $started = hrtime(true) / 1000000000;
        try {
            $state = self::status(); $done = array();
            foreach(self::tasks() as $task=>$definition) {
                if(!empty($definition['capability']) && !PintogyaCapabilities::available($definition['capability'])) { continue; }
                if(time() - (int)($state[$task]['finished_at'] ?? 0) < $definition['interval']) { continue; }
                $remaining = (int)floor(55 - (hrtime(true) / 1000000000 - $started));
                if(count($done) >= 2 || $remaining < 2) { break; }
                $result = PintogyaMaintenanceRunner::run($task,$limit,min(30,$remaining));
                $state[$task] = array('finished_at'=>time(),'result'=>$result);
                save_syscache(self::CACHE_KEY,$state);
                $done[$task] = $state[$task];
            }
            return array('state'=>'processed','tasks'=>$done);
        } finally {
            DB::result_first('SELECT RELEASE_LOCK(%s)',array($lock));
        }
    }
}
