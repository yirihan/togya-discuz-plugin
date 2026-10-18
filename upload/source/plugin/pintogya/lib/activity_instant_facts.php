<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_rule.php';

final class PintogyaActivityInstantFacts {
    public static function fromJourney($binding, $detail, $journey, $rule, $observedAt) {
        $rule = PintogyaActivityInstantRule::assertSnapshot($rule);
        if(!is_array($binding) || ($binding['state_code'] ?? null) !== 'active' ||
            !is_int($observedAt) || $observedAt < 1 ||
            !preg_match('/^[1-9][0-9]{0,7}$/D', (string)($binding['uid'] ?? '')) ||
            (int)$binding['uid'] > 16777215 ||
            !preg_match('/^[1-9][0-9]{0,9}$/D', (string)($binding['generation'] ?? ''))) {
            throw new RuntimeException('instant_binding_inactive');
        }
        $userId = PintogyaCrypto::assertUuid($binding['togya_user_id'] ?? null);
        if(!is_array($detail) || ($detail['id'] ?? null) !== $rule['activity_id'] ||
            ($detail['stageVersion'] ?? null) !== $rule['stage_version'] ||
            ($detail['isPublic'] ?? null) !== true || ($detail['isJoined'] ?? null) !== true ||
            !in_array($detail['status'] ?? null, array('active','archived'), true)) {
            throw new RuntimeException('instant_source_unavailable_or_changed');
        }
        $currentStages = $detail['stageDetails'] ?? null;
        if(!is_array($currentStages) || !array_is_list($currentStages) ||
            count($currentStages) !== count($rule['stages']) ||
            ($detail['totalStages'] ?? null) !== count($currentStages)) {
            throw new RuntimeException('instant_stage_plan_changed');
        }
        foreach($currentStages as $position => $stage) {
            $saved = is_array($stage) ? ($rule['stages'][$stage['id'] ?? ''] ?? null) : null;
            if(!$saved || ($stage['position'] ?? null) !== $position ||
                $saved['position'] !== $position || ($stage['stageKind'] ?? null) !== $saved['kind'] ||
                ($stage['version'] ?? null) !== $rule['stage_version']) {
                throw new RuntimeException('instant_stage_plan_changed');
            }
        }
        if(!is_array($journey) || ($journey['activityId'] ?? null) !== $rule['activity_id'] ||
            ($journey['participant']['id'] ?? null) !== $userId ||
            !is_array($journey['checkins'] ?? null) || !array_is_list($journey['checkins']) ||
            count($journey['checkins']) > 100) { throw new RuntimeException('instant_journey_identity_invalid'); }
        $partial = count($journey['checkins']) === 100;
        $first = array(); $firstTimes = array(); $factsById = array(); $lastPosition = null; $lastTime = null;
        foreach($journey['checkins'] as $checkin) {
            if(!is_array($checkin) || !is_bool($checkin['completesStage'] ?? null) ||
                !is_string($checkin['createdAt'] ?? null)) { throw new RuntimeException('instant_checkin_invalid'); }
            $checkinId = PintogyaCrypto::assertUuid($checkin['id'] ?? null);
            $stageId = PintogyaCrypto::assertUuid($checkin['stageId'] ?? null);
            $stage = $rule['stages'][$stageId] ?? null;
            if(!$stage || ($checkin['stageVersion'] ?? null) !== $rule['stage_version'] ||
                ($checkin['stagePosition'] ?? null) !== $stage['position']) {
                throw new RuntimeException('instant_checkin_plan_changed');
            }
            // TOGYA_PLUGIN pintogya_first_fact_precision: keep provider microseconds, not UUID order within a second.
            $date = self::timestamp($checkin['createdAt']);
            $occurredAt = $date->getTimestamp();
            $exactTime = $date->format('Y-m-d\TH:i:s.u\Z');
            if($occurredAt > $observedAt + 300) { throw new RuntimeException('instant_checkin_clock_invalid'); }
            // The formal journey is ordered by stage position DESC, then created_at DESC.
            // At the limit only stages above the final bucket have complete first-event evidence.
            if($partial && $lastPosition !== null && ($stage['position'] > $lastPosition ||
                ($stage['position'] === $lastPosition && strcmp($exactTime,$lastTime) > 0))) {
                throw new RuntimeException('instant_journey_order_invalid');
            }
            $lastPosition = $stage['position']; $lastTime = $exactTime;
            $fact = array('checkin_id'=>$checkinId, 'stage_id'=>$stageId, 'occurred_at'=>$occurredAt,
                'stage_version'=>$rule['stage_version'], 'completes_stage'=>$checkin['completesStage']);
            $identity = array('fact'=>$fact,'timestamp'=>$exactTime);
            if(isset($factsById[$checkinId]) && $factsById[$checkinId] !== $identity) {
                throw new RuntimeException('instant_checkin_conflict');
            }
            $factsById[$checkinId] = $identity;
            // Togya persists completesStage; optional AI evidence, bodies and photos are not local reward gates.
            if(!$checkin['completesStage']) { continue; }
            if(!isset($first[$stageId]) || strcmp($exactTime,$firstTimes[$stageId]) < 0 ||
                ($exactTime === $firstTimes[$stageId] && strcmp($checkinId, $first[$stageId]['checkin_id']) < 0)) {
                $first[$stageId] = $fact;
                $firstTimes[$stageId] = $exactTime;
            }
        }
        $candidates = array(); $historyStages = array(); $historyCount = 0;
        foreach($rule['stages'] as $stageId => $stage) {
            if($partial && $stage['position'] <= $lastPosition) { $historyCount++; }
            if($partial && $stage['position'] <= $lastPosition &&
                ($currentStages[$stage['position']]['canViewFeed'] ?? null) === true) { $historyStages[] = $stageId; }
            if(!isset($first[$stageId])) { continue; }
            if($partial && $stage['position'] <= $lastPosition) { continue; }
            $kind = $stage['kind'] === 'finish_story' ? 'base' : 'stage';
            $amount = $kind === 'base' ? $rule['base'] : $stage['amount'];
            $candidates[] = array('uid'=>(int)$binding['uid'], 'togya_user_id'=>$userId,
                'binding_generation'=>(int)$binding['generation'], 'activity_id'=>$rule['activity_id'],
                'kind'=>$kind, 'reward_stage_id'=>$kind === 'stage' ? $stageId : null,
                'credit_id'=>$rule['credit_id'], 'amount'=>$amount, 'observed_at'=>$observedAt,
                'fact'=>$first[$stageId]);
        }
        return array('state'=>$partial ? 'partial_history' : 'observed',
            'candidates'=>$candidates, 'history_stage_ids'=>$historyStages,
            'history_access_complete'=>count($historyStages) === $historyCount,
            'base_source_available'=>$rule['finish_stage_id'] !== null);
    }

    private static function timestamp($value) {
        if(!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D', $value, $parts) ||
            !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) ||
            (int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59) {
            throw new RuntimeException('instant_checkin_time_invalid');
        }
        $date = new DateTimeImmutable($value);
        $time = $date->getTimestamp();
        if($time < 1 || $time > 4294967295) { throw new RuntimeException('instant_checkin_time_invalid'); }
        return $date;
    }
}
