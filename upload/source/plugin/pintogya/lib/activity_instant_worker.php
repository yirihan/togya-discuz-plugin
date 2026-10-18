<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_facts.php';
require_once __DIR__.'/activity_instant_history.php';
require_once __DIR__.'/activity_instant_settlement.php';
require_once __DIR__.'/api_client.php';
require_once __DIR__.'/session.php';
require_once __DIR__.'/activity_instant_discovery.php';

final class PintogyaActivityInstantWorker {
    const MAX_ATTEMPTS = 10;

    public static function run($site, $limit = 5, $store = null, $source = null, $payments = null, $clock = null, $monotonic = null, $discovery = null) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !is_int($limit) || $limit < 1 || $limit > 20 ||
            !PintogyaInstallation::validScope($site)) {
            throw new InvalidArgumentException('instant_worker_arguments_invalid');
        }
        PintogyaInstallation::assertScope($site);
        $injected = $store !== null || $source !== null || $payments !== null || $clock !== null || $monotonic !== null || $discovery !== null;
        if($injected && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('instant_worker_injection_forbidden');
        }
        $result = array('state'=>'disabled','scanned'=>0,'established'=>0,'posted'=>0,'held'=>0,'errors'=>array());
        if((string)($_G['setting'][PintogyaActivityInstantRule::SYNC_ENABLED_KEY] ?? '') !== '1') { return $result; }
        $clock = $clock ?? static function() { return time(); };
        $monotonic = $monotonic ?? static function() { return hrtime(true) / 1000000000; };
        $deadline = $monotonic() + 24.0;
        if($store === null) { $store = new PintogyaActivityInstantSyncStore($site); }
        if(!$store->acquire()) { $result['state']='locked'; return $result; }
        try {
            $payments = $payments ?? new PintogyaActivityInstantPayments($site);
            $pay = (string)($_G['setting'][PintogyaActivityInstantRule::ENABLED_KEY] ?? '') === '1';
            $budget = $limit; $historyBudget = 5;
            $attempted = array();
            $result['state']='observed';
            // Local eligible rights survive source archival, expired sessions, and active-only discovery.
            if($pay) {
                foreach($store->payable($limit, $clock()) as $pk) {
                    if($monotonic() >= $deadline) { $result['state']='time_bound'; return $result; }
                    self::pay($pk, $payments, $store, $clock(), $result); $budget--; $attempted[$pk]=true;
                }
            }
            if($monotonic() >= $deadline) { $result['state']='time_bound'; return $result; }
            $discovery = $discovery ?? new PintogyaActivityInstantDiscovery();
            $discover = static function() use($discovery,$site,$limit,$deadline,$clock,$monotonic,&$result) {
                $result['discovery'] = $discovery->run($site,min($limit,2),min($deadline,$monotonic()+6.0),$clock,$monotonic);
                $result['held'] += (int)($result['discovery']['held'] ?? 0);
                foreach($result['discovery']['errors'] ?? array() as $code) { $result['errors'][]=array('code'=>$code); }
                if($result['discovery']['state'] === 'awaiting_schema') {
                    $result['held']++; $result['errors'][]=array('code'=>'instant_discovery_schema_missing');
                }
            };
            $store->seed($limit, $clock());
            $rows = $store->due(min($limit,5), $clock());
            // TOGYA_PLUGIN: pintogya_owed_source_priority -- revalidate owed facts before spending refresh on discovery.
            $owedFirst = $rows && !empty($rows[0]['unpaid_due']);
            if(!$owedFirst) {
                $discover();
                $store->seed($limit, $clock());
                $rows = $store->due(min($limit,5), $clock());
            }
            foreach($rows as $row) {
                if($monotonic() >= $deadline) { $result['state']='time_bound'; break; }
                $result['scanned']++;
                try {
                    $savedHistory = array_key_exists('history_json',$row) ? PintogyaActivityInstantHistory::decode($row['history_json']) :
                        ($row['history'] ?? null);
                    if($source === null) {
                        if(function_exists('loadcache')) { loadcache('plugin'); }
                        if((string)($_G['cache']['plugin']['pintogya']['enabled'] ?? '') !== '1') {
                            throw new RuntimeException('instant_source_disabled');
                        }
                        $source = new PintogyaSession(new PintogyaRepository(),
                            new PintogyaApiClient('https://api.togya.com',null,$deadline));
                    }
                    $data = $source->instantRewardSourceReadOnly($row['uid'], $row['activity_id'], 'en-US');
                    if(($data['binding']['uid'] ?? null) != $row['uid'] ||
                        ($data['binding']['togya_user_id'] ?? null) !== $row['togya_user_id'] ||
                        (int)($data['binding']['generation'] ?? 0) !== $row['binding_generation']) {
                        throw new RuntimeException('instant_binding_changed');
                    }
                    $facts = PintogyaActivityInstantFacts::fromJourney($data['binding'], $data['detail'],
                        $data['journey'], $row['rule'], $clock());
                    foreach($facts['candidates'] as $candidate) {
                        if($monotonic() >= $deadline) { $result['state']='time_bound'; return $result; }
                        self::establish($candidate,$row['rule'],$payments,$store,$pay,$budget,$attempted,$clock(),$result);
                    }
                    $partial = $facts['state'] === 'partial_history';
                    $checkpoint = null;
                    if($partial && $facts['history_stage_ids']) {
                        $history = PintogyaActivityInstantHistory::advance($data['binding'],$data['detail'],$row['rule'],
                            $facts['history_stage_ids'],$savedHistory,
                            static function($stageId,$cursor) use($source,$row,$data) {
                                return $source->instantRewardStageFeedReadOnly($row['uid'],$data['binding'],
                                    $row['activity_id'],$stageId,$row['rule']['stage_version'],$cursor);
                            }, $historyBudget, $clock, static function() use($monotonic,$deadline) { return $monotonic() >= $deadline; },
                            static function($candidate) use($monotonic,$deadline,$row,$payments,$store,$pay,&$budget,&$attempted,$clock,&$result) {
                                if($monotonic() >= $deadline) { throw new RuntimeException('instant_history_time_bound'); }
                                self::establish($candidate,$row['rule'],$payments,$store,$pay,$budget,$attempted,$clock(),$result);
                            });
                        $checkpoint = $history['checkpoint'];
                        $partial = !$facts['history_access_complete'] || $checkpoint !== null;
                    }
                    $store->observed($row['sync_pk'], $clock(), $partial ? 'instant_partial_history' :
                        ($facts['base_source_available'] ? '' : 'instant_base_source_unavailable'),$checkpoint);
                    if($partial) {
                        // Continue keyset history on the normal poll schedule, independent of other stage payments.
                        $result['held']++;
                        $result['errors'][]=array('sync_pk'=>$row['sync_pk'],'code'=>'instant_partial_history');
                    }
                } catch(Throwable $error) {
                    if($error->getMessage() === 'instant_history_time_bound') { $result['state']='time_bound'; return $result; }
                    if($error instanceof PintogyaRefreshDeferred) {
                        $store->sourceDeferred($row['sync_pk'], $error->retryAt, $clock());
                        $result['held']++; $result['errors'][]=array('sync_pk'=>$row['sync_pk'],'code'=>'auth_refresh_budget_wait');
                        continue;
                    }
                    self::uncertain($error);
                    $code = self::category($error);
                    $store->sourceFailed($row['sync_pk'], $code, $clock());
                    $result['held']++;
                    $result['errors'][]=array('sync_pk'=>$row['sync_pk'],'code'=>$code);
                }
            }
            if($owedFirst && $monotonic() < $deadline) { $discover(); }
            return $result;
        } finally { $store->release(); }
    }

    public static function retry($attempt, $now, $code) {
        if(!is_int($attempt) || $attempt < 1 || $attempt > self::MAX_ATTEMPTS || !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('instant_retry_invalid');
        }
        if(in_array($code, array('instant_read_session_expired','instant_read_permission_unavailable','binding_inactive',
            'instant_binding_changed','api_unauthorized'), true)) { return array('state'=>'blocked_auth','next'=>$now); }
        if(in_array($code, array('instant_partial_history','instant_journey_order_invalid','instant_stage_plan_changed',
            'instant_source_unavailable_or_changed','instant_history_page_invalid','instant_history_order_invalid',
            'instant_history_cursor_invalid','instant_history_checkpoint_invalid'), true)) {
            return array('state'=>'held_source','next'=>$now);
        }
        $minimum = $code === 'instant_source_rate_limited' ? 900 : 60;
        return array('state'=>$attempt === self::MAX_ATTEMPTS ? 'quarantined' : 'retry',
            'next'=>$now + min(3600, max($minimum, 60 * (1 << ($attempt - 1)))));
    }

    private static function pay($pk, $payments, $store, $now, &$result) {
        try {
            $paid = $payments->grant($pk, $now);
            if($paid['state'] === 'posted') { $result['posted']++; }
        } catch(Throwable $error) {
            self::uncertain($error);
            $code = self::category($error);
            $store->paymentFailed($pk, $code, $now);
            $result['errors'][]=array('reward_pk'=>$pk,'code'=>$code);
        }
    }

    private static function establish($candidate,$rule,$payments,$store,$pay,&$budget,&$attempted,$now,&$result) {
        $right = $payments->establish($candidate, $rule);
        if(($right['created'] ?? false) === true) { $result['established']++; }
        if($pay && $budget > 0 && $right['state'] === 'eligible' && !isset($attempted[$right['reward_pk']]) &&
            $store->canPay($right['reward_pk'], $now)) {
            self::pay($right['reward_pk'], $payments, $store, $now, $result); $budget--;
            $attempted[$right['reward_pk']]=true;
        }
        if($right['state'] === 'held_policy') { $result['held']++; }
    }

    private static function uncertain($error) {
        if(in_array($error->getMessage(), array('instant_commit_outcome_unknown','instant_rollback_outcome_unknown',
            'instant_worker_database_outcome_unknown'), true)) {
            // Caller must terminate this isolated worker/connection; never continue or blindly resend.
            throw new RuntimeException('instant_worker_database_outcome_unknown', 0, $error);
        }
    }

    private static function category($error) {
        $code = $error->getMessage();
        if($code === 'binding_changed') { return 'instant_binding_changed'; }
        $allowed = array('instant_read_session_expired','instant_read_permission_unavailable','binding_inactive',
            'instant_binding_changed','instant_partial_history','instant_journey_order_invalid','instant_stage_plan_changed',
            'instant_source_unavailable_or_changed','api_unauthorized','instant_rule_changed_or_held',
            'instant_forum_participation_missing','instant_activity_unpublished','instant_credit_unconfigured',
            'instant_reward_key_mismatch','instant_posted_entry_mismatch','credit_log_cardinality_invalid',
            'instant_binding_inactive','instant_history_page_invalid','instant_history_order_invalid',
            'instant_history_cursor_invalid','instant_history_checkpoint_invalid',
            'instant_entitlement_conflict','instant_credit_balance_mismatch');
        if(in_array($code,$allowed,true)) { return $code; }
        if(($code === 'api_http_error' && $error->getCode() >= 500 && $error->getCode() <= 599) ||
            in_array($code,array('api_transport_failed','api_transport_unavailable','api_deadline_exhausted'),true)) { return 'instant_source_temporary_failure'; }
        if(preg_match('/^api_http_429(?:_[0-9]+)?$/D',$code)) { return 'instant_source_rate_limited'; }
        if(preg_match('/^api_http_5[0-9]{2}(?:_[0-9]+)?$/D',$code)) { return 'instant_source_temporary_failure'; }
        return 'instant_worker_record_unknown';
    }
}

final class PintogyaActivityInstantPayments {
    private $site;
    public function __construct($site) { $this->site=$site; }
    public function establish($candidate,$rule) { return PintogyaActivityInstantSettlement::establish($this->site,$candidate,$rule); }
    public function grant($pk,$now) { return PintogyaActivityInstantSettlement::grant($this->site,$pk,$now); }
}

final class PintogyaActivityInstantSyncStore {
    private $lock;
    public function __construct($site) {
        PintogyaActivityInstantSettlement::assertScope($site);
        $table = DB::table('pintogya_activity_instant_sync');
        if(strtoupper((string)DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array($table))) !== 'INNODB') {
            throw new RuntimeException('instant_sync_schema_missing');
        }
        $this->lock='pintogya_instant_'.substr(hash('sha256',(string)DB::result_first('SELECT DATABASE()')),0,20);
    }
    public function acquire() { return (int)DB::result_first('SELECT GET_LOCK(%s,0)',array($this->lock)) === 1; }
    public function release() { try { DB::result_first('SELECT RELEASE_LOCK(%s)',array($this->lock)); } catch(Throwable $ignored) {} }

    public function seed($limit,$now) {
        $s=DB::table('pintogya_activity_instant_sync'); $p=DB::table('pintogya_participation');
        $a=DB::table('pintogya_activity'); $b=DB::table('pintogya_binding'); $r=DB::table('pintogya_activity_instant_rule');
        DB::query('INSERT IGNORE INTO '.$s.' (participant_pk,activity_id,uid,togya_user_id,binding_generation,history_json)'.
            " SELECT p.participant_pk,a.activity_id,p.uid,p.togya_user_id,p.binding_generation,'' FROM ".$p.' p'.
            ' JOIN '.$a.' a ON a.activity_pk=p.activity_pk JOIN '.$b.' b ON b.uid=p.uid'.
            ' JOIN '.$r.' r ON r.activity_id=a.activity_id LEFT JOIN '.$s.' s ON s.participant_pk=p.participant_pk'.
            " WHERE s.sync_pk IS NULL AND p.state_code='active' AND a.state_code='active' AND a.tid>0".
            " AND b.state_code='active' AND b.togya_user_id=p.togya_user_id AND b.generation=p.binding_generation".
            " AND r.state_code='active' AND r.published_at>0 ORDER BY p.participant_pk LIMIT ".(int)$limit);
        // Renewed legal local sessions may unblock the same identity; no remote refresh or identity swap.
        $rows=DB::fetch_all('SELECT s.sync_pk,b.generation FROM '.$s.' s JOIN '.$b.' b ON b.uid=s.uid'.
            " WHERE s.state_code='blocked_auth' AND b.state_code='active' AND b.togya_user_id=s.togya_user_id".
            ' AND b.generation=s.binding_generation AND b.updated_at>s.checked_at ORDER BY s.sync_pk LIMIT '.(int)$limit);
        foreach($rows as $row) { DB::update('pintogya_activity_instant_sync',array('state_code'=>'ready','retry_count'=>0,
            'next_attempt_at'=>$now,'last_error'=>''),'sync_pk='.(int)$row['sync_pk']." AND state_code='blocked_auth'"); }
        // TOGYA_PLUGIN: pintogya_instant_rate_limit_recovery -- renewal never revives uncertain payments or changed identities.
        $rows=DB::fetch_all('SELECT s.sync_pk FROM '.$s.' s JOIN '.$b.' b ON b.uid=s.uid'.
            " WHERE s.state_code='quarantined' AND s.last_error='instant_source_rate_limited'".
            " AND b.state_code='active' AND b.togya_user_id=s.togya_user_id AND b.generation=s.binding_generation".
            ' AND b.updated_at>s.checked_at AND s.next_attempt_at<=%d ORDER BY s.sync_pk LIMIT '.(int)$limit,array($now));
        foreach($rows as $row) {
            DB::query('UPDATE '.$s.' s JOIN '.$b.' b ON b.uid=s.uid'.
                " SET s.state_code='ready',s.retry_count=0,s.next_attempt_at=%d,s.last_error=''".
                " WHERE s.sync_pk=%d AND s.state_code='quarantined' AND s.last_error='instant_source_rate_limited'".
                " AND b.state_code='active' AND b.togya_user_id=s.togya_user_id AND b.generation=s.binding_generation".
                ' AND b.updated_at>s.checked_at AND s.next_attempt_at<=%d',array($now,(int)$row['sync_pk'],$now));
        }
        $owed=DB::fetch_all('SELECT r.reward_pk FROM '.DB::table('pintogya_activity_instant_reward').' r'.
            ' JOIN '.$b.' b ON b.uid=r.uid'.
            " WHERE r.state_code='held_auth' AND b.state_code='active' AND b.togya_user_id=r.togya_user_id".
            ' AND b.updated_at>r.updated_at ORDER BY r.reward_pk LIMIT '.(int)$limit);
        foreach($owed as $row) { DB::update('pintogya_activity_instant_reward',array('state_code'=>'eligible',
            'retry_count'=>0,'next_attempt_at'=>$now,'last_error'=>''),'reward_pk='.(int)$row['reward_pk']." AND state_code='held_auth'"); }
    }

    public function due($limit,$now) {
        $rows=DB::fetch_all('SELECT s.*,r.rule_json,r.rule_hash,'.
            " (b.state_code='active' AND b.togya_user_id=s.togya_user_id AND b.generation=s.binding_generation".
            ' AND MAX(w.reward_pk IS NOT NULL)) unpaid_due,'.
            ' (b.state_code=\'active\' AND b.togya_user_id=s.togya_user_id AND b.generation=s.binding_generation'.
            ' AND b.access_expires_at>%d) fresh_access FROM '.DB::table('pintogya_activity_instant_sync').' s'.
            ' JOIN '.DB::table('pintogya_activity_instant_rule').' r ON r.activity_id=s.activity_id'.
            ' LEFT JOIN '.DB::table('pintogya_binding').' b ON b.uid=s.uid'.
            ' LEFT JOIN '.DB::table('pintogya_activity_instant_reward').' w ON w.uid=s.uid'.
            " AND w.activity_id=s.activity_id AND w.togya_user_id=s.togya_user_id".
            " AND w.state_code='held_policy' AND w.credit_log_id IS NULL".
            " WHERE s.state_code IN ('ready','retry') AND r.state_code='active' AND s.next_attempt_at<=%d".
            ' GROUP BY s.sync_pk ORDER BY unpaid_due DESC,fresh_access DESC,s.checked_at,s.sync_pk LIMIT '.(int)$limit,array($now+30,$now));
        foreach($rows as &$row) {
            $row['rule']=PintogyaActivityInstantRule::assertSnapshot(json_decode($row['rule_json'],true,512,JSON_THROW_ON_ERROR));
            if(PintogyaCrypto::digest('pintogya_instant_rule_v3',$row['rule']) !== $row['rule_hash']) { throw new RuntimeException('instant_rule_corrupt'); }
            foreach(array('sync_pk','uid','binding_generation') as $key) { $row[$key]=(int)$row[$key]; }
            unset($row['rule_json'],$row['rule_hash']);
        }
        unset($row); return $rows;
    }
    public function payable($limit,$now) {
        $rows=DB::fetch_all('SELECT reward_pk FROM '.DB::table('pintogya_activity_instant_reward').
            " WHERE state_code='eligible' AND retry_count<10 AND next_attempt_at<=%d ORDER BY next_attempt_at,reward_pk LIMIT ".(int)$limit,array($now));
        return array_map('intval',array_column($rows,'reward_pk'));
    }
    public function canPay($pk,$now) {
        return (int)DB::result_first('SELECT COUNT(*) FROM '.DB::table('pintogya_activity_instant_reward').
            " WHERE reward_pk=%d AND state_code='eligible' AND retry_count<10 AND next_attempt_at<=%d",array($pk,$now)) === 1;
    }
    public function observed($pk,$now,$code,$history=null) {
        DB::update('pintogya_activity_instant_sync',array('state_code'=>'ready','retry_count'=>0,
            'checked_at'=>$now,'next_attempt_at'=>$now+60,'last_error'=>$code,
            'history_json'=>$history === null ? '' : json_encode($history,JSON_THROW_ON_ERROR)),'sync_pk='.(int)$pk);
    }
    public function sourceFailed($pk,$code,$now) {
        $row=DB::fetch_first('SELECT retry_count FROM '.DB::table('pintogya_activity_instant_sync').' WHERE sync_pk=%d',array($pk));
        if(!$row) { throw new RuntimeException('instant_sync_missing'); }
        $count=min(10,(int)$row['retry_count']+1); $retry=PintogyaActivityInstantWorker::retry($count,$now,$code);
        DB::update('pintogya_activity_instant_sync',array('state_code'=>$retry['state'],'retry_count'=>$count,
            'checked_at'=>$now,'next_attempt_at'=>$retry['next'],'last_error'=>$code),'sync_pk='.(int)$pk);
    }
    public function sourceDeferred($pk,$next,$now) {
        // Local scheduling is not a failed source attempt: preserve retry count, checked_at and history.
        DB::update('pintogya_activity_instant_sync',array('state_code'=>'retry',
            'next_attempt_at'=>max($now+1,(int)$next),'last_error'=>'auth_refresh_budget_wait'),
            'sync_pk='.(int)$pk." AND state_code IN ('ready','retry')");
    }
    public function paymentFailed($pk,$code,$now) {
        $row=DB::fetch_first('SELECT retry_count FROM '.DB::table('pintogya_activity_instant_reward').' WHERE reward_pk=%d',array($pk));
        if(!$row) { throw new RuntimeException('instant_reward_missing'); }
        if($code === 'instant_binding_inactive') {
            DB::update('pintogya_activity_instant_reward',array('state_code'=>'held_auth',
                'last_error'=>$code,'updated_at'=>$now),
                'reward_pk='.(int)$pk." AND state_code='eligible' AND credit_log_id IS NULL");
            return;
        }
        $count=min(10,(int)$row['retry_count']+1); $retry=PintogyaActivityInstantWorker::retry($count,$now,$code);
        DB::update('pintogya_activity_instant_reward',array('retry_count'=>$count,'next_attempt_at'=>$retry['next'],
            'state_code'=>$count === 10 ? 'quarantined' : 'eligible','last_error'=>$code,'updated_at'=>$now),
            'reward_pk='.(int)$pk." AND state_code='eligible' AND credit_log_id IS NULL");
    }
}
