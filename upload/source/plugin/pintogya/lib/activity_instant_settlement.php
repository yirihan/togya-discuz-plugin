<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/repository.php';
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/activity_native_credit_entry.php';

final class PintogyaActivityInstantSettlement {
    public static function establish($site, $candidate, $rule) {
        self::assertSite($site);
        $rule = PintogyaActivityInstantRule::assertSnapshot($rule);
        self::candidate($candidate, $rule);
        $key = PintogyaActivityInstantRule::rewardKey($site, $candidate['activity_id'],
            $candidate['togya_user_id'], $candidate['kind'], $candidate['reward_stage_id']);
        return self::transaction($candidate['uid'], false, static function() use ($candidate,$rule,$key) {
            $binding = (new PintogyaRepository())->activeBindingForUid($candidate['uid'], true);
            if(!$binding || $binding['togya_user_id'] !== $candidate['togya_user_id'] ||
                (int)$binding['generation'] !== $candidate['binding_generation']) { throw new RuntimeException('instant_binding_changed'); }
            $mapping = DB::fetch_first('SELECT activity_pk,state_code,tid FROM '.DB::table('pintogya_activity').' WHERE activity_id=%s LIMIT 1 FOR UPDATE', array($candidate['activity_id']));
            if(!$mapping || $mapping['state_code'] !== 'active' || (int)$mapping['tid'] < 1) {
                throw new RuntimeException('instant_activity_unpublished');
            }
            $participant = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_participation').
                ' WHERE activity_pk=%d AND uid=%d LIMIT 1 FOR UPDATE', array((int)$mapping['activity_pk'], $candidate['uid']));
            if(!$participant || $participant['state_code'] !== 'active' ||
                $participant['togya_user_id'] !== $candidate['togya_user_id'] ||
                (int)$participant['binding_generation'] !== $candidate['binding_generation']) {
                throw new RuntimeException('instant_forum_participation_missing');
            }
            $stored = self::storedRule($candidate['activity_id']);
            $hash = PintogyaCrypto::digest('pintogya_instant_rule_v3', $rule);
            if($stored['rule_hash'] !== $hash || $stored['state_code'] !== 'active' ||
                (int)$stored['published_at'] < 1 || (int)$stored['published_at'] > $candidate['observed_at']) {
                throw new RuntimeException('instant_rule_changed_or_held');
            }
            $existing = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_reward').
                ' WHERE reward_key=%s LIMIT 1 FOR UPDATE', array($key));
            if($existing) {
                if((int)$existing['uid'] !== $candidate['uid'] || $existing['togya_user_id'] !== $candidate['togya_user_id'] ||
                    $existing['rule_hash'] !== $hash || (int)$existing['amount'] !== $candidate['amount'] ||
                    (int)$existing['credit_id'] !== $candidate['credit_id']) { throw new RuntimeException('instant_entitlement_conflict'); }
                // Release legacy time holds only after the same first fact is formally revalidated.
                if($existing['state_code'] === 'held_policy') {
                    $fact = json_decode($existing['fact_json'], true, 512, JSON_THROW_ON_ERROR);
                    if($existing['credit_log_id'] !== null || (int)$existing['posted_at'] !== 0 ||
                        $existing['native_entry_json'] !== '' || !is_array($fact) ||
                        PintogyaCrypto::canonicalJson($fact) !== PintogyaCrypto::canonicalJson($candidate['fact']) ||
                        (int)$existing['occurred_at'] !== $candidate['fact']['occurred_at']) {
                        throw new RuntimeException('instant_held_fact_conflict');
                    }
                    $state = $candidate['amount'] === 0 ? 'no_reward' : 'eligible';
                    DB::update('pintogya_activity_instant_reward', array('state_code'=>$state,
                        'retry_count'=>0,'next_attempt_at'=>0,'last_error'=>'','updated_at'=>$candidate['observed_at']),
                        'reward_pk='.(int)$existing['reward_pk']." AND state_code='held_policy' AND credit_log_id IS NULL");
                    if(DB::affected_rows() !== 1) { throw new RuntimeException('instant_held_release_failed'); }
                    $existing['state_code'] = $state;
                }
                return array('state'=>$existing['state_code'],'reward_pk'=>(int)$existing['reward_pk'],'created'=>false);
            }
            // pintogya_instant_no_time_gate: valid first facts do not depend on forum chronology.
            $state = $candidate['amount'] === 0 ? 'no_reward' : 'eligible';
            $row = array('reward_key'=>$key,'activity_id'=>$candidate['activity_id'],'togya_user_id'=>$candidate['togya_user_id'],
                'uid'=>$candidate['uid'],'binding_generation'=>$candidate['binding_generation'],'kind'=>$candidate['kind'],
                'stage_id'=>$candidate['reward_stage_id'] ?? '', 'credit_id'=>$candidate['credit_id'],'amount'=>$candidate['amount'],
                'rule_hash'=>$hash,'fact_json'=>PintogyaCrypto::canonicalJson($candidate['fact']),
                'occurred_at'=>$candidate['fact']['occurred_at'],'observed_at'=>$candidate['observed_at'],
                'state_code'=>$state,'native_entry_json'=>'','updated_at'=>$candidate['observed_at']);
            $pk = DB::insert('pintogya_activity_instant_reward', $row, true);
            if(!is_int($pk) || $pk < 1) { throw new RuntimeException('instant_entitlement_insert_failed'); }
            return array('state'=>$state,'reward_pk'=>$pk,'created'=>true);
        });
    }

    public static function grant($site, $rewardPk, $now) {
        global $_G;
        self::assertSite($site);
        if((string)($_G['setting'][PintogyaActivityInstantRule::ENABLED_KEY] ?? '') !== '1') {
            throw new RuntimeException('instant_settlement_disabled');
        }
        self::integer($rewardPk, 1, 4294967295); self::integer($now, 1, 4294967295);
        $observed = DB::fetch_first('SELECT uid FROM '.DB::table('pintogya_activity_instant_reward').' WHERE reward_pk=%d', array($rewardPk));
        if(!$observed) { throw new RuntimeException('instant_reward_missing'); }
        $uid = (int)$observed['uid']; self::integer($uid, 1, 16777215);
        return self::transaction($uid, true, static function() use ($site,$rewardPk,$now,$uid) {
            global $_G;
            $row = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_reward').
                ' WHERE reward_pk=%d LIMIT 1 FOR UPDATE', array($rewardPk));
            if(!$row || (int)$row['uid'] !== $uid) { throw new RuntimeException('instant_reward_missing'); }
            $expectedKey = PintogyaActivityInstantRule::rewardKey($site, $row['activity_id'],
                $row['togya_user_id'], $row['kind'], $row['kind'] === 'stage' ? $row['stage_id'] : null);
            if(!hash_equals($expectedKey, $row['reward_key'])) { throw new RuntimeException('instant_reward_key_mismatch'); }
            if($row['state_code'] === 'posted') {
                self::verifyPostedReadOnly($row);
                return array('state'=>'already_posted','reward_pk'=>$rewardPk,'credit_log_id'=>(int)$row['credit_log_id']);
            }
            if($row['state_code'] !== 'eligible') { return array('state'=>$row['state_code'],'reward_pk'=>$rewardPk); }
            $binding=(new PintogyaRepository())->activeBindingForUid($uid,true);
            if(!$binding || $binding['togya_user_id'] !== $row['togya_user_id']) {
                throw new RuntimeException('instant_binding_inactive');
            }
            $stored = self::storedRule($row['activity_id']);
            if($stored['rule_hash'] !== $row['rule_hash']) { throw new RuntimeException('instant_rule_changed_or_held'); }
            $rule = json_decode($stored['rule_json'], true, 512, JSON_THROW_ON_ERROR);
            self::rowMatchesRule($row, $rule);
            $credit = (int)$row['credit_id']; $amount = (int)$row['amount'];
            if(!isset($_G['setting']['extcredits'][$credit])) { throw new RuntimeException('instant_credit_unconfigured'); }
            $balances = DB::fetch_first('SELECT * FROM '.DB::table('common_member_count').' WHERE uid=%d LIMIT 1 FOR UPDATE', array($uid));
            if(!$balances) { throw new RuntimeException('instant_credit_account_missing'); }
            $values = array();
            foreach(range(1,8) as $i) { $values['extcredits'.$i] = self::sqlInteger($balances['extcredits'.$i] ?? null); }
            $entry = PintogyaActivityNativeCreditEntry::build($uid, $credit, $amount, $rewardPk, $now, $values,
                array('activity_id'=>$row['activity_id'],'kind'=>$row['kind'],
                    'stage_id'=>$row['kind'] === 'stage' ? $row['stage_id'] : null));
            DB::update('common_member_count', array('extcredits'.$credit=>$entry['balance_after']), 'uid='.$uid);
            if(DB::affected_rows() !== 1) { throw new RuntimeException('instant_credit_update_failed'); }
            $logId = DB::insert('common_credit_log', $entry['main'], true);
            self::integer($logId, 1, 4294967295);
            $field = $entry['field']; $field['logid'] = $logId;
            DB::insert('common_credit_log_field', $field);
            PintogyaActivityNativeCreditEntry::verifyExactRows($entry, $logId,
                DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log').' WHERE logid=%d LIMIT 2', array($logId)),
                DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log_field').' WHERE logid=%d LIMIT 2', array($logId)));
            $balance = DB::result_first('SELECT extcredits'.$credit.' FROM '.DB::table('common_member_count').' WHERE uid=%d', array($uid));
            if(self::sqlInteger($balance) !== $entry['balance_after']) { throw new RuntimeException('instant_credit_balance_mismatch'); }
            DB::update('pintogya_activity_instant_reward', array('state_code'=>'posted','credit_log_id'=>$logId,
                'native_entry_json'=>PintogyaCrypto::canonicalJson($entry),'posted_at'=>$now,'updated_at'=>$now,
                'retry_count'=>0,'next_attempt_at'=>0,'last_error'=>''),
                'reward_pk='.$rewardPk." AND state_code='eligible' AND credit_log_id IS NULL");
            if(DB::affected_rows() !== 1) { throw new RuntimeException('instant_reward_update_failed'); }
            return array('state'=>'posted','reward_pk'=>$rewardPk,'credit_log_id'=>$logId,'amount'=>$amount);
        });
    }

    public static function reconcile($site, $rewardPk) {
        self::assertSite($site); self::integer($rewardPk, 1, 4294967295);
        $row = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_reward').' WHERE reward_pk=%d', array($rewardPk));
        if(!$row) { throw new RuntimeException('instant_reward_missing'); }
        return self::transaction((int)$row['uid'], false, static function() use ($site,$rewardPk) {
            $row = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_reward').' WHERE reward_pk=%d LIMIT 1 FOR UPDATE', array($rewardPk));
            if(!$row) { throw new RuntimeException('instant_reward_missing'); }
            $key = PintogyaActivityInstantRule::rewardKey($site, $row['activity_id'], $row['togya_user_id'],
                $row['kind'], $row['kind'] === 'stage' ? $row['stage_id'] : null);
            if(!hash_equals($key, $row['reward_key'])) { throw new RuntimeException('instant_reward_key_mismatch'); }
            if($row['state_code'] === 'posted') { self::verifyPostedReadOnly($row); }
            return array('state'=>$row['state_code'],'reward_pk'=>$rewardPk,
                'credit_log_id'=>$row['credit_log_id'] === null ? null : (int)$row['credit_log_id']);
        });
    }

    public static function assertScope($site) { self::assertSite($site); }

    private static function storedRule($activityId) {
        $stored = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_rule').' WHERE activity_id=%s LIMIT 1 FOR UPDATE', array($activityId));
        if(!$stored) { throw new RuntimeException('instant_rule_missing'); }
        $rule = PintogyaActivityInstantRule::assertSnapshot(json_decode($stored['rule_json'], true, 512, JSON_THROW_ON_ERROR));
        if($rule['activity_id'] !== $activityId || PintogyaCrypto::digest('pintogya_instant_rule_v3', $rule) !== $stored['rule_hash']) {
            throw new RuntimeException('instant_rule_corrupt');
        }
        return $stored;
    }

    public static function verifyPostedReadOnly($row) {
        $entry = json_decode($row['native_entry_json'], true, 512, JSON_THROW_ON_ERROR);
        $logId = (int)$row['credit_log_id'];
        if(!is_array($entry) || ($entry['main']['uid'] ?? null) !== (int)$row['uid'] ||
            ($entry['main']['relatedid'] ?? null) !== (int)$row['reward_pk'] ||
            ($entry['main']['extcredits'.(int)$row['credit_id']] ?? null) !== (int)$row['amount']) {
            throw new RuntimeException('instant_posted_entry_mismatch');
        }
        $before = array();
        foreach(range(1,8) as $i) {
            $after = $entry['field']['ac_extcredits'.$i] ?? null;
            self::integer($after, -2147483648, 2147483647);
            $before['extcredits'.$i] = $after - ($i === (int)$row['credit_id'] ? (int)$row['amount'] : 0);
        }
        $expected = PintogyaActivityNativeCreditEntry::build((int)$row['uid'], (int)$row['credit_id'],
            (int)$row['amount'], (int)$row['reward_pk'], (int)$row['posted_at'], $before,
            array('activity_id'=>$row['activity_id'],'kind'=>$row['kind'],
                'stage_id'=>$row['kind'] === 'stage' ? $row['stage_id'] : null));
        if(PintogyaCrypto::canonicalJson($expected) !== PintogyaCrypto::canonicalJson($entry)) {
            throw new RuntimeException('instant_posted_entry_mismatch');
        }
        PintogyaActivityNativeCreditEntry::verifyExactRows($entry, $logId,
            DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log').' WHERE logid=%d LIMIT 2', array($logId)),
            DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log_field').' WHERE logid=%d LIMIT 2', array($logId)));
    }

    private static function rowMatchesRule($row, $rule) {
        $rule = PintogyaActivityInstantRule::assertSnapshot($rule);
        $stage = $rule['stages'][$row['stage_id']] ?? null;
        $expected = $row['kind'] === 'base' ? $rule['base'] : ($stage['amount'] ?? null);
        if($rule['activity_id'] !== $row['activity_id'] || (int)$row['credit_id'] !== $rule['credit_id'] ||
            ($row['kind'] === 'stage' && (!$stage || $stage['kind'] !== 'tutorial')) ||
            ($row['kind'] === 'base' && ($row['stage_id'] !== '' || $rule['finish_stage_id'] === null)) ||
            (int)$row['amount'] !== $expected || $expected < 1) { throw new RuntimeException('instant_entitlement_rule_mismatch'); }
    }

    private static function candidate($candidate, $rule) {
        if(!is_array($candidate) || !is_array($candidate['fact'] ?? null) ||
            ($candidate['activity_id'] ?? null) !== $rule['activity_id'] ||
            ($candidate['credit_id'] ?? null) !== $rule['credit_id'] ||
            ($candidate['fact']['completes_stage'] ?? null) !== true ||
            ($candidate['fact']['stage_version'] ?? null) !== $rule['stage_version']) {
            throw new InvalidArgumentException('instant_candidate_invalid');
        }
        foreach(array('uid'=>16777215,'binding_generation'=>4294967295,'observed_at'=>4294967295) as $key=>$max) {
            self::integer($candidate[$key] ?? null, 1, $max);
        }
        self::integer($candidate['fact']['occurred_at'] ?? null, 1, $candidate['observed_at'] + 300);
        PintogyaCrypto::assertUuid($candidate['togya_user_id'] ?? null);
        PintogyaCrypto::assertUuid($candidate['fact']['checkin_id'] ?? null);
        $id = $candidate['fact']['stage_id'] ?? null;
        $stage = $rule['stages'][$id] ?? null;
        if(!$stage || !in_array($candidate['kind'] ?? null, array('stage','base'), true) ||
            ($candidate['kind'] === 'stage' && ($stage['kind'] !== 'tutorial' || ($candidate['reward_stage_id'] ?? null) !== $id || $candidate['amount'] !== $stage['amount'])) ||
            ($candidate['kind'] === 'base' && ($stage['kind'] !== 'finish_story' || ($candidate['reward_stage_id'] ?? null) !== null || $candidate['amount'] !== $rule['base']))) {
            throw new InvalidArgumentException('instant_candidate_invalid');
        }
    }

    private static function transaction($uid, $credit, $callback) {
        $repo = new PintogyaRepository(); $uidLock = ''; $creditLock = ''; $started = false; $committing = false; $committed = false;
        try {
            $uidLock = $repo->acquireUidLock($uid);
            if($credit) {
                $creditLock = 'pin_credit_'.$uid;
                if((int)DB::result_first('SELECT GET_LOCK(%s, 5)', array($creditLock)) !== 1) {
                    $creditLock = ''; throw new RuntimeException('instant_credit_lock_unavailable');
                }
            }
            if(DB::query('START TRANSACTION') === false) { throw new RuntimeException('instant_begin_failed'); }
            $started = true; $result = $callback(); $committing = true;
            if(DB::query('COMMIT') === false) { throw new RuntimeException('instant_commit_failed'); }
            $started = false; $committing = false;
            $committed = true;
            return $result;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('instant_commit_outcome_unknown', 0, $error); }
            if($started) {
                try { if(DB::query('ROLLBACK') === false) { throw new RuntimeException('rollback_failed'); } }
                catch(Throwable $rollback) { throw new RuntimeException('instant_rollback_outcome_unknown', 0, $rollback); }
            }
            throw $error;
        } finally {
            // Raw transactional balance writes bypass the native member-count cache.
            if($credit && ($committed || $committing) && class_exists('table_common_member_count')) {
                try { table_common_member_count::t()->clear_cache($uid); }
                catch(Throwable $error) { error_log('pintogya_instant_credit_cache_refresh_failed'); }
            }
            if($creditLock !== '') { try { DB::result_first('SELECT RELEASE_LOCK(%s)', array($creditLock)); } catch(Throwable $ignored) {} }
            if($uidLock !== '') { try { $repo->releaseUidLock($uidLock); } catch(Throwable $ignored) {} }
        }
    }

    private static function assertSite($site) {
        PintogyaInstallation::assertScope($site);
        $tables = array_map(array('DB','table'), array('pintogya_binding','pintogya_activity','pintogya_participation',
            'pintogya_activity_instant_rule','pintogya_activity_instant_reward',
            'common_member_count','common_credit_log','common_credit_log_field'));
        $rows = DB::fetch_all('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .implode(',',array_fill(0,count($tables),'%s')).')', $tables);
        if(count($rows) !== count($tables)) { throw new RuntimeException('instant_schema_incomplete'); }
        foreach($rows as $row) {
            if(strtoupper((string)$row['ENGINE']) !== 'INNODB') { throw new RuntimeException('instant_schema_not_transactional'); }
        }
    }

    private static function integer($value, $min, $max) {
        if(!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException('instant_integer_invalid'); }
        return $value;
    }

    private static function sqlInteger($value) {
        if(is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,9})$/D', $value)) { $value = (int)$value; }
        return self::integer($value, -2147483648, 2147483647);
    }
}
