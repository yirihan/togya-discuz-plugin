<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_deadlines.php';

// The transaction adapter must supply the full, locked reconciliation snapshot.
final class PintogyaActivityClosePlan {
    public static function decide($snapshot, $publishedAt, $days, $now) {
        if(!is_array($snapshot) || ($snapshot['complete'] ?? null) !== true ||
            !is_array($snapshot['members'] ?? null) || !is_array($snapshot['payments'] ?? null)) {
            throw new RuntimeException('activity_close_snapshot_incomplete');
        }
        $cutoffs = PintogyaActivityDeadlines::cutoffs($publishedAt, $days);
        $now = self::number($now, 'activity_close_time_invalid');
        $state = $snapshot['state'] ?? '';
        if(!in_array($state, array('open', 'closing', 'closed'), true)) {
            throw new RuntimeException('activity_close_pool_inactive');
        }
        $target = self::number($snapshot['target'] ?? null, 'activity_close_target_invalid');
        $poolPaid = self::number($snapshot['paid'] ?? null, 'activity_close_paid_invalid');
        $memberCount = self::number($snapshot['memberCount'] ?? null, 'activity_close_count_invalid');
        $paymentCount = self::number($snapshot['paymentCount'] ?? null, 'activity_close_count_invalid');
        if($memberCount !== count($snapshot['members']) || $paymentCount !== count($snapshot['payments'])) {
            throw new RuntimeException('activity_close_snapshot_incomplete');
        }
        $blocked = self::number($snapshot['pendingReviews'] ?? null, 'activity_close_reviews_invalid') > 0;
        $members = array();
        $memberPaid = 0;
        foreach($snapshot['members'] as $member) {
            if(!is_array($member)) { throw new RuntimeException('activity_close_member_invalid'); }
            $id = self::number($member['id'] ?? null, 'activity_close_member_invalid');
            $paid = self::number($member['paid'] ?? null, 'activity_close_member_invalid');
            if($id < 1 || isset($members[$id]) || $paid > $target) {
                throw new RuntimeException('activity_close_member_invalid');
            }
            $memberState = $member['state'] ?? '';
            if(!in_array($memberState, array('eligible', 'held', 'awaiting_receipt', 'disqualified'), true)) {
                throw new RuntimeException('activity_close_member_invalid');
            }
            if($memberState !== 'disqualified' && ($memberState !== 'eligible' || $paid !== $target)) {
                $blocked = true;
            }
            $members[$id] = array('paid' => $paid, 'sum' => 0);
            $memberPaid = self::add($memberPaid, $paid);
        }
        $paymentPaid = 0;
        $ids = array();
        $logIds = array();
        foreach($snapshot['payments'] as $payment) {
            if(!is_array($payment)) { throw new RuntimeException('activity_close_payment_invalid'); }
            $id = self::number($payment['id'] ?? null, 'activity_close_payment_invalid');
            $memberId = self::number($payment['memberId'] ?? null, 'activity_close_payment_invalid');
            $amount = self::number($payment['amount'] ?? null, 'activity_close_payment_invalid');
            $logId = self::number($payment['logId'] ?? null, 'activity_close_payment_invalid');
            if($id < 1 || $amount < 1 || $logId < 1 || isset($ids[$id]) || isset($logIds[$logId]) ||
                !isset($members[$memberId]) || ($payment['state'] ?? '') !== 'posted' ||
                ($payment['exactLogVerified'] ?? null) !== true) {
                throw new RuntimeException('activity_close_payment_invalid');
            }
            $ids[$id] = true;
            $logIds[$logId] = true;
            $members[$memberId]['sum'] = self::add($members[$memberId]['sum'], $amount);
            $paymentPaid = self::add($paymentPaid, $amount);
        }
        foreach($members as $member) {
            if($member['sum'] !== $member['paid']) { throw new RuntimeException('activity_close_ledger_mismatch'); }
        }
        if($poolPaid !== $memberPaid || $poolPaid !== $paymentPaid) {
            throw new RuntimeException('activity_close_ledger_mismatch');
        }
        if($state === 'closed') {
            if($blocked || $now < $cutoffs['close']) { throw new RuntimeException('activity_close_state_invalid'); }
            return array('state' => 'closed', 'change' => false, 'reason' => 'already_closed');
        }
        if($now < $cutoffs['close']) {
            return array('state' => $state, 'change' => false, 'reason' => 'before_deadline');
        }
        $next = $blocked ? 'closing' : 'closed';
        return array('state' => $next, 'change' => $state !== $next,
            'reason' => $blocked ? 'reconciliation_pending' : 'reconciled');
    }

    private static function number($value, $error) {
        if(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new RuntimeException($error); }
            $value = $parsed;
        }
        if(!is_int($value) || $value < 0) { throw new RuntimeException($error); }
        return $value;
    }

    private static function add($left, $right) {
        if($right > PHP_INT_MAX - $left) { throw new RuntimeException('activity_close_sum_overflow'); }
        return $left + $right;
    }
}
