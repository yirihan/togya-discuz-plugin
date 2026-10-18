<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityDeadlines {
    const MAX_TIME = 4294967295;

    public static function days($input) {
        if(!is_array($input) || array_keys($input) !== array('join', 'checkin', 'score', 'review', 'close')) {
            throw new InvalidArgumentException('activity_deadline_fields_invalid');
        }
        $days = array();
        $previous = 0;
        foreach($input as $name => $value) {
            if(!is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException('activity_deadline_days_invalid');
            }
            $value = (string)$value;
            if(!preg_match('/^[1-9][0-9]{0,2}$/D', $value) || (int)$value <= $previous || (int)$value > 365) {
                throw new InvalidArgumentException('activity_deadline_days_invalid');
            }
            $days[$name] = (int)$value;
            $previous = (int)$value;
        }
        return $days;
    }

    public static function cutoffs($publishedAt, $days) {
        if(!is_int($publishedAt) || $publishedAt < 1 || $publishedAt > self::MAX_TIME) {
            throw new InvalidArgumentException('activity_published_time_invalid');
        }
        $days = self::days($days);
        $cutoffs = array();
        foreach($days as $name => $value) {
            $delta = $value * 86400;
            if($publishedAt > self::MAX_TIME - $delta) {
                throw new InvalidArgumentException('activity_deadline_overflow');
            }
            $cutoffs[$name] = $publishedAt + $delta;
        }
        return $cutoffs;
    }

    public static function assertOpen($publishedAt, $days, $name, $now) {
        if(!is_int($now) || $now < 1 || $now > self::MAX_TIME ||
            !in_array($name, array('join', 'checkin', 'score', 'review', 'close'), true)) {
            throw new InvalidArgumentException('activity_deadline_check_invalid');
        }
        $cutoffs = self::cutoffs($publishedAt, $days);
        if($now >= $cutoffs[$name]) { throw new RuntimeException('activity_'.$name.'_closed'); }
    }
}
