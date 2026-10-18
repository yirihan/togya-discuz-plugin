<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/activity_official_examples.php';
require_once __DIR__.'/activity_settings.php';

final class PintogyaActivityInstantConfig {
    const OFFICIAL_KEY = 'pintogya_activity_official_defaults';
    const RATING_LIMIT_KEY = 'pintogya_activity_rating_total_cap';
    const DEFAULT_RATING_LIMIT = 200;

    public static function ratingLimitChanges($value) {
        if((!is_string($value) && !is_int($value)) ||
            !preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$value) ||
            (int)$value > 2147483647) {
            throw new InvalidArgumentException('activity_rating_limit_invalid');
        }
        // A stored safety limit never enables rating rewards or changes frozen stage amounts.
        return array(self::RATING_LIMIT_KEY=>(string)$value);
    }

    public static function publicationChanges($input, $forum, $groups, $settings) {
        if(!is_array($input) || !self::keys($input,array('forum_fid','publisher_groups'))) {
            throw new InvalidArgumentException('instant_publication_fields_invalid');
        }
        $fid=PintogyaActivitySettings::selectedForum($input['forum_fid'],$forum,false);
        $selected=PintogyaActivitySettings::selectedPublisherGroups($input['publisher_groups'],$groups);
        $changes=array(PintogyaActivitySettings::FORUM_KEY=>(string)$fid,
            PintogyaActivitySettings::PUBLISHER_GROUPS_KEY=>$selected);
        if((string)($settings[PintogyaActivitySettings::FORUM_KEY] ?? '0') !== (string)$fid ||
            (string)($settings[PintogyaActivitySettings::PUBLISHER_GROUPS_KEY] ?? '') !== $selected) {
            $changes[PintogyaActivitySettings::ENABLED_KEY]='0';
        }
        return $changes;
    }

    public static function requiredTables($operation) {
        $core=array('pintogya_binding','pintogya_activity','pintogya_participation',
            'pintogya_activity_instant_rule','pintogya_activity_instant_reward');
        if($operation === 'publication') {
            return array_merge($core,array('pintogya_challenge','pintogya_task_context','pintogya_activity_action'));
        }
        if($operation === 'sync' || $operation === 'payment') {
            $core=array_merge($core,array('common_member_count','common_credit_log','common_credit_log_field'));
            return $operation === 'sync' ? array_merge($core,array('pintogya_activity_instant_sync')) : $core;
        }
        throw new InvalidArgumentException('instant_operation_invalid');
    }

    public static function operationChanges($input, $settings, $forum, $credits, $tables, $permissions, $groups) {
        if(!is_array($input) || !self::keys($input,array('payment','publication','sync'))) {
            throw new InvalidArgumentException('instant_operation_invalid');
        }
        $keys=array('publication'=>PintogyaActivitySettings::ENABLED_KEY,
            'sync'=>PintogyaActivityInstantRule::SYNC_ENABLED_KEY,'payment'=>PintogyaActivityInstantRule::ENABLED_KEY);
        $changes=array();
        foreach($keys as $operation=>$key) {
            if($input[$operation] !== '0' && $input[$operation] !== '1') {
                throw new InvalidArgumentException('instant_operation_invalid');
            }
            if($input[$operation] === '1') {
                foreach(self::requiredTables($operation) as $logical) {
                    if(($tables[$logical] ?? false) !== true) { throw new RuntimeException('instant_operation_schema_unavailable'); }
                }
                if($operation === 'publication') {
                    self::defaultsFromJson($settings[PintogyaActivityInstantRule::SETTING_KEY] ?? '',$credits);
                    $csv=$settings[PintogyaActivitySettings::PUBLISHER_GROUPS_KEY] ?? '';
                    $ids=PintogyaActivitySettings::publisherGroupIds($csv);
                    if(PintogyaActivitySettings::selectedForum($settings[PintogyaActivitySettings::FORUM_KEY] ?? '0',$forum)<1 ||
                        !is_string($csv) || $csv!==implode(',',$ids) ||
                        (int)($forum['allowspecialonly'] ?? 0)!==1 || (int)($forum['allowpostspecial'] ?? -1)!==0 ||
                        !is_array($forum['threadplugin'] ?? null) || array_values($forum['threadplugin'])!==array('pintogya') ||
                        PintogyaActivitySettings::selectedPublisherGroups($ids,$groups)!==implode(',',$ids)) {
                        throw new RuntimeException('instant_publication_permissions_invalid');
                    }
                    foreach($ids as $id) {
                        if(!is_array($permissions[$id] ?? null) || !in_array('pintogya',$permissions[$id],true)) {
                            throw new RuntimeException('instant_publication_permissions_invalid');
                        }
                    }
                }
            }
            $changes[$key]=$input[$operation];
        }
        // Discovery and payment can be paused independently; frozen unpaid rights never need future defaults.
        return $changes;
    }

    public static function settingsKeys() {
        $keys=array(self::OFFICIAL_KEY,self::RATING_LIMIT_KEY,PintogyaActivityInstantRule::SETTING_KEY,
            PintogyaActivitySettings::FORUM_KEY,PintogyaActivitySettings::PUBLISHER_GROUPS_KEY,
            PintogyaActivitySettings::ENABLED_KEY,PintogyaActivityInstantRule::SYNC_ENABLED_KEY,
            PintogyaActivityInstantRule::ENABLED_KEY);
        sort($keys,SORT_STRING); return $keys;
    }

    public static function defaultsFromJson($json, $credits) {
        if(!is_string($json) || $json === '' || strlen($json) > 512) {
            throw new InvalidArgumentException('instant_defaults_unconfigured');
        }
        try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new InvalidArgumentException('instant_defaults_invalid', 0, $error); }
        if(!is_array($value) || ($value['version'] ?? null) !== PintogyaActivityInstantRule::VERSION) {
            throw new InvalidArgumentException('instant_defaults_invalid');
        }
        unset($value['version']);
        return PintogyaActivityInstantRule::fromInput($value, $credits);
    }

    public static function defaultChanges($input, $credits) {
        $defaults = PintogyaActivityInstantRule::fromInput($input, $credits);
        // Only future defaults change. Saving is not permission to enable sync or payment.
        return array(PintogyaActivityInstantRule::SETTING_KEY => PintogyaCrypto::canonicalJson($defaults));
    }

    public static function officialSelection($input, $member) {
        if(!is_array($input) || !self::keys($input, array('activity_id','publisher_uid')) ||
            !is_string($input['activity_id']) || !isset(PintogyaActivityOfficialExamples::choices()[$input['activity_id']])) {
            throw new InvalidArgumentException('official_example_selection_invalid');
        }
        $raw = $input['publisher_uid'];
        if((!is_string($raw) && !is_int($raw)) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$raw) ||
            (int)$raw > 16777215 || !is_array($member) || (string)($member['uid'] ?? '') !== (string)$raw) {
            throw new InvalidArgumentException('official_example_publisher_invalid');
        }
        // This is a local draft selection, not Togya ownership or native posting approval.
        return array('activity_id'=>$input['activity_id'], 'publisher_uid'=>(int)$raw);
    }

    public static function officialFromJson($json, $member) {
        if(!is_string($json) || $json === '' || strlen($json) > 256) {
            throw new InvalidArgumentException('official_example_selection_invalid');
        }
        try { $input = json_decode($json, true, 4, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new InvalidArgumentException('official_example_selection_invalid', 0, $error); }
        return self::officialSelection($input, $member);
    }

    // TOGYA_PLUGIN pintogya_official_multiselect: old single-choice drafts remain readable.
    public static function officialSelections($input, $member) {
        if(is_array($input) && self::keys($input,array('activity_id','publisher_uid'))) {
            $single=self::officialSelection($input,$member);
            return array('activity_ids'=>array($single['activity_id']),'publisher_uid'=>$single['publisher_uid']);
        }
        if(!is_array($input) || !self::keys($input,array('activity_ids','publisher_uid')) ||
            !is_array($input['activity_ids']) || !array_is_list($input['activity_ids']) ||
            count($input['activity_ids'])<1 || count($input['activity_ids'])>3) {
            throw new InvalidArgumentException('official_example_selection_invalid');
        }
        $selected=array(); $uid=null;
        foreach($input['activity_ids'] as $id) {
            $single=self::officialSelection(array('activity_id'=>$id,'publisher_uid'=>$input['publisher_uid']),$member);
            $selected[$single['activity_id']]=true; $uid=$single['publisher_uid'];
        }
        return array('activity_ids'=>array_keys(array_intersect_key(PintogyaActivityOfficialExamples::choices(),$selected)),
            'publisher_uid'=>$uid);
    }

    public static function officialSelectionsFromJson($json, $member) {
        if(!is_string($json) || $json==='' || strlen($json)>512) {
            throw new InvalidArgumentException('official_example_selection_invalid');
        }
        try { $input=json_decode($json,true,4,JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new InvalidArgumentException('official_example_selection_invalid',0,$error); }
        return self::officialSelections($input,$member);
    }

    public static function draftRule($stored, $input, $credits) {
        if(!is_array($stored) || !is_string($stored['rule_json'] ?? null) ||
            !is_string($stored['rule_hash'] ?? null) || (int)($stored['published_at'] ?? -1) !== 0 ||
            !in_array($stored['state_code'] ?? '', array('active','held'), true)) {
            throw new RuntimeException('instant_published_rule_immutable');
        }
        $rule = PintogyaActivityInstantRule::assertSnapshot(json_decode($stored['rule_json'], true, 512, JSON_THROW_ON_ERROR));
        if($rule['activity_id'] !== ($stored['activity_id'] ?? null) ||
            !hash_equals(PintogyaCrypto::digest('pintogya_instant_rule_v3', $rule), $stored['rule_hash'])) {
            throw new RuntimeException('instant_rule_corrupt');
        }
        if(!is_array($input) || !self::keys($input, array('base','stages')) || !is_array($input['stages'])) {
            throw new InvalidArgumentException('instant_draft_fields_invalid');
        }
        $defaults = PintogyaActivityInstantRule::fromInput(array('credit_id'=>$rule['credit_id'],
            'base'=>$input['base'], 'stage_default'=>0), $credits);
        $stages = array_values($rule['stages']);
        usort($stages, static function($a,$b) { return $a['position'] <=> $b['position']; });
        $expected = array(); $source = array();
        foreach($stages as $stage) {
            $source[] = array('id'=>$stage['id'], 'position'=>$stage['position'], 'version'=>$rule['stage_version'],
                'stageKind'=>$stage['kind'], 'title'=>$stage['title']);
            if($stage['kind'] === 'tutorial') { $expected[] = $stage['id']; }
        }
        sort($expected, SORT_STRING); $actual = array_keys($input['stages']); sort($actual, SORT_STRING);
        if($expected !== $actual) { throw new InvalidArgumentException('instant_draft_stages_invalid'); }
        return PintogyaActivityInstantRule::snapshot($defaults, array('id'=>$rule['activity_id'],
            'stageVersion'=>$rule['stage_version'], 'totalStages'=>count($source), 'stageDetails'=>$source), $input['stages']);
    }

    private static function keys($input, $expected) {
        $keys=array_keys($input); sort($keys,SORT_STRING); return $keys === $expected;
    }
}

final class PintogyaActivityInstantRuleStore {
    public static function readSettings($settings) {
        $keys=PintogyaActivityInstantConfig::settingsKeys();
        $rows=DB::fetch_all('SELECT skey,svalue FROM '.DB::table('common_setting').' WHERE skey IN ('
            .implode(',',array_fill(0,count($keys),'%s')).') ORDER BY skey',$keys);
        foreach($keys as $key) { unset($settings[$key]); }
        foreach($rows as $row) { if(in_array($row['skey'],$keys,true)) { $settings[$row['skey']]=(string)$row['svalue']; } }
        return $settings;
    }

    public static function saveSettings($changes, $expected=array()) {
        $keys=PintogyaActivityInstantConfig::settingsKeys();
        foreach(array($changes,$expected) as $values) {
            if(!is_array($values) || array_diff(array_keys($values),$keys)) { throw new InvalidArgumentException('instant_setting_invalid'); }
            foreach($values as $value) { if(!is_string($value)) { throw new InvalidArgumentException('instant_setting_invalid'); } }
        }
        $engine=DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            array(DB::table('common_setting')));
        if(strtoupper((string)$engine)!=='INNODB') { throw new RuntimeException('instant_settings_not_transactional'); }
        $started=false; $committing=false;
        try {
            if(DB::query('START TRANSACTION')===false) { throw new RuntimeException('instant_settings_begin_failed'); }
            $started=true;
            $rows=DB::fetch_all('SELECT skey,svalue FROM '.DB::table('common_setting').' WHERE skey IN ('
                .implode(',',array_fill(0,count($keys),'%s')).') ORDER BY skey FOR UPDATE',$keys);
            $current=array(); foreach($rows as $row) { $current[$row['skey']]=(string)$row['svalue']; }
            foreach($expected as $key=>$value) {
                if(($current[$key] ?? '')!==$value) { throw new RuntimeException('instant_settings_changed_reload'); }
            }
            ksort($changes,SORT_STRING);
            foreach($changes as $key=>$value) {
                if(DB::insert('common_setting',array('skey'=>$key,'svalue'=>$value),false,true)===false) {
                    throw new RuntimeException('instant_settings_write_failed');
                }
            }
            $committing=true;
            if(DB::query('COMMIT')===false) { throw new RuntimeException('instant_settings_commit_failed'); }
            $started=false; $committing=false;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('instant_settings_outcome_unknown',0,$error); }
            if($started) {
                try { if(DB::query('ROLLBACK')===false) { throw new RuntimeException('rollback_failed'); } }
                catch(Throwable $rollback) { throw new RuntimeException('instant_settings_outcome_unknown',0,$rollback); }
            }
            throw $error;
        }
    }

    public static function installed() {
        foreach(array('pintogya_activity','pintogya_activity_instant_rule','pintogya_activity_instant_reward') as $logical) {
            $engine=DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                array(DB::table($logical)));
            if(strtoupper((string)$engine) !== 'INNODB') { return false; }
        }
        return true;
    }

    public static function saveDraft($activityId, $input, $credits, $now) {
        PintogyaCrypto::assertUuid($activityId);
        if(!is_int($now) || $now < 1 || $now > 4294967295 || !self::installed()) {
            throw new RuntimeException('instant_config_schema_unavailable');
        }
        $started=false; $committing=false;
        try {
            if(DB::query('START TRANSACTION') === false) { throw new RuntimeException('instant_config_begin_failed'); }
            $started=true;
            // Same map-before-rule order as entitlement establishment. No balance or log writes.
            $map=DB::fetch_first('SELECT tid,published_at,state_code FROM '.DB::table('pintogya_activity').
                ' WHERE activity_id=%s LIMIT 1 FOR UPDATE',array($activityId));
            if($map && ((int)$map['tid'] > 0 || (int)$map['published_at'] > 0 || $map['state_code'] === 'active')) {
                throw new RuntimeException('instant_published_rule_immutable');
            }
            $stored=DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_instant_rule').
                ' WHERE activity_id=%s LIMIT 1 FOR UPDATE',array($activityId));
            $rule=PintogyaActivityInstantConfig::draftRule($stored,$input,$credits);
            if((int)DB::result_first('SELECT COUNT(*) FROM '.DB::table('pintogya_activity_instant_reward').
                ' WHERE activity_id=%s',array($activityId)) !== 0) { throw new RuntimeException('instant_published_rule_immutable'); }
            if(DB::update('pintogya_activity_instant_rule',array('rule_json'=>PintogyaCrypto::canonicalJson($rule),
                'rule_hash'=>PintogyaCrypto::digest('pintogya_instant_rule_v3',$rule)),
                "activity_id='".$activityId."' AND published_at=0") === false) {
                throw new RuntimeException('instant_config_update_failed');
            }
            $committing=true;
            if(DB::query('COMMIT') === false) { throw new RuntimeException('instant_config_commit_failed'); }
            $started=false; $committing=false; return $rule;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('instant_config_outcome_unknown',0,$error); }
            if($started) {
                try { if(DB::query('ROLLBACK') === false) { throw new RuntimeException('rollback_failed'); } }
                catch(Throwable $rollback) { throw new RuntimeException('instant_config_outcome_unknown',0,$rollback); }
            }
            throw $error;
        }
    }
}
