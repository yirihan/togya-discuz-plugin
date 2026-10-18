<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_close_plan.php';
require_once __DIR__.'/activity_settlement.php';

final class PintogyaActivityClose {
    const MAX_ROWS = 1000;

    public static function reconcile($activityPk, $now) {
        global $_G;
        $activityPk = self::number($activityPk);
        $now = self::number($now);
        if($activityPk < 1 || $now < 1) { throw new RuntimeException('activity_close_arguments_invalid'); }
        $started = false;
        $committing = false;
        try {
            if(DB::query('START TRANSACTION') === false) { throw new RuntimeException('activity_close_begin_failed'); }
            $started = true;
            $activity = self::locked('pintogya_activity', 'activity_pk', $activityPk);
            if(!$activity || $activity['state_code'] !== 'active') {
                throw new RuntimeException('activity_close_activity_inactive');
            }
            $thread = self::locked('forum_thread', 'tid', self::number($activity['tid']));
            if(!$thread || (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
                (int)$thread['fid'] !== (int)$activity['fid'] || (int)$thread['authorid'] !== (int)$activity['owner_uid']) {
                throw new RuntimeException('activity_close_thread_unavailable');
            }
            $pool = self::locked('pintogya_activity_reward_pool', 'activity_pk', $activityPk);
            if(!$pool || !in_array($pool['state_code'], array('open','closing','closed'), true)) {
                throw new RuntimeException('activity_close_pool_inactive');
            }
            $rule = self::locked('pintogya_activity_reward_rule', 'rule_pk', self::number($pool['rule_pk']));
            if(!$rule || $rule['activity_id'] !== $activity['activity_id'] ||
                !hash_equals((string)$pool['rule_hash'], (string)$rule['rule_hash']) ||
                !hash_equals((string)$rule['rule_hash'], hash('sha256', "pintogya/activity-reward-rule/v1\0".$rule['rules_json'])) ||
                (int)$rule['credit_id'] !== (int)$pool['credit_id']) {
                throw new RuntimeException('activity_close_rule_mismatch');
            }
            $config = PintogyaActivitySettings::rewardDefaultsFromJson($rule['rules_json'], $_G['setting']['extcredits'] ?? array());
            if(($config['version'] ?? 0) !== 2 || $config['credit_id'] !== (int)$pool['credit_id']) {
                throw new RuntimeException('activity_close_deadlines_missing');
            }
            $cutoffs = PintogyaActivityDeadlines::cutoffs(self::number($activity['published_at']), $config['window_days']);
            if($now < $cutoffs['close']) {
                $result = array('state'=>$pool['state_code'],'change'=>false,'reason'=>'before_deadline');
            } else {
                $members = self::rows('pintogya_activity_reward_member', $activityPk, 'reward_pk');
                $payments = self::rows('pintogya_activity_reward_payment', $activityPk, 'payment_pk');
                if(count($members) > self::MAX_ROWS || count($payments) > self::MAX_ROWS) {
                    $result = array('state'=>'closing','change'=>$pool['state_code'] !== 'closing','reason'=>'reconciliation_batch_limit');
                } else {
                    $snapshot = array('complete'=>true,'state'=>$pool['state_code'],
                        'target'=>$pool['target_amount'],'paid'=>$pool['paid_total'],
                        'memberCount'=>count($members),'paymentCount'=>count($payments),
                        'pendingReviews'=>self::pendingReviews($activityPk),'members'=>array(),'payments'=>array());
                    $byId = array();
                    foreach($members as $member) {
                        if((int)$member['credit_id'] !== (int)$pool['credit_id'] ||
                            !hash_equals((string)$member['rule_hash'], (string)$pool['rule_hash'])) {
                            throw new RuntimeException('activity_close_member_rule_mismatch');
                        }
                        $id = self::number($member['reward_pk']);
                        $byId[$id] = $member;
                        $snapshot['members'][] = array('id'=>$id,'state'=>$member['state_code'],'paid'=>$member['paid_amount']);
                    }
                    foreach($payments as $payment) {
                        $rewardPk = self::number($payment['reward_pk']);
                        if(!isset($byId[$rewardPk]) || (int)$payment['uid'] !== (int)$byId[$rewardPk]['uid']) {
                            throw new RuntimeException('activity_close_payment_member_mismatch');
                        }
                        PintogyaActivitySettlement::verifyPriorPayment($payment, $rewardPk, $activityPk,
                            self::number($payment['uid']), (int)$pool['credit_id'],
                            self::number($payment['paid_total_after']), $now);
                        $snapshot['payments'][] = array('id'=>$payment['payment_pk'],'memberId'=>$rewardPk,
                            'amount'=>$payment['amount'],'logId'=>$payment['credit_log_id'],
                            'state'=>$payment['state_code'],'exactLogVerified'=>true);
                    }
                    $result = PintogyaActivityClosePlan::decide($snapshot, self::number($activity['published_at']),
                        $config['window_days'], $now);
                }
                if($result['change']) {
                    if($pool['state_code'] === 'closed') { throw new RuntimeException('activity_close_closed_immutable'); }
                    DB::update('pintogya_activity_reward_pool', array('state_code'=>$result['state'],'updated_at'=>$now),
                        'pool_pk='.self::number($pool['pool_pk'])." AND state_code='".addslashes($pool['state_code'])."'");
                    if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_close_state_conflict'); }
                }
            }
            $committing = true;
            if(DB::query('COMMIT') === false) { throw new RuntimeException('activity_close_commit_failed'); }
            $started = false;
            $committing = false;
            return $result;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('activity_close_commit_outcome_unknown', 0, $error); }
            if($started && DB::query('ROLLBACK') === false) {
                throw new RuntimeException('activity_close_rollback_outcome_unknown', 0, $error);
            }
            throw $error;
        }
    }

    private static function pendingReviews($activityPk) {
        $row = DB::fetch_first('SELECT r.review_pk FROM '.DB::table('pintogya_activity_review_case').' r'
            .' WHERE r.activity_pk='.$activityPk." AND (r.state_code='pending' OR"
            ." (r.kind='completion' AND r.state_code='approved' AND NOT EXISTS"
            .' (SELECT 1 FROM '.DB::table('pintogya_activity_reward_member').' m'
            .' WHERE m.activity_pk=r.activity_pk AND m.decision_digest=r.decision_digest))) LIMIT 1 FOR UPDATE');
        return $row ? 1 : 0;
    }

    private static function rows($table, $activityPk, $key) {
        $rows = DB::fetch_all('SELECT * FROM '.DB::table($table).' WHERE activity_pk='.$activityPk
            .' ORDER BY '.$key.' LIMIT '.(self::MAX_ROWS + 1).' FOR UPDATE');
        if(!is_array($rows)) { throw new RuntimeException('activity_close_snapshot_read_failed'); }
        return $rows;
    }

    private static function locked($table, $key, $id) {
        return DB::fetch_first('SELECT * FROM '.DB::table($table).' WHERE '.$key.'='.$id.' LIMIT 1 FOR UPDATE');
    }

    private static function number($value) {
        if(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new RuntimeException('activity_close_integer_invalid'); }
            $value = $parsed;
        }
        if(!is_int($value) || $value < 0) { throw new RuntimeException('activity_close_integer_invalid'); }
        return $value;
    }
}
