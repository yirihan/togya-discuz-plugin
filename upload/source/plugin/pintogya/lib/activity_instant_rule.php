<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/crypto.php';
require_once __DIR__.'/installation.php';

final class PintogyaActivityInstantRule {
    const VERSION = 3;
    const MAX_AMOUNT = 2147483647;
    const SETTING_KEY = 'pintogya_activity_instant_defaults';
    const ENABLED_KEY = 'pintogya_activity_instant_enabled';
    const SYNC_ENABLED_KEY = 'pintogya_activity_instant_sync_enabled';

    public static function fromInput($input, $credits) {
        if(!is_array($input) || !is_array($credits)) { throw new InvalidArgumentException('instant_rules_invalid'); }
        $keys = array_keys($input); sort($keys, SORT_STRING);
        if($keys !== array('base', 'credit_id', 'stage_default')) { throw new InvalidArgumentException('instant_rules_fields'); }
        $credit = self::inputNumber($input['credit_id'], 1, 8);
        if(!isset($credits[$credit]) || !is_array($credits[$credit])) { throw new InvalidArgumentException('instant_credit_unconfigured'); }
        return array('version'=>self::VERSION, 'credit_id'=>$credit,
            'base'=>self::inputNumber($input['base'], 0, self::MAX_AMOUNT),
            'stage_default'=>self::inputNumber($input['stage_default'], 0, self::MAX_AMOUNT));
    }

    public static function snapshot($defaults, $activity, $overrides = array()) {
        self::assertDefaults($defaults);
        if(!is_array($activity) || !is_array($overrides)) { throw new InvalidArgumentException('instant_activity_invalid'); }
        $id = PintogyaCrypto::assertUuid($activity['id'] ?? null);
        $version = self::number($activity['stageVersion'] ?? null, 1, 2147483647);
        $total = self::number($activity['totalStages'] ?? null, 1, 100);
        $source = $activity['stageDetails'] ?? null;
        if(!is_array($source) || !array_is_list($source) || count($source) !== $total) {
            throw new InvalidArgumentException('instant_stages_invalid');
        }
        $stages = array(); $sum = $defaults['base']; $finish = null; $tutorialIds = array();
        foreach($source as $position => $stage) {
            if(!is_array($stage) || ($stage['position'] ?? null) !== $position ||
                ($stage['version'] ?? null) !== $version ||
                !in_array($stage['stageKind'] ?? null, array('tutorial','finish_story'), true) ||
                !is_string($stage['title'] ?? null) || trim($stage['title']) === '' || strlen($stage['title']) > 500) {
                throw new InvalidArgumentException('instant_stages_invalid');
            }
            $stageId = PintogyaCrypto::assertUuid($stage['id'] ?? null);
            if(isset($stages[$stageId])) { throw new InvalidArgumentException('instant_duplicate_stage'); }
            $kind = $stage['stageKind'];
            if($kind === 'finish_story') {
                if($finish !== null || $position !== $total - 1 || array_key_exists($stageId, $overrides)) {
                    throw new InvalidArgumentException('instant_finish_stage_invalid');
                }
                $finish = $stageId;
                $amount = 0;
            } else {
                $tutorialIds[] = $stageId;
                $amount = array_key_exists($stageId, $overrides)
                    ? self::inputNumber($overrides[$stageId], 0, self::MAX_AMOUNT) : $defaults['stage_default'];
                if($amount > self::MAX_AMOUNT - $sum) { throw new OverflowException('instant_total_overflow'); }
                $sum += $amount;
            }
            $stages[$stageId] = array('id'=>$stageId, 'position'=>$position, 'kind'=>$kind,
                'title'=>$stage['title'], 'amount'=>$amount);
        }
        foreach($overrides as $stageId => $amount) {
            if(!is_string($stageId) || !in_array($stageId, $tutorialIds, true)) {
                throw new InvalidArgumentException('instant_override_unknown');
            }
        }
        return array('version'=>self::VERSION, 'activity_id'=>$id, 'stage_version'=>$version,
            'credit_id'=>$defaults['credit_id'], 'base'=>$defaults['base'], 'stages'=>$stages,
            'finish_stage_id'=>$finish, 'total'=>$sum);
    }

    public static function assertSnapshot($rule) {
        if(!is_array($rule) || ($rule['version'] ?? null) !== self::VERSION ||
            !is_array($rule['stages'] ?? null) || count($rule['stages']) < 1 || count($rule['stages']) > 100) {
            throw new InvalidArgumentException('instant_snapshot_invalid');
        }
        $keys = array_keys($rule); sort($keys, SORT_STRING);
        if($keys !== array('activity_id','base','credit_id','finish_stage_id','stage_version','stages','total','version')) {
            throw new InvalidArgumentException('instant_snapshot_invalid');
        }
        PintogyaCrypto::assertUuid($rule['activity_id']);
        self::number($rule['stage_version'], 1, 2147483647);
        self::number($rule['credit_id'], 1, 8);
        $sum = self::number($rule['base'], 0, self::MAX_AMOUNT); $finish = null; $positions = array();
        foreach($rule['stages'] as $id => $stage) {
            if(PintogyaCrypto::assertUuid($id) !== $id || !is_array($stage) ||
                !self::hasKeys($stage, array('amount','id','kind','position','title')) ||
                $stage['id'] !== $id || !is_int($stage['position']) || $stage['position'] < 0 ||
                $stage['position'] >= count($rule['stages']) || isset($positions[$stage['position']]) ||
                !in_array($stage['kind'], array('tutorial','finish_story'), true) ||
                !is_string($stage['title']) || trim($stage['title']) === '' || strlen($stage['title']) > 500) {
                throw new InvalidArgumentException('instant_snapshot_invalid');
            }
            $positions[$stage['position']] = true;
            $amount = self::number($stage['amount'], 0, self::MAX_AMOUNT);
            if($stage['kind'] === 'finish_story') {
                if($finish !== null || $stage['position'] !== count($rule['stages']) - 1 || $amount !== 0) {
                    throw new InvalidArgumentException('instant_snapshot_invalid');
                }
                $finish = $id;
            } else {
                if($amount > self::MAX_AMOUNT - $sum) { throw new OverflowException('instant_total_overflow'); }
                $sum += $amount;
            }
        }
        if($rule['finish_stage_id'] !== $finish || $rule['total'] !== $sum) {
            throw new InvalidArgumentException('instant_snapshot_invalid');
        }
        return $rule;
    }

    public static function rewardKey($site, $activityId, $togyaUserId, $kind, $stageId = null) {
        if(!PintogyaInstallation::validScope($site) ||
            !in_array($kind, array('stage','base'), true)) { throw new InvalidArgumentException('instant_key_invalid'); }
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $togyaUserId = PintogyaCrypto::assertUuid($togyaUserId);
        if($kind === 'stage') { $stageId = PintogyaCrypto::assertUuid($stageId); }
        elseif($stageId !== null) { throw new InvalidArgumentException('instant_key_invalid'); }
        // Mutable facts, amounts, binding generations and plan versions never create another entitlement.
        return PintogyaCrypto::digest('pintogya_instant_reward_v1', array($site, $activityId, $togyaUserId, $kind, $stageId));
    }

    public static function storedSnapshot($row) {
        if(!is_array($row) || !is_string($row['rule_json'] ?? null) || strlen($row['rule_json']) > 65536 ||
            !is_string($row['rule_hash'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D',$row['rule_hash'])) {
            throw new RuntimeException('instant_rule_corrupt');
        }
        $rule = self::assertSnapshot(json_decode($row['rule_json'],true,512,JSON_THROW_ON_ERROR));
        if(($row['activity_id'] ?? null) !== $rule['activity_id'] ||
            !hash_equals(PintogyaCrypto::digest('pintogya_instant_rule_v3',$rule),$row['rule_hash'])) {
            throw new RuntimeException('instant_rule_corrupt');
        }
        return $rule;
    }

    private static function assertDefaults($defaults) {
        if(!is_array($defaults) || !self::hasKeys($defaults, array('base','credit_id','stage_default','version')) ||
            $defaults['version'] !== self::VERSION) { throw new InvalidArgumentException('instant_rules_invalid'); }
        self::number($defaults['credit_id'], 1, 8);
        self::number($defaults['base'], 0, self::MAX_AMOUNT);
        self::number($defaults['stage_default'], 0, self::MAX_AMOUNT);
    }

    private static function inputNumber($value, $minimum, $maximum) {
        if(is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value)) { $value = (int)$value; }
        return self::number($value, $minimum, $maximum);
    }

    private static function hasKeys($value, $expected) {
        $keys = array_keys($value); sort($keys, SORT_STRING);
        return $keys === $expected;
    }

    private static function number($value, $minimum, $maximum) {
        if(!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('instant_number_invalid');
        }
        return $value;
    }
}
