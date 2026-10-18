<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/crypto.php';

final class PintogyaActivityOfficialExamples {
    const CREATOR_ID = '00000000-0000-4000-8000-000000000010';

    public static function choices() {
        return array(
            '30000000-0000-4000-8000-000000000001'=>array('slug'=>'granny-square', 'label'=>'Make Your First Granny Square Together'),
            '30000000-0000-4000-8000-000000000002'=>array('slug'=>'colorful-pasta', 'label'=>'Cook Colorful Pasta Together'),
            '30000000-0000-4000-8000-000000000003'=>array('slug'=>'fitness-together', 'label'=>'Fitness Together'),
        );
    }

    public static function verifyPublic($activityId, $detail) {
        $choice = self::choices()[$activityId] ?? null;
        if(!$choice || !is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
            ($detail['isExample'] ?? null) !== true || ($detail['exampleSlug'] ?? null) !== $choice['slug'] ||
            ($detail['creator']['id'] ?? null) !== self::CREATOR_ID ||
            ($detail['isPublic'] ?? null) !== true || ($detail['status'] ?? null) !== 'active' ||
            !is_int($detail['stageVersion'] ?? null) || $detail['stageVersion'] < 1) {
            throw new RuntimeException('official_example_untrusted');
        }
        return true;
    }

    // TOGYA_PLUGIN pintogya_official_cover: fixed public App assets, never member image keys.
    public static function coverUrl($detail) {
        $assets = array(
            '30000000-0000-4000-8000-000000000001'=>'example-craft',
            '30000000-0000-4000-8000-000000000002'=>'example-cooking',
            '30000000-0000-4000-8000-000000000003'=>'example-fitness',
        );
        $id = $detail['id'] ?? '';
        $asset = $assets[$id] ?? null;
        if($asset === null || ($detail['imageKey'] ?? null) !== $asset.'.png') { return ''; }
        try { self::verifyPublic($id, $detail); }
        catch(RuntimeException $error) { return ''; }
        return '/source/plugin/pintogya/static/examples/'.$asset.'.webp';
    }

    public static function readSnapshot($api, $activityId, $locale) {
        if(!isset(self::choices()[$activityId])) { throw new InvalidArgumentException('official_example_selection_invalid'); }
        // Only the anonymous public route can produce a shared publishing snapshot.
        return self::snapshot($activityId, $api->getPublicActivity($activityId, $locale));
    }

    // TOGYA_PLUGIN pintogya_official_reference: publishing metadata is not a stage-access grant.
    public static function readReference($api, $activityId, $locale) {
        if(!isset(self::choices()[$activityId])) { throw new InvalidArgumentException('official_example_selection_invalid'); }
        return self::reference($activityId, $api->getPublicActivity($activityId, $locale));
    }

    public static function reference($activityId, $detail) {
        self::verifyPublic($activityId, $detail);
        $copy = array('id'=>$activityId, 'creatorId'=>self::CREATOR_ID,
            'exampleSlug'=>$detail['exampleSlug'], 'stageVersion'=>$detail['stageVersion']);
        foreach(array('title','summary','category','mode') as $key) {
            $value = $detail[$key] ?? null;
            if(!is_string($value) || strlen($value) > 8192 || ($key === 'title' && trim($value) === '')) {
                throw new RuntimeException('official_example_details_invalid');
            }
            $copy[$key] = $value;
        }
        $stages = $detail['stageDetails'] ?? null;
        if(!is_array($stages) || !array_is_list($stages) || count($stages) < 1 || count($stages) > 100 ||
            ($detail['totalStages'] ?? null) !== count($stages)) { throw new RuntimeException('official_example_details_invalid'); }
        $copy['stages'] = array(); $ids = array();
        foreach($stages as $position=>$stage) {
            if(!is_array($stage) || ($stage['position'] ?? null) !== $position ||
                ($stage['version'] ?? null) !== $detail['stageVersion'] ||
                !in_array($stage['stageKind'] ?? null, array('tutorial','finish_story'), true) ||
                !is_string($stage['title'] ?? null) || trim($stage['title']) === '' || strlen($stage['title']) > 500) {
                throw new RuntimeException('official_example_details_invalid');
            }
            $id = PintogyaCrypto::assertUuid($stage['id'] ?? null);
            if(isset($ids[$id])) { throw new RuntimeException('official_example_details_invalid'); }
            $ids[$id] = true;
            $copy['stages'][] = array('id'=>$id, 'position'=>$position, 'kind'=>$stage['stageKind'], 'title'=>$stage['title']);
        }
        $copy['paceMode'] = $detail['paceMode'] ?? null;
        $copy['minimumCompletionIntervalSeconds'] = $detail['minimumCompletionIntervalSeconds'] ?? null;
        if(!in_array($copy['paceMode'], array('free','daily'), true) ||
            !is_int($copy['minimumCompletionIntervalSeconds']) || $copy['minimumCompletionIntervalSeconds'] < 0) {
            throw new RuntimeException('official_example_details_invalid');
        }
        return $copy;
    }

    public static function snapshot($activityId, $detail) {
        self::verifyPublic($activityId, $detail);
        $copy = array('id'=>$activityId, 'creatorId'=>self::CREATOR_ID,
            'exampleSlug'=>$detail['exampleSlug'], 'stageVersion'=>$detail['stageVersion']);
        foreach(array('title','summary','category','mode') as $key) {
            $value = $detail[$key] ?? null;
            if(!is_string($value) || strlen($value) > 8192 || ($key === 'title' && trim($value) === '')) {
                throw new RuntimeException('official_example_details_invalid');
            }
            $copy[$key] = $value;
        }
        $stages = $detail['stageDetails'] ?? null;
        if(!is_array($stages) || !array_is_list($stages) || count($stages) < 1 || count($stages) > 100 ||
            ($detail['totalStages'] ?? null) !== count($stages)) { throw new RuntimeException('official_example_details_invalid'); }
        $copy['stages'] = array(); $ids = array();
        foreach($stages as $position => $stage) {
            if(!is_array($stage) || ($stage['position'] ?? null) !== $position ||
                ($stage['version'] ?? null) !== $detail['stageVersion'] ||
                !in_array($stage['stageKind'] ?? null, array('tutorial','finish_story'), true)) {
                throw new RuntimeException('official_example_details_invalid');
            }
            if(($stage['canReadInstructions'] ?? null) !== true) { throw new RuntimeException('official_example_rules_unavailable'); }
            $id = PintogyaCrypto::assertUuid($stage['id'] ?? null);
            if(isset($ids[$id])) { throw new RuntimeException('official_example_details_invalid'); }
            $ids[$id] = true;
            $row = array('id'=>$id, 'position'=>$position, 'kind'=>$stage['stageKind']);
            foreach(array('title','objective','completionCriteria','checkinPrompt') as $key) {
                $value = $stage[$key] ?? null;
                if(!is_string($value) || trim($value) === '' || strlen($value) > 8192) {
                    throw new RuntimeException('official_example_rules_unavailable');
                }
                $row[$key] = $value;
            }
            $spec = $stage['evidenceSpec'] ?? null;
            if(!is_array($spec) || !$spec || strlen(json_encode($spec, JSON_THROW_ON_ERROR)) > 16384) {
                throw new RuntimeException('official_example_details_invalid');
            }
            $row['evidenceSpec'] = $spec;
            $copy['stages'][] = $row;
        }
        foreach(array('paceMode','minimumCompletionIntervalSeconds') as $key) {
            if(!array_key_exists($key, $detail)) { throw new RuntimeException('official_example_details_invalid'); }
            $copy[$key] = $detail[$key];
        }
        if(!in_array($copy['paceMode'], array('free','daily'), true) ||
            !is_int($copy['minimumCompletionIntervalSeconds']) || $copy['minimumCompletionIntervalSeconds'] < 0) {
            throw new RuntimeException('official_example_details_invalid');
        }
        return $copy;
    }

    public static function publisher($uid, $member, $nativePostAllowed) {
        if(!is_int($uid) || $uid < 1 || $uid > 16777215 || !is_array($member) ||
            (string)($member['uid'] ?? '') !== (string)$uid || $nativePostAllowed !== true) {
            throw new RuntimeException('official_example_publisher_not_allowed');
        }
        return $uid;
    }
}
