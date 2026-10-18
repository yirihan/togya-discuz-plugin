<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_deadlines.php';

final class PintogyaActivitySettings {
    const ENABLED_KEY = 'pintogya_activity_posts_enabled';
    const FORUM_KEY = 'pintogya_activity_forum_fid';
    const PUBLISHER_GROUPS_KEY = 'pintogya_activity_publisher_groups';
    const REWARD_RULES_KEY = 'pintogya_activity_reward_defaults';
    const REVIEW_AUTO_DAYS_KEY = 'pintogya_activity_review_auto_days';
    const REVIEW_ENABLED_KEY = 'pintogya_activity_reviews_enabled';

    public static function reviewAutoDaysFromInput($input) {
        if(!is_array($input)) { throw new InvalidArgumentException('activity_review_days_invalid'); }
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if($keys !== array('checkin', 'completion')) { throw new InvalidArgumentException('activity_review_days_invalid'); }
        try {
            return array('checkin' => self::decimal($input['checkin'], 0, 365),
                'completion' => self::decimal($input['completion'], 0, 365));
        } catch(InvalidArgumentException $error) {
            throw new InvalidArgumentException('activity_review_days_invalid', 0, $error);
        }
    }

    public static function reviewAutoDaysFromJson($json) {
        if(!is_string($json) || $json === '' || strlen($json) > 64) {
            throw new InvalidArgumentException('activity_review_days_invalid');
        }
        try { $input = json_decode($json, true, 4, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new InvalidArgumentException('activity_review_days_invalid', 0, $error); }
        return self::reviewAutoDaysFromInput($input);
    }

    public static function selectedPublisherGroups($input, $available) {
        if(!is_array($input) || !is_array($available)) { throw new InvalidArgumentException('activity_groups_invalid'); }
        $valid = array();
        foreach($available as $row) {
            if(is_array($row) && isset($row['groupid']) && (int)$row['groupid'] > 0) {
                $valid[(int)$row['groupid']] = true;
            }
        }
        $selected = array();
        foreach($input as $value) {
            if((!is_string($value) && !is_int($value)) ||
                !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) ||
                (int)$value > 2147483647 || !isset($valid[(int)$value])) {
                throw new InvalidArgumentException('activity_groups_invalid');
            }
            $selected[(int)$value] = true;
        }
        ksort($selected, SORT_NUMERIC);
        $csv = implode(',', array_keys($selected));
        if(strlen($csv) > 1024) { throw new InvalidArgumentException('activity_groups_invalid'); }
        return $csv;
    }

    public static function publisherGroupIds($csv) {
        if(!is_string($csv) || $csv === '' || strlen($csv) > 1024) { return array(); }
        $ids = explode(',', $csv);
        $valid = array();
        foreach($ids as $value) {
            if(!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int)$value > 2147483647 ||
                isset($valid[(int)$value])) { return array(); }
            $valid[(int)$value] = true;
        }
        ksort($valid, SORT_NUMERIC);
        return implode(',', array_keys($valid)) === $csv ? array_keys($valid) : array();
    }

    public static function isPublisherGroupAllowed($groupId, $settings) {
        if(!is_array($settings) || !is_int($groupId) || $groupId < 1) { return false; }
        // TOGYA_PLUGIN pintogya_native_empty_groups_all: no extra filter; DX still checks actual posting rights.
        $csv = $settings[self::PUBLISHER_GROUPS_KEY] ?? '';
        return is_string($csv) && ($csv === '' || in_array($groupId, self::publisherGroupIds($csv), true));
    }

    public static function rewardInputFields() {
        return array('base', 'join_start', 'join_step', 'join_bonus', 'complete_start',
            'complete_step', 'complete_bonus', 'max_participants', 'max_member_reward',
            'max_score');
    }

    public static function rewardFields() {
        return array_merge(self::rewardInputFields(), array('max_total_budget'));
    }

    public static function rewardDefaultsFromInput($input, $extcredits) {
        if(!is_array($input) || !is_array($extcredits)) { throw new InvalidArgumentException('activity_reward_input_invalid'); }
        $expected = array_merge(array('credit_id'), self::rewardInputFields());
        $withDeadlines = array_key_exists('window_days', $input);
        if($withDeadlines) { $expected[] = 'window_days'; }
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if($actual !== $expected) { throw new InvalidArgumentException('activity_reward_fields_invalid'); }
        $creditId = self::decimal($input['credit_id'], 1, 8);
        if(!isset($extcredits[$creditId]) || !is_array($extcredits[$creditId])) {
            throw new InvalidArgumentException('activity_reward_credit_invalid');
        }
        $rules = array();
        foreach(self::rewardInputFields() as $field) {
            $minimum = in_array($field, array('join_step', 'complete_step', 'max_participants',
                'max_member_reward'), true) ? 1 : 0;
            $rules[$field] = self::decimal($input[$field], $minimum, 2147483647);
        }
        if($rules['join_start'] > $rules['max_participants'] ||
            $rules['complete_start'] > $rules['max_participants']) {
            throw new InvalidArgumentException('activity_reward_threshold_invalid');
        }
        require_once __DIR__.'/activity_reward_math.php';
        $rules['max_total_budget'] = PHP_INT_MAX;
        try { $worst = PintogyaActivityRewardMath::worstCase($rules); }
        catch(Throwable $error) { throw new InvalidArgumentException('activity_reward_budget_invalid', 0, $error); }
        if($worst['per_member'] < 1) { throw new InvalidArgumentException('activity_reward_empty'); }
        $rules['max_total_budget'] = $worst['projected_total'];
        $result = array('version' => $withDeadlines ? 2 : 1, 'credit_id' => $creditId, 'rules' => $rules);
        if($withDeadlines) { $result['window_days'] = PintogyaActivityDeadlines::days($input['window_days']); }
        return $result;
    }

    public static function rewardDefaultsFromJson($json, $extcredits) {
        if(!is_string($json) || $json === '' || strlen($json) > 4096) {
            throw new InvalidArgumentException('activity_reward_settings_invalid');
        }
        try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new InvalidArgumentException('activity_reward_settings_invalid', 0, $error); }
        if(!is_array($data) || !in_array($data['version'] ?? null, array(1, 2), true) ||
            array_keys($data) !== ($data['version'] === 2 ?
                array('version', 'credit_id', 'rules', 'window_days') : array('version', 'credit_id', 'rules')) ||
            !is_array($data['rules'])) {
            throw new InvalidArgumentException('activity_reward_settings_invalid');
        }
        $actualFields = array_keys($data['rules']);
        $expectedFields = self::rewardFields();
        sort($actualFields, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if($actualFields !== $expectedFields) { throw new InvalidArgumentException('activity_reward_settings_invalid'); }
        $storedBudget = self::decimal($data['rules']['max_total_budget'], 1, PHP_INT_MAX);
        $inputRules = $data['rules'];
        unset($inputRules['max_total_budget']);
        $defaults = self::rewardDefaultsFromInput(array('credit_id' => $data['credit_id']) + $inputRules +
            ($data['version'] === 2 ? array('window_days' => $data['window_days']) : array()), $extcredits);
        if($storedBudget < $defaults['rules']['max_total_budget']) {
            throw new InvalidArgumentException('activity_reward_budget_invalid');
        }
        $defaults['rules']['max_total_budget'] = $storedBudget;
        return $defaults;
    }

    private static function decimal($value, $minimum, $maximum) {
        if(!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('activity_reward_number_invalid'); }
        $value = (string)$value;
        if(!preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value)) {
            throw new InvalidArgumentException('activity_reward_number_invalid');
        }
        $number = (int)$value;
        if((string)$number !== $value || $number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException('activity_reward_number_invalid');
        }
        return $number;
    }

    // TOGYA_PLUGIN pintogya_admin_config_disabled_forum: staging is separate from runtime publication.
    public static function selectedForum($value, $forum, $requireEnabled = true) {
        if($value === '0' || $value === 0) { return 0; }
        if(!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('activity_forum_invalid'); }
        $value = (string)$value;
        if(!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int)$value > 2147483647 ||
            !is_array($forum) || (int)($forum['fid'] ?? 0) !== (int)$value ||
            !in_array($forum['type'] ?? '', array('forum', 'sub'), true) ||
            !in_array((int)($forum['status'] ?? -1), $requireEnabled ? array(1) : array(0, 1), true)) {
            throw new InvalidArgumentException('activity_forum_invalid');
        }
        return (int)$value;
    }

    public static function requiredPublishTables() {
        return array('pintogya_activity', 'pintogya_activity_action', 'pintogya_participation',
            'pintogya_activity_reward_rule', 'pintogya_activity_reward_pool',
            'pintogya_activity_reward_member', 'pintogya_activity_reward_payment');
    }

    public static function requiredReviewTables() {
        return array('pintogya_activity_review_case', 'pintogya_activity_review_decision');
    }

    public static function canEnableReviews($settings, $tables) {
        if(!is_array($settings) || !is_array($tables) ||
            (string)($settings[self::ENABLED_KEY] ?? '0') !== '1') { return false; }
        try { $days = self::reviewAutoDaysFromJson($settings[self::REVIEW_AUTO_DAYS_KEY] ?? ''); }
        catch(InvalidArgumentException $error) { return false; }
        if($days['checkin'] < 1 || $days['completion'] < 1) { return false; }
        foreach(self::requiredReviewTables() as $logical) {
            if(($tables[$logical] ?? false) !== true) { return false; }
        }
        return true;
    }

    public static function canEnable($settings, $forum, $extcredits, $tables, $groupThreadPlugins) {
        if(!is_array($settings) || !is_array($extcredits) || !is_array($tables) ||
            !is_array($groupThreadPlugins)) { return false; }
        $fid = $settings[self::FORUM_KEY] ?? '0';
        try {
            $csv = $settings[self::PUBLISHER_GROUPS_KEY] ?? '';
            $publisherGroups = self::publisherGroupIds($csv);
            if(self::selectedForum($fid, $forum) < 1 || !is_string($csv) || $csv !== implode(',', $publisherGroups) ||
                (int)($forum['allowspecialonly'] ?? 0) !== 1 ||
                (int)($forum['allowpostspecial'] ?? -1) !== 0 ||
                !is_array($forum['threadplugin'] ?? null) ||
                array_values($forum['threadplugin']) !== array('pintogya')) { return false; }
            foreach($publisherGroups as $groupId) {
                if(!is_array($groupThreadPlugins[$groupId] ?? null) ||
                    !in_array('pintogya', $groupThreadPlugins[$groupId], true)) { return false; }
            }
            $rules = self::rewardDefaultsFromJson($settings[self::REWARD_RULES_KEY] ?? null, $extcredits);
            if($rules['version'] !== 2) { return false; }
        } catch(InvalidArgumentException $error) { return false; }
        foreach(self::requiredPublishTables() as $logical) {
            if(($tables[$logical] ?? false) !== true) { return false; }
        }
        return true;
    }

    public static function adminModule() {
        return array('name' => 'admin', 'param' => '', 'menu' => 'Togya Plugin', 'url' => '',
            'type' => '3', 'adminid' => '1', 'displayorder' => '0', 'navtitle' => '',
            'navicon' => '', 'navsubname' => '', 'navsuburl' => '');
    }

    public static function threadModule() {
        return array('name' => 'thread', 'param' => '', 'menu' => '', 'url' => '',
            'type' => '12', 'adminid' => '0', 'displayorder' => '0', 'navtitle' => '',
            'navicon' => '', 'navsubname' => '', 'navsuburl' => '');
    }

    public static function hookModule($type) {
        if($type !== '11' && $type !== '28') { throw new InvalidArgumentException('activity_hook_type_invalid'); }
        return array('name' => 'plugin', 'menu' => '', 'type' => $type, 'adminid' => '0',
            'displayorder' => '0', 'navtitle' => '', 'navicon' => '', 'navsubname' => '',
            'paramstyle' => '0');
    }

    public static function withAdminModule($modules) {
        if(!is_array($modules)) { throw new InvalidArgumentException('activity_modules_invalid'); }
        foreach($modules as $module) {
            if(is_array($module) && ($module['name'] ?? '') === 'admin') {
                if((string)($module['type'] ?? '') !== '3' || (string)($module['adminid'] ?? '') !== '1') {
                    throw new RuntimeException('activity_admin_module_conflict');
                }
                return $modules;
            }
        }
        $modules[] = self::adminModule();
        return $modules;
    }

    public static function withModules($modules) {
        $modules = self::withAdminModule($modules);
        $hasThread = false;
        $hooks = array('11' => false, '28' => false);
        foreach($modules as $module) {
            if(is_array($module) && ($module['name'] ?? '') === 'thread') {
                if((string)($module['type'] ?? '') !== '12' || (string)($module['adminid'] ?? '') !== '0') {
                    throw new RuntimeException('activity_thread_module_conflict');
                }
                $hasThread = true;
            }
            if(is_array($module) && ($module['name'] ?? '') === 'plugin' &&
                array_key_exists((string)($module['type'] ?? ''), $hooks)) {
                if((string)($module['adminid'] ?? '') !== '0') {
                    throw new RuntimeException('activity_hook_module_conflict');
                }
                $hooks[(string)$module['type']] = true;
            }
        }
        if(!$hasThread) { $modules[] = self::threadModule(); }
        foreach($hooks as $type => $present) {
            if(!$present) { $modules[] = self::hookModule((string)$type); }
        }
        return $modules;
    }

    public static function registerModules() {
        $row = DB::fetch_first('SELECT pluginid,modules FROM %t WHERE identifier=%s', array('common_plugin', 'pintogya'));
        if(!$row) { return false; }
        $current = dunserialize($row['modules']);
        $updated = self::withModules($current);
        if($updated === $current) { return false; }
        DB::update('common_plugin', array('modules' => serialize($updated)), array('pluginid' => (int)$row['pluginid']));
        if(function_exists('updatecache')) { updatecache('plugin'); }
        return true;
    }
}
