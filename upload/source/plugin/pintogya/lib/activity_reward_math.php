<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityRewardMath {
    const MAX_CREDIT_DELTA = 2147483647;

    public static function calculate($rules, $joined, $completed, $score) {
        if(!is_array($rules)) { throw new InvalidArgumentException('reward_rules_invalid'); }
        $base = self::number($rules, 'base');
        $joinStart = self::number($rules, 'join_start');
        $joinStep = self::number($rules, 'join_step', 1);
        $joinBonus = self::number($rules, 'join_bonus');
        $completeStart = self::number($rules, 'complete_start');
        $completeStep = self::number($rules, 'complete_step', 1);
        $completeBonus = self::number($rules, 'complete_bonus');
        $maxParticipants = self::number($rules, 'max_participants', 1);
        $maxMemberReward = self::number($rules, 'max_member_reward', 1, self::MAX_CREDIT_DELTA);
        $maxScore = self::number($rules, 'max_score', 0, self::MAX_CREDIT_DELTA);
        $maxTotalBudget = self::number($rules, 'max_total_budget');

        self::assertInteger($joined, 0, $maxParticipants, 'joined_count_invalid');
        self::assertInteger($completed, 0, $joined, 'completed_count_invalid');
        self::assertInteger($score, 0, $maxScore, 'score_invalid');

        $joinSteps = intdiv(max(0, $joined - $joinStart), $joinStep);
        $completeSteps = intdiv(max(0, $completed - $completeStart), $completeStep);
        $reward = self::safeAdd($base, self::safeMultiply($joinSteps, $joinBonus, self::MAX_CREDIT_DELTA), self::MAX_CREDIT_DELTA);
        $reward = self::safeAdd($reward, self::safeMultiply($completeSteps, $completeBonus, self::MAX_CREDIT_DELTA), self::MAX_CREDIT_DELTA);
        $reward = self::safeAdd($reward, $score, self::MAX_CREDIT_DELTA);
        if($reward > $maxMemberReward) { throw new DomainException('member_reward_limit'); }

        $projectedTotal = self::safeMultiply($joined, $reward, PHP_INT_MAX);
        if($projectedTotal > $maxTotalBudget) { throw new DomainException('activity_budget_limit'); }
        return array('per_member' => $reward, 'projected_total' => $projectedTotal);
    }

    public static function worstCase($rules) {
        if(!is_array($rules)) { throw new InvalidArgumentException('reward_rules_invalid'); }
        $maximum = self::number($rules, 'max_participants', 1);
        $score = self::number($rules, 'max_score', 0, self::MAX_CREDIT_DELTA);
        return self::calculate($rules, $maximum, $maximum, $score);
    }

    public static function due($previousTarget, $candidate, $paid) {
        self::assertInteger($previousTarget, 0, self::MAX_CREDIT_DELTA, 'target_invalid');
        self::assertInteger($candidate, 0, self::MAX_CREDIT_DELTA, 'candidate_invalid');
        self::assertInteger($paid, 0, self::MAX_CREDIT_DELTA, 'paid_invalid');
        $target = max($previousTarget, $candidate);
        if($paid > $target) { throw new DomainException('paid_exceeds_target'); }
        return array('target' => $target, 'due' => $target - $paid);
    }

    private static function number($values, $key, $minimum = 0, $maximum = PHP_INT_MAX) {
        if(!array_key_exists($key, $values)) { throw new InvalidArgumentException('reward_rule_missing:'.$key); }
        self::assertInteger($values[$key], $minimum, $maximum, 'reward_rule_invalid:'.$key);
        return $values[$key];
    }

    private static function assertInteger($value, $minimum, $maximum, $message) {
        if(!is_int($value) || $value < $minimum || $value > $maximum) { throw new InvalidArgumentException($message); }
    }

    private static function safeAdd($left, $right, $limit) {
        if($right > $limit - $left) { throw new OverflowException('reward_overflow'); }
        return $left + $right;
    }

    private static function safeMultiply($left, $right, $limit) {
        if($left > 0 && $right > intdiv($limit, $left)) { throw new OverflowException('reward_overflow'); }
        return $left * $right;
    }
}
