<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/refresh_budget.php';

final class PintogyaActivityInstantDiscovery {
    const CURSOR_KEY = 'pintogya_instant_discovery_cursor';

    public static function run($site, $limit, $deadline, $clock, $monotonic, $store = null, $join = null) {
        global $_G;
        if(PHP_SAPI !== 'cli' || !PintogyaInstallation::validScope($site) ||
            !is_int($limit) || $limit < 1 || $limit > 2 || !is_callable($clock) || !is_callable($monotonic) ||
            (!is_float($deadline) && !is_int($deadline)) || !is_finite((float)$deadline) || $deadline <= 0) {
            throw new InvalidArgumentException('instant_discovery_arguments_invalid');
        }
        PintogyaInstallation::assertScope($site);
        if(($store !== null || $join !== null) && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('instant_discovery_injection_forbidden');
        }
        $result = array('state'=>'observed','scanned'=>0,'confirmed'=>0,'not_joined'=>0,'held'=>0,'errors'=>array());
        if((string)($_G['setting'][PintogyaActivityInstantRule::SYNC_ENABLED_KEY] ?? '') !== '1') {
            $result['state']='disabled'; return $result;
        }
        if(function_exists('loadcache')) { loadcache('plugin'); }
        if((string)($_G['cache']['plugin']['pintogya']['enabled'] ?? '') !== '1') {
            $result['state']='disabled'; return $result;
        }
        $store = $store ?? new PintogyaActivityInstantDiscoveryStore();
        if(!$store->available()) { $result['state']='awaiting_schema'; return $result; }
        if($monotonic() >= $deadline) { $result['state']='time_bound'; return $result; }
        if(!$store->acquire()) { $result['state']='locked'; return $result; }
        try {
        $store->seed(20, $clock());
        foreach($store->due($limit, $clock()) as $row) {
            if($monotonic() >= $deadline) { $result['state']='time_bound'; break; }
            $result['scanned']++;
            try {
                if(PintogyaCrypto::assertUuid($row['togya_user_id']) !== $row['togya_user_id'] ||
                    PintogyaCrypto::assertUuid($row['activity_id']) !== $row['activity_id']) {
                    throw new RuntimeException('instant_discovery_source_invalid');
                }
                if($join === null) {
                    require_once __DIR__.'/pintogya.php';
                    if(function_exists('loadcache')) { loadcache('plugin'); }
                    $services = pintogya_services(null,null,null,$deadline);
                    $join = $services['activity_join'];
                }
                // TOGYA_PLUGIN: pintogya_bound_membership_discovery -- no browser, QR approval, or remote join is fabricated.
                $viewer = $join->viewerState($row['uid'],$row['tid'],'en-US',$clock(),
                    array('uid'=>$row['uid'],'togya_user_id'=>$row['togya_user_id'],'generation'=>$row['binding_generation']));
                if(!is_array($viewer) || !is_bool($viewer['forumJoined'] ?? null) ||
                    !in_array($viewer['state'] ?? null,array('joined','not_joined'),true) ||
                    ($viewer['state'] === 'not_joined' && $viewer['forumJoined'])) {
                    throw new RuntimeException('instant_discovery_source_invalid');
                }
                if($viewer['state'] === 'joined' && !$viewer['forumJoined']) {
                    throw new RuntimeException('instant_discovery_confirmation_missing');
                }
                $store->observed($row,$viewer['forumJoined'],$clock());
                $result[$viewer['forumJoined'] ? 'confirmed' : 'not_joined']++;
            } catch(Throwable $error) {
                if($error instanceof PintogyaRefreshDeferred) {
                    $store->deferred($row,$error->retryAt,$clock());
                    $result['held']++; $result['errors'][]='auth_refresh_budget_wait';
                    continue;
                }
                $code = self::category($error);
                $store->failed($row,$code,$clock());
                $result['held']++;
                $result['errors'][]=$code;
            }
        }
        return $result;
        } finally { $store->release(); }
    }

    public static function retry($attempt,$now,$code) {
        if(!is_int($attempt) || $attempt < 1 || $attempt > 10 || !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('instant_discovery_retry_invalid');
        }
        if($code === 'instant_discovery_binding_unavailable') { return array('state'=>'blocked_auth','next'=>$now); }
        if($code === 'instant_discovery_source_invalid' || $code === 'instant_discovery_confirmation_missing') {
            return array('state'=>'held_source','next'=>$now);
        }
        $minimum = $code === 'instant_discovery_rate_limited' ? 900 : 60;
        return array('state'=>$attempt === 10 ? 'quarantined' : 'retry',
            'next'=>$now + min(3600,max($minimum,60 * (1 << ($attempt - 1)))));
    }

    private static function category($error) {
        $code = $error->getMessage();
        if(in_array($code,array('binding_inactive','binding_changed','api_unauthorized','binding_token_unavailable',
            'refresh_session_invalid','activity_join_binding_changed'),true)) { return 'instant_discovery_binding_unavailable'; }
        if(in_array($code,array('invalid_uuid','activity_viewer_response_invalid','activity_viewer_stages_invalid',
            'activity_join_identity_changed','activity_join_identity_in_use','activity_join_revoked',
            'activity_join_thread_changed','instant_rule_publication_changed','instant_discovery_source_invalid',
            'instant_discovery_identity_changed'),true)) { return 'instant_discovery_source_invalid'; }
        if($code === 'instant_discovery_confirmation_missing') { return $code; }
        if(preg_match('/^api_http_429(?:_[0-9]+)?$/D',$code)) { return 'instant_discovery_rate_limited'; }
        if(preg_match('/^api_http_5[0-9]{2}(?:_[0-9]+)?$/D',$code) ||
            in_array($code,array('api_transport_failed','api_deadline_exhausted'),true)) {
            return 'instant_discovery_temporary_failure';
        }
        return 'instant_discovery_record_unknown';
    }
}

final class PintogyaActivityInstantDiscoveryStore {
    private $lock;
    public function acquire() {
        $database=(string)DB::result_first('SELECT DATABASE()');
        if($database === '') { throw new RuntimeException('instant_discovery_database_missing'); }
        $this->lock='pintogya_discovery_'.substr(hash('sha256',$database),0,20);
        return (int)DB::result_first('SELECT GET_LOCK(%s,0)',array($this->lock)) === 1;
    }
    public function release() {
        if($this->lock !== null) { DB::result_first('SELECT RELEASE_LOCK(%s)',array($this->lock)); }
    }
    public function available() {
        return strtoupper((string)DB::result_first('SELECT ENGINE FROM information_schema.TABLES'.
            ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array(DB::table('pintogya_activity_membership_sync')))) === 'INNODB';
    }

    public function seed($limit,$now) {
        if(!is_int($limit) || $limit < 1 || $limit > 20 || !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('instant_discovery_seed_invalid');
        }
        $raw=DB::result_first('SELECT svalue FROM '.DB::table('common_setting').' WHERE skey=%s',
            array(PintogyaActivityInstantDiscovery::CURSOR_KEY));
        $cursor=$raw === null || $raw === false || $raw === '' ? array(0,0) : json_decode((string)$raw,true);
        if(!is_array($cursor) || array_keys($cursor) !== array(0,1) || !is_int($cursor[0]) ||
            !is_int($cursor[1]) || $cursor[0] < 0 || $cursor[1] < 0) { throw new RuntimeException('instant_discovery_cursor_invalid'); }
        $activity=$this->activity($cursor[0]);
        if(!$activity && $cursor[0] > 0) { $cursor=array(0,0); $activity=$this->activity(0); }
        if(!$activity) { return; }
        if((int)$activity['activity_pk'] !== $cursor[0]) { $cursor=array((int)$activity['activity_pk'],0); }
        $rows=DB::fetch_all('SELECT b.uid,b.togya_user_id,b.generation,b.updated_at FROM '.DB::table('pintogya_binding').' b'.
            ' JOIN '.DB::table('common_member').' m ON m.uid=b.uid'.
            " WHERE b.state_code='active' AND b.uid>%d ORDER BY b.uid LIMIT ".(int)$limit,array($cursor[1]));
        foreach($rows as $binding) {
            $uid=(int)$binding['uid'];
            $participant=DB::fetch_first('SELECT togya_user_id,binding_generation,stage_version,state_code FROM '.
                DB::table('pintogya_participation').' WHERE activity_pk=%d AND uid=%d',array($cursor[0],$uid));
            if($participant && ($participant['state_code'] === 'revoked' ||
                !hash_equals((string)$participant['togya_user_id'],(string)$binding['togya_user_id']))) { continue; }
            if($participant && $participant['state_code'] === 'active' &&
                (int)$participant['binding_generation'] === (int)$binding['generation'] &&
                (int)$participant['stage_version'] === (int)$activity['stage_version']) { continue; }
            $existing=DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_membership_sync').
                ' WHERE activity_id=%s AND uid=%d',array($activity['activity_id'],$uid));
            if(!$existing) {
                DB::insert('pintogya_activity_membership_sync',array('activity_id'=>$activity['activity_id'],'uid'=>$uid,
                    'togya_user_id'=>$binding['togya_user_id'],'binding_generation'=>(int)$binding['generation'],
                    'updated_at'=>$now));
            } elseif($existing['state_code'] === 'quarantined' &&
                ($existing['last_error'] ?? '') === 'instant_discovery_rate_limited' &&
                hash_equals((string)$existing['togya_user_id'],(string)$binding['togya_user_id']) &&
                (int)$existing['binding_generation'] === (int)$binding['generation'] &&
                (int)$binding['updated_at'] > (int)$existing['checked_at'] && (int)$existing['next_attempt_at'] <= $now) {
                // Recheck renewal, identity and backoff atomically; cursor wrap alone cannot release quarantine.
                DB::query('UPDATE '.DB::table('pintogya_activity_membership_sync').' d'.
                    ' JOIN '.DB::table('pintogya_binding').' b ON b.uid=d.uid'.
                    " SET d.state_code='ready',d.retry_count=0,d.next_attempt_at=%d,d.last_error='',d.updated_at=%d".
                    " WHERE d.sync_pk=%d AND d.state_code='quarantined' AND d.last_error='instant_discovery_rate_limited'".
                    " AND b.state_code='active' AND b.togya_user_id=d.togya_user_id AND b.generation=d.binding_generation".
                    ' AND b.updated_at>d.checked_at AND d.next_attempt_at<=%d',array($now,$now,(int)$existing['sync_pk'],$now));
            } elseif(hash_equals((string)$existing['togya_user_id'],(string)$binding['togya_user_id']) &&
                ($existing['state_code'] === 'joined' ||
                    ($existing['state_code'] === 'blocked_auth' && (int)$binding['updated_at'] > (int)$existing['checked_at']) ||
                    (in_array($existing['state_code'],array('ready','retry'),true) &&
                        (int)$existing['binding_generation'] !== (int)$binding['generation']))) {
                DB::update('pintogya_activity_membership_sync',array('state_code'=>'ready','retry_count'=>0,
                    'binding_generation'=>(int)$binding['generation'],'next_attempt_at'=>$now,'last_error'=>'','updated_at'=>$now),
                    'sync_pk='.(int)$existing['sync_pk']);
            }
        }
        $last=$rows ? end($rows) : null;
        $next=count($rows) === $limit ? array($cursor[0],(int)$last['uid']) : array($cursor[0]+1,0);
        DB::insert('common_setting',array('skey'=>PintogyaActivityInstantDiscovery::CURSOR_KEY,
            'svalue'=>json_encode($next,JSON_THROW_ON_ERROR)),false,true);
    }

    private function activity($pk) {
        return DB::fetch_first('SELECT a.activity_pk,a.activity_id,a.tid,a.stage_version FROM '.DB::table('pintogya_activity').' a'.
            ' JOIN '.DB::table('pintogya_activity_instant_rule').' r ON r.activity_id=a.activity_id'.
            " WHERE a.state_code='active' AND a.tid>0 AND r.state_code='active' AND r.published_at>0".
            ' AND a.activity_pk>=%d ORDER BY a.activity_pk LIMIT 1',array($pk));
    }

    public function due($limit,$now,$cache=null) {
        if(!is_int($limit) || $limit < 1 || $limit > 2 || !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('instant_discovery_batch_invalid');
        }
        if($cache!==null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST===true)) {
            throw new RuntimeException('instant_discovery_injection_forbidden');
        }
        $sql='SELECT d.*,a.tid FROM '.DB::table('pintogya_activity_membership_sync').' d'.
            ' JOIN '.DB::table('pintogya_binding').' b ON b.uid=d.uid'.
            ' JOIN '.DB::table('common_member').' m ON m.uid=d.uid'.
            ' JOIN '.DB::table('pintogya_activity').' a ON a.activity_id=d.activity_id'.
            ' JOIN '.DB::table('pintogya_activity_instant_rule').' r ON r.activity_id=d.activity_id'.
            ' LEFT JOIN '.DB::table('pintogya_participation').' p ON p.activity_pk=a.activity_pk AND p.uid=d.uid'.
            " WHERE d.state_code IN ('ready','retry') AND d.next_attempt_at<=%d AND b.state_code='active'".
            ' AND b.togya_user_id=d.togya_user_id AND b.generation=d.binding_generation'.
            " AND a.state_code='active' AND a.tid>0 AND r.state_code='active' AND r.published_at>0".
            " AND (p.participant_pk IS NULL OR (p.state_code<>'revoked' AND p.togya_user_id=b.togya_user_id".
            " AND (p.state_code<>'active' OR p.binding_generation<>b.generation OR p.stage_version<>a.stage_version)))";
        $hints=array();
        try { $hints=$this->cachedAuthorHints($cache); }
        catch(Throwable $error) { /* Missing local content leaves the existing bounded discovery queue intact. */ }
        $rows=array();$order=' ORDER BY d.next_attempt_at,d.sync_pk LIMIT ';
        if($hints) {
            $where=array();$args=array($now);
            foreach($hints as $activity=>$subjects) {
                $where[]='(d.activity_id=%s AND b.togya_user_id IN (%n))';$args[]=$activity;$args[]=$subjects;
            }
            // TOGYA_PLUGIN pintogya_bound_checkin_priority: cached authors are hints, never joined authority.
            $rows=DB::fetch_all($sql.' AND d.checked_at=0 AND ('.implode(' OR ',$where).')'.$order.(int)$limit,$args);
        }
        if(count($rows)<$limit) {
            $args=array($now);$exclude='';
            if($rows) { $exclude=' AND d.sync_pk NOT IN (%n)';$args[]=array_column($rows,'sync_pk'); }
            $rows=array_merge($rows,DB::fetch_all($sql.$exclude.$order.($limit-count($rows)),$args));
        }
        foreach($rows as &$row) { foreach(array('sync_pk','uid','tid','binding_generation','retry_count') as $key) { $row[$key]=(int)$row[$key]; } }
        unset($row); return $rows;
    }

    private function cachedAuthorHints($cache) {
        require_once __DIR__.'/display_cache.php';
        $cache=$cache ?? new PintogyaDisplayCache();$hints=array();
        $activities=DB::fetch_all('SELECT a.activity_id,a.stage_version FROM '.DB::table('pintogya_activity').' a'.
            ' JOIN '.DB::table('pintogya_activity_instant_rule').' r ON r.activity_id=a.activity_id'.
            " WHERE a.state_code='active' AND a.tid>0 AND r.state_code='active' AND r.published_at>0 ORDER BY a.activity_pk LIMIT 20");
        foreach($activities as $activity) {
            $id=$activity['activity_id'];$version=(int)$activity['stage_version'];
            $entry=$cache->peek(PintogyaDisplayCache::request('public_detail',$id));$detail=$entry['data']??null;
            if(!$detail||($detail['id']??null)!==$id||($detail['isPublic']??null)!==true||($detail['stageVersion']??null)!==$version)continue;
            foreach($detail['stageDetails']??array() as $stage) {
                if(($stage['position']??null)!==0||($stage['canViewFeed']??null)!==true)continue;
                $entry=$cache->peek(PintogyaDisplayCache::request('public_feed',$id,'en-US',$version,$stage['id']));$feed=$entry['data']??null;
                if(!$feed||($feed['activityId']??null)!==$id||($feed['stageId']??null)!==$stage['id']||($feed['stageVersion']??null)!==$version)continue;
                foreach(array_slice($feed['items']??array(),0,20) as $item) {
                    if(($item['isSameActivity']??null)!==true||($item['activityId']??null)!==$id||
                        ($item['stageId']??null)!==$stage['id']||($item['stageVersion']??null)!==$version)continue;
                    try { $subject=PintogyaCrypto::assertUuid($item['author']['id']??null); }
                    catch(Throwable $error) { continue; }
                    $hints[$id][$subject]=$subject;
                }
            }
        }
        return array_map('array_values',$hints);
    }

    public function observed($row,$joined,$now) {
        $this->assertCurrent($row);
        DB::update('pintogya_activity_membership_sync',array('state_code'=>$joined ? 'joined' : 'ready','retry_count'=>0,
            'checked_at'=>$now,'next_attempt_at'=>$joined ? 0 : $now+300,'last_error'=>'','updated_at'=>$now),
            'sync_pk='.(int)$row['sync_pk']);
    }

    public function failed($row,$code,$now) {
        $attempt=min(10,$row['retry_count']+1); $retry=PintogyaActivityInstantDiscovery::retry($attempt,$now,$code);
        DB::update('pintogya_activity_membership_sync',array('state_code'=>$retry['state'],'retry_count'=>$attempt,
            'checked_at'=>$now,'next_attempt_at'=>$retry['next'],'last_error'=>$code,'updated_at'=>$now),
            'sync_pk='.(int)$row['sync_pk']);
    }

    public function deferred($row,$next,$now) {
        DB::update('pintogya_activity_membership_sync',array('state_code'=>'retry',
            'next_attempt_at'=>max($now+1,(int)$next),'last_error'=>'auth_refresh_budget_wait','updated_at'=>$now),
            'sync_pk='.(int)$row['sync_pk']." AND state_code IN ('ready','retry')");
    }

    private function assertCurrent($row) {
        $binding=DB::fetch_first('SELECT togya_user_id,generation FROM '.DB::table('pintogya_binding').
            " WHERE uid=%d AND state_code='active'",array($row['uid']));
        if(!$binding || !hash_equals((string)$binding['togya_user_id'],(string)$row['togya_user_id']) ||
            (int)$binding['generation'] !== $row['binding_generation']) {
            throw new RuntimeException('instant_discovery_identity_changed');
        }
    }
}
