<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/session.php';
require_once __DIR__.'/repository.php';
require_once __DIR__.'/api_client.php';
require_once __DIR__.'/crypto.php';
require_once __DIR__.'/installation.php';

final class PintogyaSessionKeepalive {
    const MAX_FAILURES = 500;

    public static function run($site, $limit = 20, $store = null, $session = null, $clock = null, $monotonic = null) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !PintogyaInstallation::validScope($site) ||
            !is_int($limit) || $limit < 1 || $limit > 20) { throw new InvalidArgumentException('session_keepalive_arguments_invalid'); }
        PintogyaInstallation::assertScope($site);
        if(($store !== null || $session !== null || $clock !== null || $monotonic !== null) &&
            !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('session_keepalive_injection_forbidden');
        }
        $result=array('state'=>'disabled','scanned'=>0,'renewed'=>0,'held'=>0,'errors'=>array());
        if(function_exists('loadcache')) { loadcache('plugin'); }
        if((string)($_G['cache']['plugin']['pintogya']['enabled'] ?? '') !== '1') { return $result; }
        $clock=$clock ?? static function() { return time(); };
        $monotonic=$monotonic ?? static function() { return hrtime(true)/1000000000; };
        $deadline=$monotonic()+24;
        $store=$store ?? new PintogyaSessionKeepaliveStore($site);
        if(!$store->acquire()) { $result['state']='locked'; return $result; }
        $state=null;
        try {
            $state=$store->state();
            $session=$session ?? new PintogyaSession(new PintogyaRepository(),new PintogyaApiClient('https://api.togya.com',null,$deadline));
            $rows=$store->due($state['cursor'],$limit,$clock());
            if(!$rows && $state['cursor'] > 0) { $state['cursor']=0; $rows=$store->due(0,$limit,$clock()); }
            $result['state']='maintained';
            foreach($rows as $row) {
                if($monotonic() >= $deadline) { $result['state']='time_bound'; break; }
                $uid=(int)$row['uid']; $generation=(int)$row['generation']; $updated=(int)$row['updated_at'];
                $previousCursor=$state['cursor'];
                $state['cursor']=$uid; $result['scanned']++;
                $failed=$state['failures'][$uid] ?? null;
                if($failed && ($failed['generation'] !== $generation || $failed['updated_at'] !== $updated)) {
                    unset($state['failures'][$uid]); $failed=null;
                }
                if($failed && ($failed['terminal'] || $failed['next_at'] > $clock())) {
                    $result['held']++; continue;
                }
                try {
                    if($session->maintainBinding($uid,$generation)) { $result['renewed']++; }
                    unset($state['failures'][$uid]);
                } catch(Throwable $error) {
                    if($error instanceof PintogyaRefreshDeferred) {
                        $state['cursor']=$previousCursor;
                        $result['held']++; $result['errors'][]=array('code'=>'auth_refresh_budget_wait');
                        break; // Do not charge a failed attempt or skip this account on the next normal run.
                    }
                    if(in_array($error->getMessage(),array('binding_inactive','binding_changed'),true)) {
                        unset($state['failures'][$uid]); continue;
                    }
                    $attempt=min(10,(int)($failed['attempts'] ?? 0)+1);
                    $rejected=$error->getMessage() === 'api_unauthorized';
                    if(!isset($state['failures'][$uid]) && count($state['failures']) >= self::MAX_FAILURES) {
                        throw new RuntimeException('session_keepalive_state_full');
                    }
                    $state['failures'][$uid]=array('generation'=>$generation,'updated_at'=>$updated,
                        'attempts'=>$attempt,'terminal'=>$rejected,
                        'next_at'=>$clock()+($attempt === 10 ? 86400 : min(86400,300*(1 << ($attempt-1)))));
                    $result['held']++;
                    $result['errors'][]=array('code'=>$rejected ? 'session_renewal_rejected' :
                        ($attempt === 10 ? 'session_renewal_daily_retry' : 'session_renewal_temporary_failure'));
                }
            }
            return $result;
        } finally {
            try { if($state !== null) { $store->save($state); } }
            finally { $store->release(); }
        }
    }
}

final class PintogyaSessionKeepaliveStore {
    const CACHE_KEY='pintogya_session_keepalive';
    private $lock;
    private $site;
    public function __construct($site) {
        $this->site=$site;
        $this->lock='pintogya_sessions_'.substr(hash('sha256',(string)DB::result_first('SELECT DATABASE()')),0,20);
    }
    public function acquire() { return (int)DB::result_first('SELECT GET_LOCK(%s,0)',array($this->lock)) === 1; }
    public function release() { DB::result_first('SELECT RELEASE_LOCK(%s)',array($this->lock)); }
    public function state() {
        global $_G;
        unset($_G['cache'][self::CACHE_KEY]); loadcache(self::CACHE_KEY,true);
        $state=$_G['cache'][self::CACHE_KEY] ?? null;
        if($state === null) { return array('schema'=>1,'cursor'=>0,'failures'=>array()); }
        if(!is_array($state) || ($state['schema'] ?? null) !== 1 || !is_int($state['cursor'] ?? null) ||
            $state['cursor'] < 0 || !is_array($state['failures'] ?? null) || count($state['failures']) > PintogyaSessionKeepalive::MAX_FAILURES) {
            throw new RuntimeException('session_keepalive_state_invalid');
        }
        foreach($state['failures'] as $uid=>$failure) {
            if(!ctype_digit((string)$uid) || (int)$uid < 1 || !is_array($failure) ||
                !is_int($failure['generation'] ?? null) || $failure['generation'] < 1 ||
                !is_int($failure['updated_at'] ?? null) || !is_int($failure['attempts'] ?? null) ||
                $failure['attempts'] < 1 || $failure['attempts'] > 10 || !is_bool($failure['terminal'] ?? null) ||
                !is_int($failure['next_at'] ?? null)) {
                throw new RuntimeException('session_keepalive_state_invalid');
            }
        }
        return $state;
    }
    public function due($cursor,$limit,$now,$displayCache=null) {
        global $_G;
        if($displayCache!==null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST===true)) {
            throw new RuntimeException('session_keepalive_injection_forbidden');
        }
        // TOGYA_PLUGIN: pintogya_owed_source_priority -- routine keepalive cannot spend the only slot ahead of owed facts.
        if((string)($_G['setting']['pintogya_activity_instant_sync_enabled'] ?? '') === '1' &&
            (string)($_G['setting']['pintogya_activity_instant_enabled'] ?? '') === '1') {
            require_once __DIR__.'/activity_instant_worker.php';
            $due=(new PintogyaActivityInstantSyncStore($this->site))->due(1,$now);
            if($due && !empty($due[0]['unpaid_due'])) { return array(); }
        }
        // TOGYA_PLUGIN pintogya_initial_mirror_priority: do not spend the sole slot on an unrelated idle binding.
        try {
            require_once __DIR__.'/display_cache.php';
            if(($displayCache ?? new PintogyaDisplayCache())->hasInitialFillWork()) { return array(); }
        } catch(Throwable $error) { /* A cache failure must not disable routine session maintenance. */ }
        return DB::fetch_all('SELECT uid,generation,updated_at FROM '.DB::table('pintogya_binding').
            " WHERE state_code='active' AND refresh_ciphertext<>'' AND uid>%d AND updated_at<=%d ORDER BY uid LIMIT ".(int)$limit,
            array($cursor,$now-86400));
    }
    public function save($state) { save_syscache(self::CACHE_KEY,$state); }
}
