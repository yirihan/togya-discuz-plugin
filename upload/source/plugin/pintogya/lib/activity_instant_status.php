<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/activity_instant_settlement.php';

final class PintogyaActivityInstantStatus {
    private $store;

    public function __construct($store = null) {
        if($store !== null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('instant_status_injection_forbidden');
        }
        $this->store = $store;
    }

    public function forMember($uid, $projection, $stored) {
        global $_G;
        if(!is_int($uid) || $uid < 1 || $uid > 16777215 || (int)($_G['uid'] ?? 0) !== $uid) {
            throw new RuntimeException('instant_status_member_required');
        }
        $rule = PintogyaActivityInstantRule::storedSnapshot($stored);
        if(($projection['activity']['id'] ?? null) !== $rule['activity_id'] ||
            !is_int($projection['activityPk'] ?? null) || $projection['activityPk'] < 1 ||
            ($stored['state_code'] ?? null) !== 'active' || (int)($stored['published_at'] ?? 0) < 1 ||
            (int)$stored['published_at'] !== ($projection['publishedAt'] ?? null)) {
            throw new RuntimeException('instant_status_rule_unavailable');
        }
        $credit = strip_tags((string)($_G['setting']['extcredits'][$rule['credit_id']]['title'] ?? ''));
        if($credit === '' || strlen($credit) > 120) { throw new RuntimeException('instant_credit_unconfigured'); }
        $store = $this->store ?? new PintogyaActivityInstantStatusStore();
        $participant = $store->participant((int)$projection['activityPk'], $uid);
        $rows = $store->rewards($rule['activity_id'], $uid);
        if(!is_array($rows) || count($rows) > 101) { throw new RuntimeException('instant_status_rows_invalid'); }
        $indexed = array();
        foreach($rows as $row) {
            if(!is_array($row) || (int)($row['uid'] ?? 0) !== $uid || ($row['activity_id'] ?? null) !== $rule['activity_id'] ||
                !in_array($row['kind'] ?? null, array('stage','base'), true)) {
                throw new RuntimeException('instant_status_rows_invalid');
            }
            $key = $row['kind'] === 'base' ? 'base' : 'stage:'.PintogyaCrypto::assertUuid($row['stage_id'] ?? null);
            if(isset($indexed[$key])) { throw new RuntimeException('instant_status_rows_invalid'); }
            $indexed[$key] = $row;
        }
        $binding = $store->binding($uid);
        $sync = $store->sync($rule['activity_id'], $uid);
        $checked = $sync ? self::integer($sync['checked_at'] ?? null, 0, 4294967295) : 0;
        $source = 'waiting_sync';
        if(!$participant || ($participant['state_code'] ?? null) !== 'active') { $source = 'not_participating'; }
        elseif(!$binding || ($binding['togya_user_id'] ?? null) !== ($participant['togya_user_id'] ?? null) ||
            ((int)($binding['access_expires_at'] ?? 0) <= time()+30 && empty($binding['refresh_ciphertext']))) { $source = 'waiting_access'; }
        elseif($sync) {
            if(($sync['activity_id'] ?? null) !== $rule['activity_id'] || (int)($sync['uid'] ?? 0) !== $uid ||
                ($sync['togya_user_id'] ?? null) !== ($participant['togya_user_id'] ?? null)) {
                throw new RuntimeException('instant_status_sync_invalid');
            }
            if($sync['state_code'] === 'blocked_auth') { $source = 'waiting_access'; }
            elseif(in_array($sync['state_code'], array('held_source','quarantined'), true)) { $source = 'blocked'; }
            elseif($sync['state_code'] === 'ready' && $checked >= time()-120 && $checked <= time() &&
                (int)($sync['binding_generation'] ?? 0) === (int)($binding['generation'] ?? -1) &&
                ($sync['last_error'] ?? '') === '') { $source = 'synced'; }
            elseif(!in_array($sync['state_code'], array('ready','retry'), true)) { throw new RuntimeException('instant_status_sync_invalid'); }
        }
        $stages = array_values($rule['stages']);
        usort($stages, static function($a,$b) { return $a['position'] <=> $b['position']; });
        $items = array();
        foreach($stages as $stage) {
            if($stage['kind'] !== 'tutorial') { continue; }
            $items[] = $this->item($store, $uid, $participant, $rule, $stored['rule_hash'], 'stage',
                $stage['id'], $stage['title'], $stage['amount'], $indexed['stage:'.$stage['id']] ?? null);
            unset($indexed['stage:'.$stage['id']]);
        }
        $items[] = $this->item($store, $uid, $participant, $rule, $stored['rule_hash'], 'base',
            null, '', $rule['base'], $indexed['base'] ?? null);
        unset($indexed['base']);
        if($indexed) { throw new RuntimeException('instant_status_rows_invalid'); }
        return array('model'=>'instant','creditId'=>$rule['credit_id'],'credit'=>$credit,
            'sourceState'=>$source,'checkedAt'=>$checked,'items'=>$items);
    }

    private function item($store, $uid, $participant, $rule, $ruleHash, $kind, $stageId, $title, $amount, $row) {
        $result = array('kind'=>$kind,'stageId'=>$stageId,'title'=>$title,'amount'=>$amount,
            'state'=>$amount === 0 ? 'no_reward' : 'waiting_sync','paidAt'=>null,'creditLogId'=>null);
        if($kind === 'base' && $rule['finish_stage_id'] === null) { $result['state']='unavailable'; }
        if(!$row) { return $result; }
        try {
            if(($kind === 'base' && $rule['finish_stage_id'] === null) || !$participant || (int)$participant['uid'] !== $uid ||
                ($participant['togya_user_id'] ?? null) !== ($row['togya_user_id'] ?? null) ||
                ($row['stage_id'] ?? null) !== ($stageId ?? '') || ($row['rule_hash'] ?? null) !== $ruleHash ||
                self::integer($row['amount'] ?? null,0,2147483647) !== $amount ||
                self::integer($row['credit_id'] ?? null,1,8) !== $rule['credit_id'] ||
                !hash_equals(PintogyaActivityInstantRule::rewardKey($store->site(),$rule['activity_id'],
                    $row['togya_user_id'],$kind,$stageId),(string)($row['reward_key'] ?? ''))) {
                throw new RuntimeException('instant_status_row_mismatch');
            }
            $state = $row['state_code'] ?? null;
            if($state === 'posted') {
                if($amount < 1 || !is_string($row['native_entry_json'] ?? null) ||
                    strlen($row['native_entry_json']) > 16384) { throw new RuntimeException('instant_status_posted_invalid'); }
                $store->verifyPosted($row);
                $result['paidAt']=self::integer($row['posted_at'] ?? null,1,4294967295);
                $result['creditLogId']=self::integer($row['credit_log_id'] ?? null,1,4294967295);
                $result['state']='paid';
            } else {
                if(($row['credit_log_id'] ?? null) !== null || (int)($row['posted_at'] ?? 0) !== 0) {
                    throw new RuntimeException('instant_status_unposted_invalid');
                }
                $states=array('eligible'=>'pending','held_policy'=>'waiting_policy','held_auth'=>'waiting_access',
                    'quarantined'=>'blocked','no_reward'=>'no_reward');
                if(!isset($states[$state]) || ($state === 'no_reward' && $amount !== 0) ||
                    ($state === 'eligible' && $amount === 0)) {
                    throw new RuntimeException('instant_status_state_invalid');
                }
                $result['state']=$states[$state];
            }
        } catch(Throwable $error) {
            $result['state']='unavailable'; $result['paidAt']=null; $result['creditLogId']=null;
        }
        return $result;
    }

    private static function integer($value,$min,$max) {
        if(is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value)) { $value=(int)$value; }
        if(!is_int($value) || $value < $min || $value > $max) { throw new RuntimeException('instant_status_number_invalid'); }
        return $value;
    }
}

final class PintogyaActivityInstantStatusStore {
    public function __construct() {
        $tables=array_map(array('DB','table'),array('pintogya_activity_instant_reward','pintogya_activity_instant_sync'));
        $rows=DB::fetch_all('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$tables);
        if(count($rows)!==2) { throw new RuntimeException('instant_status_schema_unavailable'); }
        foreach($rows as $row) { if(strtoupper((string)$row['ENGINE'])!=='INNODB') { throw new RuntimeException('instant_status_schema_unavailable'); } }
        $this->site();
    }
    public function site() {
        return PintogyaInstallation::scope();
    }
    public function participant($pk,$uid) { return (new PintogyaRepository())->participationForUid($pk,$uid); }
    public function binding($uid) { return (new PintogyaRepository())->activeBindingForUid($uid); }
    public function rewards($id,$uid) {
        return DB::fetch_all('SELECT * FROM '.DB::table('pintogya_activity_instant_reward').
            ' WHERE activity_id=%s AND uid=%d ORDER BY reward_pk LIMIT 102',array($id,$uid));
    }
    public function sync($id,$uid) {
        $rows=DB::fetch_all('SELECT * FROM '.DB::table('pintogya_activity_instant_sync').
            ' WHERE activity_id=%s AND uid=%d ORDER BY sync_pk LIMIT 2',array($id,$uid));
        if(count($rows)>1) { throw new RuntimeException('instant_status_sync_invalid'); }
        return $rows ? $rows[0] : null;
    }
    public function verifyPosted($row) { PintogyaActivityInstantSettlement::verifyPostedReadOnly($row); }
}
