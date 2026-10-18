<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityNativeCreditEntry {
    const OPERATION = 'TGA';
    const MAX_UID = 16777215;
    const MAX_UNSIGNED = 4294967295;
    const MAX_BALANCE = 2147483647;
    const MIN_BALANCE = -2147483648;

    public static function build($uid, $creditId, $amount, $paymentPk, $now, $balances, $context = null) {
        self::integer($uid, 1, self::MAX_UID, 'credit_uid_invalid');
        self::integer($creditId, 1, 8, 'credit_id_invalid');
        self::integer($amount, 1, self::MAX_BALANCE, 'credit_amount_invalid');
        self::integer($paymentPk, 1, self::MAX_UNSIGNED, 'credit_payment_invalid');
        self::integer($now, 1, self::MAX_UNSIGNED, 'credit_time_invalid');
        if(!is_array($balances)) { throw new InvalidArgumentException('credit_balances_invalid'); }

        $main = array('uid' => $uid, 'operation' => self::OPERATION, 'relatedid' => $paymentPk, 'dateline' => $now);
        $field = array('uid' => $uid, 'title' => 'Togya activity reward',
            'text' => 'Settlement #'.$paymentPk, 'dateline' => $now);
        if($context !== null) {
            if(!is_array($context) || array_keys($context) !== array('activity_id','kind','stage_id') ||
                !in_array($context['kind'], array('stage','base'), true) ||
                !self::contextUuid($context['activity_id']) ||
                ($context['kind'] === 'stage' ? !self::contextUuid($context['stage_id']) : $context['stage_id'] !== null)) {
                throw new InvalidArgumentException('credit_context_invalid');
            }
            $field['title'] = $context['kind'] === 'stage' ? 'Togya stage reward' : 'Togya Finish Story reward';
            $field['text'] = 'Activity '.$context['activity_id'].'; '
                .($context['kind'] === 'stage' ? 'Stage '.$context['stage_id'].'; ' : 'Finish Story; ')
                .'Settlement #'.$paymentPk;
        }
        for($i = 1; $i <= 8; $i++) {
            $key = 'extcredits'.$i;
            if(!array_key_exists($key, $balances)) { throw new InvalidArgumentException('credit_balance_missing'); }
            self::integer($balances[$key], self::MIN_BALANCE, self::MAX_BALANCE, 'credit_balance_invalid');
            $main[$key] = $i === $creditId ? $amount : 0;
            $after = $balances[$key] + ($i === $creditId ? $amount : 0);
            if($after > self::MAX_BALANCE) { throw new OverflowException('credit_balance_overflow'); }
            $field['ac_'.$key] = $after;
        }
        return array('main' => $main, 'field' => $field,
            'balance_before' => $balances['extcredits'.$creditId],
            'balance_after' => $field['ac_extcredits'.$creditId]);
    }

    public static function verifyExactRows($entry, $logId, $mainRows, $fieldRows) {
        self::integer($logId, 1, self::MAX_UNSIGNED, 'credit_log_id_invalid');
        if(!is_array($entry) || !isset($entry['main'], $entry['field']) ||
            !is_array($mainRows) || count($mainRows) !== 1 || !is_array($fieldRows) || count($fieldRows) !== 1) {
            throw new RuntimeException('credit_log_cardinality_invalid');
        }
        $main = $entry['main'];
        $field = $entry['field'];
        $main['logid'] = $logId;
        $field['logid'] = $logId;
        foreach(array('main' => array($main, reset($mainRows)), 'field' => array($field, reset($fieldRows))) as $rows) {
            list($expected, $actual) = $rows;
            if(!is_array($actual)) { throw new RuntimeException('credit_log_row_invalid'); }
            foreach($expected as $key => $value) {
                if(!array_key_exists($key, $actual) ||
                    !(is_int($value) ? self::sameSqlInteger($actual[$key], $value) : $actual[$key] === $value)) {
                    throw new RuntimeException('credit_log_row_mismatch:'.$key);
                }
            }
        }
        return true;
    }

    private static function integer($value, $minimum, $maximum, $error) {
        if(!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($error);
        }
    }

    private static function sameSqlInteger($value, $expected) {
        return $value === $expected || (is_string($value) && $value === (string)$expected);
    }

    private static function contextUuid($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }
}
