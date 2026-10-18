<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once __DIR__.'/repository.php';
require_once __DIR__.'/activity_settings.php';
require_once __DIR__.'/activity_reward_math.php';
require_once __DIR__.'/activity_native_credit_entry.php';
require_once __DIR__.'/capabilities.php';

final class PintogyaActivitySettlement {
    const RECEIPT_MAX_AGE = 300;

    public static function topUpDueBatch($activityPk, $afterRewardPk, $limit, $now) {
        self::number($activityPk, 1, PHP_INT_MAX, 'settlement_activity_invalid');
        self::number($afterRewardPk, 0, PHP_INT_MAX, 'settlement_cursor_invalid');
        self::number($limit, 1, 20, 'settlement_batch_limit_invalid');
        self::number($now, 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'settlement_time_invalid');
        $pool = DB::fetch_first('SELECT state_code,target_amount FROM '.DB::table('pintogya_activity_reward_pool')
            .' WHERE activity_pk='.$activityPk.' LIMIT 1');
        if(!$pool || !in_array($pool['state_code'], array('open', 'closing'), true)) {
            return array('state' => 'pool_inactive', 'scanned' => 0, 'posted' => 0,
                'next_cursor' => $afterRewardPk, 'has_more' => false, 'errors' => array());
        }
        $target = self::number($pool['target_amount'] ?? null, 0,
            PintogyaActivityNativeCreditEntry::MAX_BALANCE, 'settlement_target_invalid');
        $rows = DB::fetch_all('SELECT reward_pk,uid,paid_amount,receipt_state,approved_by_uid FROM '.DB::table('pintogya_activity_reward_member')
            .' WHERE activity_pk='.$activityPk." AND state_code='eligible' AND reward_pk>".$afterRewardPk
            .' ORDER BY reward_pk LIMIT '.($limit + 1));
        if(!is_array($rows)) { throw new RuntimeException('settlement_batch_read_failed'); }
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $result = array('state' => 'scanned', 'scanned' => 0, 'posted' => 0,
            'next_cursor' => $afterRewardPk, 'has_more' => $hasMore, 'errors' => array());
        foreach($rows as $row) {
            $rewardPk = self::number($row['reward_pk'] ?? null, 1, PHP_INT_MAX, 'settlement_reward_invalid');
            if($rewardPk <= $result['next_cursor']) { throw new RuntimeException('settlement_batch_order_invalid'); }
            $uid = self::number($row['uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID,
                'settlement_uid_invalid');
            $paid = self::number($row['paid_amount'] ?? null, 0,
                PintogyaActivityNativeCreditEntry::MAX_BALANCE, 'settlement_paid_invalid');
            $result['next_cursor'] = $rewardPk;
            $result['scanned']++;
            if($paid === 0 || $paid === $target) { continue; }
            if($paid > $target) {
                $result['errors'][] = array('reward_pk' => $rewardPk, 'code' => 'settlement_paid_exceeds_pool_target');
                continue;
            }
            if($row['receipt_state'] !== 'verified' && !(
                $row['receipt_state'] === 'pending' && (int)$row['approved_by_uid'] === 0)) {
                $result['errors'][] = array('reward_pk' => $rewardPk, 'code' => 'settlement_receipt_not_verified');
                continue;
            }
            try {
                $settled = self::settle($activityPk, $uid, $now);
                if($settled['state'] === 'posted') { $result['posted']++; }
            } catch(Throwable $error) {
                $result['errors'][] = array('reward_pk' => $rewardPk, 'code' => $error->getMessage());
                if($error->getMessage() === 'settlement_commit_outcome_unknown' ||
                    $error->getMessage() === 'settlement_rollback_outcome_unknown') {
                    $result['state'] = 'outcome_unknown';
                    $result['next_cursor'] = $afterRewardPk;
                    $result['has_more'] = true;
                    break;
                }
            }
        }
        return $result;
    }

    public static function settle($activityPk, $uid, $now) {
        PintogyaCapabilities::assertAvailable('rewards');
        return self::settleInternal($activityPk, $uid, $now, null);
    }

    public static function settleTimeout($reviewPk, $evidence) {
        PintogyaCapabilities::assertAvailable('rewards');
        self::assertSettlementEnabled();
        self::number($reviewPk, 1, PHP_INT_MAX, 'settlement_review_invalid');
        if(!is_object($evidence) || !method_exists($evidence, 'observe')) {
            throw new InvalidArgumentException('settlement_evidence_invalid');
        }
        $repo = new PintogyaRepository();
        $review = $repo->reviewCaseByPk($reviewPk);
        if(!$review || ($review['kind'] ?? null) !== 'completion' ||
            ($review['state_code'] ?? null) !== 'approved' ||
            ($review['decision_source'] ?? null) !== 'timeout' ||
            $review['decided_by_uid'] !== null || $review['stage_id'] !== null) {
            throw new RuntimeException('settlement_timeout_approval_missing');
        }
        $uid = self::number($review['uid'] ?? null, 1,
            PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_uid_invalid');
        $activityPk = self::number($review['activity_pk'] ?? null, 1, PHP_INT_MAX,
            'settlement_activity_invalid');
        $binding = $repo->activeBindingForUid($uid);
        if(!$binding || ($binding['state_code'] ?? null) !== 'active' ||
            (int)$binding['generation'] !== (int)$review['binding_generation']) {
            throw new RuntimeException('settlement_binding_changed');
        }
        $observed = $evidence->observe($uid, $review['source_ref'],
            (int)$review['stage_version'], $binding['togya_user_id'], 'completion', null,
            $review['source_ref'], 'en-US');
        if(!self::sameHash($observed['source_digest'] ?? null, $review['source_digest'] ?? null)) {
            throw new RuntimeException('settlement_timeout_source_changed');
        }
        $verifiedAt = $repo->currentUnixTime();
        return self::settleInternal($activityPk, $uid, $verifiedAt, array(
            'review_pk' => $reviewPk, 'source_digest' => $observed['source_digest'],
            'verified_at' => $verifiedAt,
        ));
    }

    private static function settleInternal($activityPk, $uid, $now, $timeoutProof) {
        self::assertSettlementEnabled();
        self::number($activityPk, 1, PHP_INT_MAX, 'settlement_activity_invalid');
        self::number($uid, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_uid_invalid');
        self::number($now, 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'settlement_time_invalid');
        $repo = new PintogyaRepository();
        $uidLock = '';
        $creditLock = '';
        $creditAcquired = false;
        $started = false;
        $committing = false;
        $committed = false;
        try {
            $uidLock = $repo->acquireUidLock($uid);
            $creditLock = 'pin_credit_'.$uid;
            if((int)DB::result_first('SELECT GET_LOCK(%s, 5)', array($creditLock)) !== 1) {
                throw new RuntimeException('settlement_credit_lock_unavailable');
            }
            $creditAcquired = true;
            if(DB::query('START TRANSACTION') === false) { throw new RuntimeException('settlement_begin_failed'); }
            $started = true;
            $result = self::settleLocked($activityPk, $uid, $now, $timeoutProof);
            $committing = true;
            if(DB::query('COMMIT') === false) { throw new RuntimeException('settlement_commit_failed'); }
            $committing = false;
            $started = false;
            $committed = true;
            return $result;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('settlement_commit_outcome_unknown', 0, $error); }
            if($started) {
                try {
                    if(DB::query('ROLLBACK') === false) { throw new RuntimeException('settlement_rollback_failed'); }
                } catch(Throwable $rollbackError) {
                    throw new RuntimeException('settlement_rollback_outcome_unknown', 0, $error);
                }
            }
            throw $error;
        } finally {
            if(($committed || $committing) && class_exists('table_common_member_count')) {
                try { table_common_member_count::t()->clear_cache($uid); }
                catch(Throwable $error) { error_log('pintogya_settlement_cache_refresh_failed'); }
            }
            if($creditAcquired) {
                try { DB::result_first('SELECT RELEASE_LOCK(%s)', array($creditLock)); }
                catch(Throwable $error) { error_log('pintogya_settlement_credit_lock_release_failed'); }
            }
            if($uidLock !== '') {
                try { $repo->releaseUidLock($uidLock); }
                catch(Throwable $error) { error_log('pintogya_settlement_uid_lock_release_failed'); }
            }
        }
    }

    private static function settleLocked($activityPk, $uid, $now, $timeoutProof) {
        global $_G;
        $activity = self::locked('pintogya_activity', 'activity_pk', $activityPk);
        if(!$activity || $activity['state_code'] !== 'active') {
            throw new RuntimeException('settlement_activity_inactive');
        }
        $tid = self::number($activity['tid'], 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'settlement_thread_invalid');
        $thread = DB::fetch_first('SELECT tid,fid,authorid,special,displayorder FROM '.DB::table('forum_thread')
            .' WHERE tid='.$tid.' LIMIT 1 FOR UPDATE');
        if(!$thread || self::number($thread['fid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_thread_invalid') !==
                self::number($activity['fid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_thread_invalid') ||
            self::number($thread['authorid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_thread_invalid') !==
                self::number($activity['owner_uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_thread_invalid') ||
            self::number($thread['special'] ?? null, 0, 255, 'settlement_thread_invalid') !== 127 ||
            self::number($thread['displayorder'] ?? null, -2147483648, 2147483647, 'settlement_thread_invalid') < 0) {
            throw new RuntimeException('settlement_thread_unavailable');
        }
        $pool = self::locked('pintogya_activity_reward_pool', 'activity_pk', $activityPk);
        if(!$pool || !in_array($pool['state_code'], array('open', 'closing'), true)) {
            throw new RuntimeException('settlement_pool_inactive');
        }
        $rulePk = self::number($pool['rule_pk'] ?? null, 1, PHP_INT_MAX, 'settlement_rule_invalid');
        $rule = self::locked('pintogya_activity_reward_rule', 'rule_pk', $rulePk);
        if(!$rule || $rule['activity_id'] !== $activity['activity_id'] ||
            !self::sameHash($rule['rule_hash'] ?? null, $pool['rule_hash'] ?? null) ||
            !hash_equals((string)$rule['rule_hash'], hash('sha256', "pintogya/activity-reward-rule/v1\0".$rule['rules_json']))) {
            throw new RuntimeException('settlement_rule_mismatch');
        }
        $creditId = self::number($pool['credit_id'] ?? null, 1, 8, 'settlement_credit_invalid');
        if(self::number($rule['credit_id'] ?? null, 1, 8, 'settlement_credit_invalid') !== $creditId) {
            throw new RuntimeException('settlement_credit_mismatch');
        }
        $config = PintogyaActivitySettings::rewardDefaultsFromJson($rule['rules_json'], $_G['setting']['extcredits'] ?? array());
        if($config['credit_id'] !== $creditId) { throw new RuntimeException('settlement_credit_mismatch'); }
        $member = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_reward_member')
            .' WHERE activity_pk='.$activityPk.' AND uid='.$uid.' LIMIT 1 FOR UPDATE');
        if(!$member || !self::hash64($member['decision_digest'] ?? null)) {
            throw new RuntimeException('settlement_approval_missing');
        }
        $approvedBy = self::number($member['approved_by_uid'] ?? null, 0,
            PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_reviewer_invalid');
        $manual = $member['state_code'] === 'eligible' && $member['receipt_state'] === 'verified' &&
            self::hash64($member['receipt_digest'] ?? null) && $approvedBy > 0 && $approvedBy !== $uid;
        $timeout = in_array($member['state_code'], array('awaiting_receipt', 'eligible'), true) &&
            $member['receipt_state'] === 'pending' && $member['receipt_digest'] === null &&
            $approvedBy === 0 && (int)$member['receipt_verified_at'] === 0;
        if(!$manual && !$timeout) { throw new RuntimeException('settlement_approval_missing'); }
        $previousPaid = self::number($member['paid_amount'] ?? null, 0,
            $config['rules']['max_member_reward'], 'settlement_paid_invalid');
        $rewardPk = self::number($member['reward_pk'] ?? null, 1, PHP_INT_MAX, 'settlement_reward_invalid');
        if($previousPaid > 0) {
            $priorPayment = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_reward_payment')
                .' WHERE reward_pk='.$rewardPk.' AND paid_total_after='.$previousPaid.' LIMIT 1 FOR UPDATE');
            self::verifyPriorPayment($priorPayment, $rewardPk, $activityPk, $uid, $creditId, $previousPaid, $now);
        }
        $approvedAt = self::number($member['approved_at'] ?? null, 1, $now, 'settlement_approval_time_invalid');
        if(!self::sameHash($member['rule_hash'] ?? null, $pool['rule_hash'] ?? null) ||
            self::number($member['credit_id'] ?? null, 1, 8, 'settlement_credit_invalid') !== $creditId) {
            throw new RuntimeException('settlement_receipt_stale');
        }
        if($manual) {
            $receiptAt = self::number($member['receipt_verified_at'] ?? null, 1, $now,
                'settlement_receipt_time_invalid');
            if($approvedAt > $receiptAt ||
                ($previousPaid === 0 && $receiptAt < $now - self::RECEIPT_MAX_AGE)) {
                throw new RuntimeException('settlement_receipt_stale');
            }
        } elseif(($previousPaid === 0 && $member['state_code'] !== 'awaiting_receipt') ||
            ($previousPaid > 0 && $member['state_code'] !== 'eligible')) {
            throw new RuntimeException('settlement_approval_missing');
        }
        $participantPk = self::number($member['participant_pk'] ?? null, 1, PHP_INT_MAX, 'settlement_participant_invalid');
        $participant = self::locked('pintogya_participation', 'participant_pk', $participantPk);
        if(!$participant || !in_array($participant['state_code'], $previousPaid > 0 ? array('active', 'inactive') : array('active'), true) ||
            self::number($participant['activity_pk'] ?? null, 1, PHP_INT_MAX, 'settlement_participant_invalid') !== $activityPk ||
            self::number($participant['uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, 'settlement_participant_invalid') !== $uid ||
            self::number($participant['stage_version'] ?? null, 1, PHP_INT_MAX, 'settlement_participant_invalid') !==
                self::number($activity['stage_version'] ?? null, 1, PHP_INT_MAX, 'settlement_activity_version_invalid') ||
            ($manual && $previousPaid === 0 && self::number($participant['last_verified_at'] ?? null, 1, $now,
                'settlement_participant_time_invalid') < $now - self::RECEIPT_MAX_AGE)) {
            throw new RuntimeException('settlement_participation_stale');
        }
        if($previousPaid === 0) {
            $binding = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_binding')
                .' WHERE uid='.$uid.' LIMIT 1 FOR UPDATE');
            if(!$binding || $binding['state_code'] !== 'active' ||
                $binding['togya_user_id'] !== $participant['togya_user_id'] ||
                self::number($binding['generation'] ?? null, 1, PHP_INT_MAX, 'settlement_binding_invalid') !==
                    self::number($participant['binding_generation'] ?? null, 1, PHP_INT_MAX, 'settlement_binding_invalid')) {
                throw new RuntimeException('settlement_binding_changed');
            }
        }
        if($timeout) {
            self::verifyTimeoutDecision($activity, $member, $participant, $timeoutProof, $now,
                $previousPaid === 0);
        }

        $joined = self::number($pool['joined_count'] ?? null, 1, 2147483647, 'settlement_count_invalid');
        $completed = self::number($pool['completed_count'] ?? null, 1, $joined, 'settlement_count_invalid');
        $score = self::number($pool['score_total'] ?? null, 0, 2147483647, 'settlement_score_invalid');
        $current = PintogyaActivityRewardMath::calculate($config['rules'], $joined, $completed, $score);
        $poolTarget = self::number($pool['target_amount'] ?? null, $current['per_member'],
            $config['rules']['max_member_reward'], 'settlement_target_invalid');
        $memberTarget = self::number($member['target_amount'] ?? null, 0, $poolTarget, 'settlement_target_invalid');
        $paid = self::number($previousPaid, 0, $memberTarget, 'settlement_paid_invalid');
        $due = PintogyaActivityRewardMath::due($memberTarget, $poolTarget, $paid);
        $poolPaid = self::number($pool['paid_total'] ?? null, 0, $config['rules']['max_total_budget'], 'settlement_budget_invalid');
        if($poolPaid < $paid) { throw new RuntimeException('settlement_pool_paid_mismatch'); }
        if($due['due'] === 0) { return array('state' => 'already_paid', 'amount' => 0); }
        if($due['due'] > $config['rules']['max_total_budget'] - $poolPaid) {
            throw new RuntimeException('settlement_budget_exceeded');
        }
        $balances = DB::fetch_first('SELECT extcredits1,extcredits2,extcredits3,extcredits4,extcredits5,extcredits6,extcredits7,extcredits8'
            .' FROM '.DB::table('common_member_count').' WHERE uid='.$uid.' LIMIT 1 FOR UPDATE');
        if(!$balances) { throw new RuntimeException('settlement_credit_row_missing'); }
        foreach(range(1, 8) as $i) {
            $key = 'extcredits'.$i;
            $balances[$key] = self::number($balances[$key] ?? null, -2147483648, 2147483647, 'settlement_balance_invalid');
        }
        $digest = hash('sha256', "pintogya/activity-settlement/v1\0".$activityPk."\0".$uid."\0".$rewardPk."\0".$due['target']);
        $paymentPk = DB::insert('pintogya_activity_reward_payment', array(
            'reward_pk' => $rewardPk, 'activity_pk' => $activityPk, 'uid' => $uid,
            'credit_id' => $creditId, 'settlement_digest' => $digest,
            'operation' => PintogyaActivityNativeCreditEntry::OPERATION,
            'amount' => $due['due'], 'paid_total_after' => $due['target'],
            // Native DB::insert quotes PHP null as ''; use the SQL DEFAULT NULL.
            'balance_before' => 0, 'balance_after' => 0,
            'state_code' => 'reserved', 'created_at' => $now, 'posted_at' => 0,
        ), true);
        $paymentPk = self::number($paymentPk, 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'settlement_payment_insert_failed');
        $entry = PintogyaActivityNativeCreditEntry::build($uid, $creditId, $due['due'], $paymentPk, $now, $balances);
        $creditField = 'extcredits'.$creditId;
        DB::update('common_member_count', array($creditField => $entry['balance_after']),
            'uid='.$uid.' AND '.$creditField.'='.$entry['balance_before']);
        self::affectedOne('settlement_balance_write_failed');
        $logId = DB::insert('common_credit_log', $entry['main'], true);
        $logId = self::number($logId, 1, PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, 'settlement_credit_log_insert_failed');
        $field = $entry['field'];
        $field['logid'] = $logId;
        DB::insert('common_credit_log_field', $field);
        self::affectedOne('settlement_credit_field_insert_failed');
        PintogyaActivityNativeCreditEntry::verifyExactRows($entry, $logId,
            DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log').' WHERE logid='.$logId.' LIMIT 2'),
            DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log_field').' WHERE logid='.$logId.' LIMIT 2'));
        $memberUpdate = array('target_amount' => $due['target'],
            'paid_amount' => $due['target'], 'updated_at' => $now);
        if($timeout && $previousPaid === 0) { $memberUpdate['state_code'] = 'eligible'; }
        DB::update('pintogya_activity_reward_member', $memberUpdate,
            'reward_pk='.$rewardPk.' AND paid_amount='.$paid.
            ($timeout ? " AND state_code='".$member['state_code']."' AND receipt_state='pending' AND approved_by_uid=0" :
                " AND state_code='eligible' AND receipt_state='verified'"));
        self::affectedOne('settlement_entitlement_write_failed');
        DB::update('pintogya_activity_reward_pool', array('paid_total' => $poolPaid + $due['due'], 'updated_at' => $now),
            'activity_pk='.$activityPk.' AND paid_total='.$poolPaid);
        self::affectedOne('settlement_pool_write_failed');
        DB::update('pintogya_activity_reward_payment', array('balance_before' => $entry['balance_before'],
            'balance_after' => $entry['balance_after'], 'credit_log_id' => $logId,
            'state_code' => 'posted', 'posted_at' => $now),
            'payment_pk='.$paymentPk." AND state_code='reserved' AND credit_log_id IS NULL");
        self::affectedOne('settlement_payment_write_failed');
        return array('state' => 'posted', 'amount' => $due['due'], 'payment_pk' => $paymentPk,
            'credit_log_id' => $logId, 'paid_total_after' => $due['target']);
    }

    private static function verifyTimeoutDecision($activity, $member, $participant, $proof,
        $now, $needsFreshProof) {
        $error = 'settlement_timeout_approval_missing';
        $digest = $member['decision_digest'];
        $review = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_review_case')
            ." WHERE decision_digest='".$digest."' LIMIT 1 FOR UPDATE");
        if(!$review) { throw new RuntimeException($error); }
        $reviewPk = self::number($review['review_pk'] ?? null, 1, PHP_INT_MAX, $error);
        $decision = self::locked('pintogya_activity_review_decision', 'review_pk', $reviewPk);
        if(!$decision || ($review['kind'] ?? null) !== 'completion' ||
            ($review['state_code'] ?? null) !== 'approved' ||
            ($review['decision_source'] ?? null) !== 'timeout' ||
            $review['decided_by_uid'] !== null || $review['stage_id'] !== null ||
            ($decision['kind'] ?? null) !== 'completion' ||
            ($decision['decision'] ?? null) !== 'approved' ||
            ($decision['decision_source'] ?? null) !== 'timeout' ||
            $decision['decided_by_uid'] !== null ||
            self::number($review['activity_pk'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$activity['activity_pk'] ||
            self::number($review['participant_pk'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$participant['participant_pk'] ||
            self::number($review['uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, $error) !== (int)$member['uid'] ||
            self::number($review['binding_generation'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$participant['binding_generation'] ||
            ($review['source_ref'] ?? null) !== $activity['activity_id'] ||
            self::number($review['stage_version'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$activity['stage_version'] ||
            self::number($review['auto_due_at'] ?? null, 1, $now, $error) >
                self::number($review['decided_at'] ?? null, 1, $now, $error) ||
            (int)$review['decided_at'] !== (int)$member['approved_at'] ||
            self::number($decision['review_pk'] ?? null, 1, PHP_INT_MAX, $error) !== $reviewPk ||
            self::number($decision['activity_pk'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$activity['activity_pk'] ||
            self::number($decision['participant_pk'] ?? null, 1, PHP_INT_MAX, $error) !== (int)$participant['participant_pk'] ||
            self::number($decision['uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, $error) !== (int)$member['uid'] ||
            (int)$decision['decided_at'] !== (int)$review['decided_at'] ||
            !self::sameHash($decision['decision_digest'] ?? null, $digest) ||
            !self::sameHash($review['source_digest'] ?? null, $decision['source_digest'] ?? null)) {
            throw new RuntimeException($error);
        }
        if($needsFreshProof) {
            if(!is_array($proof) ||
                self::number($proof['review_pk'] ?? null, 1, PHP_INT_MAX, $error) !== $reviewPk ||
                self::number($proof['verified_at'] ?? null, 1, $now, $error) < $now - self::RECEIPT_MAX_AGE ||
                !self::sameHash($proof['source_digest'] ?? null, $review['source_digest'])) {
                throw new RuntimeException('settlement_timeout_source_changed');
            }
        }
    }

    private static function locked($logical, $column, $id) {
        return DB::fetch_first('SELECT * FROM '.DB::table($logical).' WHERE '.$column.'='.$id.' LIMIT 1 FOR UPDATE');
    }

    private static function assertSettlementEnabled() {
        PintogyaCapabilities::assertAvailable('rewards');
        global $_G;
        if((string)($_G['setting']['pintogya_activity_settlement_enabled'] ?? '0') !== '1') {
            throw new RuntimeException('reward_settlement_disabled');
        }
    }

    public static function verifyPriorPayment($payment, $rewardPk, $activityPk, $uid, $creditId, $paid, $now) {
        $error = 'settlement_prior_payment_invalid';
        if(!is_array($payment) || ($payment['state_code'] ?? null) !== 'posted' ||
            self::number($payment['reward_pk'] ?? null, 1, PHP_INT_MAX, $error) !== $rewardPk ||
            self::number($payment['activity_pk'] ?? null, 1, PHP_INT_MAX, $error) !== $activityPk ||
            self::number($payment['uid'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_UID, $error) !== $uid ||
            self::number($payment['credit_id'] ?? null, 1, 8, $error) !== $creditId ||
            self::number($payment['paid_total_after'] ?? null, 1, PintogyaActivityNativeCreditEntry::MAX_BALANCE, $error) !== $paid ||
            ($payment['operation'] ?? null) !== PintogyaActivityNativeCreditEntry::OPERATION ||
            !self::sameHash($payment['settlement_digest'] ?? null,
                hash('sha256', "pintogya/activity-settlement/v1\0".$activityPk."\0".$uid."\0".$rewardPk."\0".$paid))) {
            throw new RuntimeException($error);
        }
        $paymentPk = self::number($payment['payment_pk'] ?? null, 1,
            PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, $error);
        $amount = self::number($payment['amount'] ?? null, 1, $paid, $error);
        $logId = self::number($payment['credit_log_id'] ?? null, 1,
            PintogyaActivityNativeCreditEntry::MAX_UNSIGNED, $error);
        $createdAt = self::number($payment['created_at'] ?? null, 1, $now, $error);
        self::number($payment['posted_at'] ?? null, $createdAt, $now, $error);
        $before = self::number($payment['balance_before'] ?? null,
            PintogyaActivityNativeCreditEntry::MIN_BALANCE,
            PintogyaActivityNativeCreditEntry::MAX_BALANCE, $error);
        $after = self::number($payment['balance_after'] ?? null,
            PintogyaActivityNativeCreditEntry::MIN_BALANCE,
            PintogyaActivityNativeCreditEntry::MAX_BALANCE, $error);
        if($before > PintogyaActivityNativeCreditEntry::MAX_BALANCE - $amount || $before + $amount !== $after) {
            throw new RuntimeException($error);
        }
        $mainRows = DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log').' WHERE logid='.$logId.' LIMIT 2');
        $fieldRows = DB::fetch_all('SELECT * FROM '.DB::table('common_credit_log_field').' WHERE logid='.$logId.' LIMIT 2');
        if(!is_array($mainRows) || count($mainRows) !== 1 ||
            !is_array($fieldRows) || count($fieldRows) !== 1) { throw new RuntimeException($error); }
        $balances = array();
        foreach(range(1, 8) as $i) {
            $key = 'extcredits'.$i;
            $balances[$key] = $i === $creditId ? $before : self::number($fieldRows[0]['ac_'.$key] ?? null,
                PintogyaActivityNativeCreditEntry::MIN_BALANCE,
                PintogyaActivityNativeCreditEntry::MAX_BALANCE, $error);
        }
        try {
            $entry = PintogyaActivityNativeCreditEntry::build($uid, $creditId, $amount, $paymentPk,
                $createdAt, $balances);
            PintogyaActivityNativeCreditEntry::verifyExactRows($entry, $logId, $mainRows, $fieldRows);
        } catch(Throwable $cause) {
            throw new RuntimeException($error, 0, $cause);
        }
    }

    private static function number($value, $minimum, $maximum, $error) {
        if(!is_int($value)) {
            if(!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)) {
                throw new RuntimeException($error);
            }
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new RuntimeException($error); }
            $value = $parsed;
        }
        if($value < $minimum || $value > $maximum) { throw new RuntimeException($error); }
        return $value;
    }

    private static function hash64($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private static function sameHash($left, $right) {
        return self::hash64($left) && self::hash64($right) && hash_equals($left, $right);
    }

    private static function affectedOne($error) {
        if(DB::affected_rows() !== 1) { throw new RuntimeException($error); }
    }
}
