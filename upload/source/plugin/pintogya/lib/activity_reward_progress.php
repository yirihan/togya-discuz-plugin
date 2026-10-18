<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_settings.php';
require_once __DIR__.'/activity_reward_math.php';

final class PintogyaActivityRewardProgress {
    public static function planNewJoin($repo, $pool, $activityPk, $activityId, $extcredits) {
        $context = self::checkedContext($repo, $pool, $activityPk, $activityId, $extcredits);
        $rules = $context['rules'];
        $joined = $context['joined'];
        $completed = $context['completed'];
        $score = $context['score'];
        $target = $context['target'];
        if($joined >= $rules['max_participants']) { throw new RuntimeException('activity_join_capacity_reached'); }
        $next = PintogyaActivityRewardMath::calculate($rules, $joined + 1, $completed, $score);
        $nextTarget = max($target, $next['per_member']);
        if($nextTarget > 0 && $joined + 1 > intdiv($rules['max_total_budget'], $nextTarget)) {
            throw new RuntimeException('activity_join_budget_exceeded');
        }
        return array('activity_pk' => $activityPk, 'joined_before' => $joined,
            'target_before' => $target, 'joined_after' => $joined + 1, 'target_after' => $nextTarget);
    }

    public static function planUnconfirmedLeave($repo, $pool, $activityPk, $activityId, $extcredits) {
        $context = self::checkedContext($repo, $pool, $activityPk, $activityId, $extcredits);
        $joined = $context['joined'];
        $target = $context['target'];
        if($joined <= $context['completed']) { throw new RuntimeException('activity_join_count_mismatch'); }
        PintogyaActivityRewardMath::calculate($context['rules'], $joined - 1,
            $context['completed'], $context['score']);
        if($context['paid'] > ($joined - 1) * $target) {
            throw new RuntimeException('activity_join_pool_mismatch');
        }
        return array('activity_pk' => $activityPk, 'joined_before' => $joined,
            'target_before' => $target, 'joined_after' => $joined - 1, 'target_after' => $target);
    }

    public static function planNewCompletion($repo, $pool, $activityPk, $activityId, $extcredits) {
        $context = self::checkedContext($repo, $pool, $activityPk, $activityId, $extcredits, true);
        $joined = $context['joined'];
        $completed = $context['completed'];
        if($completed >= $joined) { throw new RuntimeException('activity_completion_count_exceeded'); }
        $next = PintogyaActivityRewardMath::calculate($context['rules'], $joined,
            $completed + 1, $context['score']);
        $nextTarget = max($context['target'], $next['per_member']);
        if($nextTarget > 0 && $joined > intdiv($context['rules']['max_total_budget'], $nextTarget)) {
            throw new RuntimeException('activity_completion_budget_exceeded');
        }
        return array('activity_pk' => $activityPk, 'joined_count' => $joined,
            'completed_before' => $completed, 'completed_after' => $completed + 1,
            'target_before' => $context['target'], 'target_after' => $nextTarget);
    }

    public static function planRatingIncrease($repo, $pool, $activityPk, $activityId, $extcredits, $creditId, $amount) {
        $context = self::checkedContext($repo, $pool, $activityPk, $activityId, $extcredits);
        self::assertRating($pool, $creditId, $amount);
        if($amount > $context['rules']['max_score'] - $context['score']) {
            throw new RuntimeException('activity_rating_score_limit');
        }
        $scoreAfter = $context['score'] + $amount;
        $next = PintogyaActivityRewardMath::calculate($context['rules'], $context['joined'],
            $context['completed'], $scoreAfter);
        $targetAfter = max($context['target'], $next['per_member']);
        if($targetAfter > 0 && $context['joined'] > intdiv($context['rules']['max_total_budget'], $targetAfter)) {
            throw new RuntimeException('activity_rating_budget_exceeded');
        }
        return array('activity_pk' => $activityPk, 'joined_count' => $context['joined'],
            'completed_count' => $context['completed'], 'score_before' => $context['score'],
            'score_after' => $scoreAfter, 'target_before' => $context['target'],
            'target_after' => $targetAfter, 'allow_closing' => false);
    }

    public static function planRatingReversal($repo, $pool, $activityPk, $activityId, $extcredits, $creditId, $amount) {
        $context = self::checkedContext($repo, $pool, $activityPk, $activityId, $extcredits, true);
        self::assertRating($pool, $creditId, $amount);
        if($amount > $context['score']) { throw new RuntimeException('activity_rating_reversal_exceeds_score'); }
        $scoreAfter = $context['score'] - $amount;
        PintogyaActivityRewardMath::calculate($context['rules'], $context['joined'],
            $context['completed'], $scoreAfter);
        return array('activity_pk' => $activityPk, 'joined_count' => $context['joined'],
            'completed_count' => $context['completed'], 'score_before' => $context['score'],
            'score_after' => $scoreAfter, 'target_before' => $context['target'],
            'target_after' => $context['target'], 'allow_closing' => true);
    }

    public static function recordNewJoin($repo, $plan, $now) {
        $repo->advanceActivityRewardPoolJoin($plan['activity_pk'], $plan['joined_before'],
            $plan['target_before'], $plan['joined_after'], $plan['target_after'], $now);
    }

    public static function recordUnconfirmedLeave($repo, $plan, $now) {
        $repo->reduceActivityRewardPoolJoin($plan['activity_pk'], $plan['joined_before'],
            $plan['target_before'], $plan['joined_after'], $now);
    }

    public static function recordNewCompletion($repo, $plan, $now) {
        $repo->advanceActivityRewardPoolCompletion($plan['activity_pk'], $plan['joined_count'],
            $plan['completed_before'], $plan['target_before'], $plan['completed_after'],
            $plan['target_after'], $now);
    }

    public static function recordRatingChange($repo, $plan, $now) {
        $repo->updateActivityRewardPoolScore($plan['activity_pk'], $plan['joined_count'],
            $plan['completed_count'], $plan['score_before'], $plan['target_before'],
            $plan['score_after'], $plan['target_after'], $plan['allow_closing'], $now);
    }

    private static function assertRating($pool, $creditId, $amount) {
        if(!is_int($creditId) || $creditId !== self::number($pool['credit_id'] ?? null, 1, 8, 'activity_rating_credit_invalid')) {
            throw new RuntimeException('activity_rating_credit_invalid');
        }
        if(!is_int($amount) || $amount <= 0) { throw new RuntimeException('activity_rating_amount_invalid'); }
    }

    private static function checkedContext($repo, $pool, $activityPk, $activityId, $extcredits, $allowClosing = false) {
        if(!is_array($pool) || !in_array($pool['state_code'] ?? null,
            $allowClosing ? array('open', 'closing') : array('open'), true) || !is_array($extcredits) ||
            self::number($pool['activity_pk'] ?? null, 1, PHP_INT_MAX, 'activity_join_pool_invalid') !== $activityPk) {
            throw new RuntimeException('activity_join_pool_unavailable');
        }
        $rulePk = self::number($pool['rule_pk'] ?? null, 1, PHP_INT_MAX, 'activity_join_rule_invalid');
        $rule = $repo->activityRewardRuleByPk($rulePk);
        if(!is_array($rule) ||
            self::number($rule['rule_pk'] ?? null, 1, PHP_INT_MAX, 'activity_join_rule_invalid') !== $rulePk ||
            ($rule['activity_id'] ?? null) !== $activityId ||
            !self::sameHash($pool['rule_hash'] ?? null, $rule['rule_hash'] ?? null) ||
            !is_string($rule['rules_json'] ?? null) ||
            !hash_equals($rule['rule_hash'], hash('sha256', "pintogya/activity-reward-rule/v1\0".$rule['rules_json']))) {
            throw new RuntimeException('activity_join_rule_mismatch');
        }
        try { $config = PintogyaActivitySettings::rewardDefaultsFromJson($rule['rules_json'], $extcredits); }
        catch(InvalidArgumentException $error) { throw new RuntimeException('activity_join_rule_mismatch', 0, $error); }
        $creditId = self::number($pool['credit_id'] ?? null, 1, 8, 'activity_join_credit_invalid');
        if($config['credit_id'] !== $creditId ||
            self::number($rule['credit_id'] ?? null, 1, 8, 'activity_join_credit_invalid') !== $creditId) {
            throw new RuntimeException('activity_join_credit_mismatch');
        }
        $rules = $config['rules'];
        $joined = self::number($pool['joined_count'] ?? null, 0, $rules['max_participants'], 'activity_join_count_invalid');
        $completed = self::number($pool['completed_count'] ?? null, 0, $joined, 'activity_join_count_invalid');
        $score = self::number($pool['score_total'] ?? null, 0, $rules['max_score'], 'activity_join_score_invalid');
        $target = self::number($pool['target_amount'] ?? null, 0, $rules['max_member_reward'], 'activity_join_target_invalid');
        $paid = self::number($pool['paid_total'] ?? null, 0, $rules['max_total_budget'], 'activity_join_budget_invalid');
        $current = PintogyaActivityRewardMath::calculate($rules, $joined, $completed, $score);
        if($target < $current['per_member'] ||
            ($target > 0 && $joined > intdiv($rules['max_total_budget'], $target)) ||
            $paid > $joined * $target) {
            throw new RuntimeException('activity_join_pool_mismatch');
        }
        return array('rules' => $rules, 'joined' => $joined, 'completed' => $completed,
            'score' => $score, 'target' => $target, 'paid' => $paid);
    }

    private static function number($value, $minimum, $maximum, $error) {
        if(!is_int($value)) {
            if(!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) { throw new RuntimeException($error); }
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new RuntimeException($error); }
            $value = $parsed;
        }
        if($value < $minimum || $value > $maximum) { throw new RuntimeException($error); }
        return $value;
    }

    private static function sameHash($left, $right) {
        return is_string($left) && preg_match('/^[0-9a-f]{64}$/D', $left) === 1 &&
            is_string($right) && preg_match('/^[0-9a-f]{64}$/D', $right) === 1 && hash_equals($left, $right);
    }
}
