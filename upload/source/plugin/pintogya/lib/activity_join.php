<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_reward_progress.php';
require_once __DIR__.'/site_profile.php';

final class PintogyaActivityJoin {
    private $repo;
    private $binding;
    private $session;
    private $reader;
    private $thread;
    private $qrRenderer;
    private $siteHost;

    public function __construct($repo, $binding, $session, $reader, $thread, $qrRenderer, $siteHost) {
        if(!is_callable($qrRenderer) || !PintogyaSiteProfile::validHost($siteHost)) {
            throw new InvalidArgumentException('activity_join_service_invalid');
        }
        $this->repo = $repo;
        $this->binding = $binding;
        $this->session = $session;
        $this->reader = $reader;
        $this->thread = $thread;
        $this->qrRenderer = $qrRenderer;
        $this->siteHost = $siteHost;
    }

    public function start($uid, $tid, $sessionDigest, $locale, $now) {
        $this->assertRequest($uid, $tid, $sessionDigest, $now);
        $projection = $this->thread->publicProjection($tid, $locale);
        if($this->repo->activityInstantRuleById($projection['activity']['id']) !== null &&
            $this->repo->activeBindingForUid($uid, false)) {
            $viewer = $this->viewerState($uid, $tid, $locale, $now);
            if($viewer['forumJoined'] === true) { return array('state'=>'joined'); }
        }
        $instance = $this->instance($uid, $tid, $projection['activity']);
        $challenge = $this->binding->latestChallenge($uid, 0, $instance, $sessionDigest);
        $approvedPending = false;
        if($this->approved($challenge, $now)) {
            $action = $this->repo->activityActionByChallenge($challenge['challengeId']);
            if($action) {
                if($action['purpose'] !== 'join' || $action['state_code'] !== 'consumed' ||
                    (int)$action['uid'] !== $uid ||
                    !hash_equals((string)$action['activity_id'], (string)$projection['activity']['id'])) {
                    throw new RuntimeException('activity_join_receipt_invalid');
                }
                $participant = $this->repo->participationForUid($projection['activityPk'], $uid);
                if(!$participant || $participant['state_code'] === 'revoked') {
                    throw new RuntimeException('activity_join_participant_invalid');
                }
                if($participant['state_code'] === 'inactive') { $challenge = null; }
            }
            $approvedPending = $challenge !== null;
        }
        $map = $this->checkedMap($tid, $projection, false);
        $participant = $this->repo->participationForUid((int)$map['activity_pk'], $uid);
        if(!$participant || $participant['state_code'] !== 'active') {
            $instant = $this->instantRuleForMap($map, $projection['activity'], false);
            $pool = $instant ? null : $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], false);
            $this->assertJoinOpen($map, $projection['activity'], $pool, $now, $instant);
        }
        if($approvedPending) { return array('state' => 'approved'); }
        if(!$challenge || $challenge['state'] !== 'pending' || (int)$challenge['expiresAt'] < $now) {
            $challenge = $this->binding->startChallenge($uid, 0, $instance, $sessionDigest, $locale);
        }
        $openUrl = pintogya_qr_browser_url($challenge['qrUrl']);
        $qr = $openUrl !== '' ? call_user_func($this->qrRenderer, $openUrl) : '';
        if(!is_string($qr) || $qr === '') { throw new RuntimeException('activity_join_qr_unavailable'); }
        return array('state' => 'pending', 'qrImage' => $qr, 'openUrl' => $openUrl,
            'expiresAt' => (int)$challenge['expiresAt']);
    }

    public function poll($uid, $tid, $sessionDigest, $locale, $now) {
        global $_G;
        $this->assertRequest($uid, $tid, $sessionDigest, $now);
        $projection = $this->thread->publicProjection($tid, $locale);
        $activity = $projection['activity'];
        $instance = $this->instance($uid, $tid, $activity);
        if(!$this->binding->pollLatest($uid, 0, $instance, $sessionDigest, true)) {
            return array('state' => 'pending');
        }
        $challenge = $this->binding->latestChallenge($uid, 0, $instance, $sessionDigest);
        if(!$this->approved($challenge, $now)) { throw new RuntimeException('activity_join_approval_expired'); }
        $bound = $this->activeIdentity($uid);
        $readOnly = $this->repo->activityInstantRuleById($activity['id']) !== null;
        if(!$this->reader->joinedActive($uid, $activity['id'], $locale, $activity['stageVersion'], $readOnly)) {
            $this->markInactive($uid, $tid, $projection, $bound, $now);
            return array('state' => 'not_joined');
        }

        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                if(!$currentBinding || (int)$currentBinding['generation'] !== (int)$bound['generation'] ||
                    !hash_equals((string)$currentBinding['togya_user_id'], (string)$bound['togya_user_id'])) {
                    throw new RuntimeException('activity_join_binding_changed');
                }
                $map = $this->checkedMap($tid, $projection, true);
                $thread = $this->repo->activityThread($tid, true);
                if(!$thread || (int)$thread['fid'] !== (int)$map['fid'] ||
                    (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
                    (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
                    $this->repo->activityFirstPostMessage($tid) !==
                        PintogyaActivityNativePost::messageForDigest((string)$map['reservation_digest'])) {
                    throw new RuntimeException('activity_join_thread_changed');
                }
                $approval = $this->repo->latestChallenge($uid, 0, $instance, true);
                if(!$approval || $approval['challenge_id'] !== $challenge['challengeId'] ||
                    $approval['state_code'] !== 'approved' ||
                    !hash_equals((string)$approval['session_digest'], $sessionDigest) ||
                    (int)$approval['approved_at'] < $now - 300 || (int)$approval['approved_at'] > $now ||
                    (int)$approval['expires_at'] < (int)$approval['approved_at']) {
                    throw new RuntimeException('activity_join_approval_changed');
                }
                $instant = $this->instantRuleForMap($map, $activity, true);
                $pool = $instant ? null : $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], true);
                if(!$instant && !$pool) { throw new RuntimeException('activity_join_pool_unavailable'); }
                $existingAction = $this->repo->activityActionByChallenge($approval['challenge_id'], true);
                $existing = $this->repo->participationForUid((int)$map['activity_pk'], $uid, true);
                $identityOwner = $this->repo->participationForTogyaUser((int)$map['activity_pk'], $bound['togya_user_id'], true);
                $digest = $this->actionDigest($uid, $tid, $sessionDigest, $approval['challenge_id'],
                    $bound['togya_user_id']);
                if($existing && !hash_equals((string)$existing['togya_user_id'], (string)$bound['togya_user_id'])) {
                    throw new RuntimeException('activity_join_identity_changed');
                }
                if($identityOwner && (int)$identityOwner['uid'] !== $uid) {
                    throw new RuntimeException('activity_join_identity_in_use');
                }
                if($existingAction) {
                    if($existingAction['purpose'] !== 'join' || $existingAction['state_code'] !== 'consumed' ||
                        (int)$existingAction['uid'] !== $uid ||
                        !hash_equals((string)$existingAction['activity_id'], (string)$activity['id']) ||
                        !hash_equals((string)$existingAction['session_digest'], $sessionDigest) ||
                        (int)$existingAction['binding_generation'] !== (int)$bound['generation'] ||
                        (int)$existingAction['stage_version'] !== $activity['stageVersion'] ||
                        !hash_equals((string)$existingAction['action_digest'], $digest) ||
                        !hash_equals((string)$existingAction['source_hash'], (string)$map['source_hash']) ||
                        !$existing || $existing['state_code'] !== 'active') {
                        throw new RuntimeException('activity_join_replay_mismatch');
                    }
                    $this->repo->commit();
                    return array('state' => 'joined');
                }
                if(!$existing || $existing['state_code'] !== 'active') {
                    $this->assertJoinOpen($map, $activity, $pool, max($now, $this->repo->currentUnixTime()), $instant);
                }
                if($existing) {
                    if($existing['state_code'] === 'revoked') { throw new RuntimeException('activity_join_revoked'); }
                    if(!$instant && $existing['state_code'] !== 'active' && ($pool['state_code'] ?? null) !== 'open') {
                        throw new RuntimeException('activity_join_pool_inactive');
                    }
                    $rejoinPlan = null;
                    if(!$instant && $existing['state_code'] !== 'active') {
                        $member = $this->repo->activityRewardMemberForUid((int)$map['activity_pk'], $uid, true);
                        if($member && (int)$member['participant_pk'] !== (int)$existing['participant_pk']) {
                            throw new RuntimeException('activity_join_member_mismatch');
                        }
                        if(!$member) {
                            $rejoinPlan = PintogyaActivityRewardProgress::planNewJoin($this->repo, $pool,
                                (int)$map['activity_pk'], $activity['id'], $_G['setting']['extcredits'] ?? array());
                        }
                    }
                    if($existing['state_code'] !== 'active' ||
                        (int)$existing['binding_generation'] !== (int)$bound['generation'] ||
                        (int)$existing['stage_version'] !== $activity['stageVersion'] ||
                        (int)$existing['last_verified_at'] !== $now) {
                        $this->repo->updateParticipation((int)$map['activity_pk'], $uid, $bound['togya_user_id'], array(
                            'binding_generation' => (int)$bound['generation'], 'stage_version' => $activity['stageVersion'],
                            'state_code' => 'active', 'last_verified_at' => $now, 'updated_at' => $now,
                        ));
                    }
                    if($rejoinPlan) { PintogyaActivityRewardProgress::recordNewJoin($this->repo, $rejoinPlan, $now); }
                } else {
                    $joinPlan = $instant ? null : PintogyaActivityRewardProgress::planNewJoin($this->repo, $pool,
                        (int)$map['activity_pk'], $activity['id'], $_G['setting']['extcredits'] ?? array());
                    $this->repo->insertParticipation(array(
                        'activity_pk' => (int)$map['activity_pk'], 'uid' => $uid,
                        'togya_user_id' => $bound['togya_user_id'], 'binding_generation' => (int)$bound['generation'],
                        'stage_version' => $activity['stageVersion'], 'state_code' => 'active',
                        'first_joined_at' => $now, 'last_verified_at' => $now, 'updated_at' => $now,
                    ));
                    if($joinPlan) { PintogyaActivityRewardProgress::recordNewJoin($this->repo, $joinPlan, $now); }
                }
                $this->repo->insertActivityAction(array(
                    'action_digest' => $digest, 'uid' => $uid, 'fid' => (int)$map['fid'],
                    'session_digest' => $sessionDigest, 'qr_challenge_id' => $approval['challenge_id'],
                    'binding_generation' => (int)$bound['generation'], 'activity_id' => $activity['id'],
                    'stage_version' => $activity['stageVersion'], 'source_hash' => $map['source_hash'],
                    'purpose' => 'join', 'state_code' => 'consumed', 'created_at' => $now,
                    'expires_at' => (int)$approval['expires_at'], 'updated_at' => $now, 'consumed_at' => $now,
                ));
                $this->repo->commit();
                return array('state' => 'joined');
            } catch(Throwable $error) {
                $this->repo->rollback();
                throw $error;
            }
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    public function viewerState($uid, $tid, $locale, $now, $expectedBinding = null) {
        if(!is_int($uid) || $uid < 1 || !is_int($tid) || $tid < 1 || !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('activity_join_request_invalid');
        }
        $projection = $this->thread->publicProjection($tid, $locale);
        $bound = $this->activeIdentity($uid);
        if($expectedBinding !== null && (!is_array($expectedBinding) ||
            ($expectedBinding['uid'] ?? null) !== $uid ||
            ($expectedBinding['generation'] ?? null) !== (int)$bound['generation'] ||
            ($expectedBinding['togya_user_id'] ?? null) !== $bound['togya_user_id'])) {
            throw new RuntimeException('activity_join_binding_changed');
        }
        $readOnly = $this->repo->activityInstantRuleById($projection['activity']['id']) !== null;
        $viewer = $this->reader->viewerState($uid, $projection['activity']['id'], $locale,
            $projection['activity']['stageVersion'], $readOnly);
        if(!is_array($viewer) || !in_array($viewer['state'] ?? null, array('joined', 'not_joined'), true) ||
            !is_array($viewer['stages'] ?? null)) {
            throw new RuntimeException('activity_viewer_response_invalid');
        }
        if($viewer['state'] === 'not_joined') { $this->markInactive($uid, $tid, $projection, $bound, $now); }
        $map = $this->checkedMap($tid, $projection, false);
        // TOGYA_PLUGIN: pintogya_bound_membership_participation -- the binding already confirms identity.
        if($readOnly && $viewer['state'] === 'joined') {
            $this->recordVerifiedInstantParticipation($uid, $tid, $projection, $bound, $now);
        }
        $participant = $this->repo->participationForUid((int)$map['activity_pk'], $uid, false);
        // Legacy activities retain their separate QR confirmation path.
        $viewer['forumJoined'] = $viewer['state'] === 'joined' && $participant &&
            ($participant['state_code'] ?? null) === 'active' &&
            hash_equals((string)($participant['togya_user_id'] ?? ''), (string)$bound['togya_user_id']) &&
            (int)($participant['binding_generation'] ?? 0) === (int)$bound['generation'] &&
            (int)($participant['stage_version'] ?? 0) === (int)$projection['activity']['stageVersion'];
        $viewer['accountRef'] = $bound['togya_user_id'];
        return $viewer;
    }

    private function recordVerifiedInstantParticipation($uid, $tid, $projection, $bound, $now) {
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $current = $this->repo->activeBindingForUid($uid, true);
                if(!$current || (int)$current['generation'] !== (int)$bound['generation'] ||
                    !hash_equals((string)$current['togya_user_id'], (string)$bound['togya_user_id'])) {
                    throw new RuntimeException('activity_join_binding_changed');
                }
                $map = $this->checkedMap($tid, $projection, true);
                $thread = $this->repo->activityThread($tid, true);
                if(!$thread || (int)$thread['fid'] !== (int)$map['fid'] ||
                    (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
                    (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
                    $this->repo->activityFirstPostMessage($tid) !==
                        PintogyaActivityNativePost::messageForDigest((string)$map['reservation_digest'])) {
                    throw new RuntimeException('activity_join_thread_changed');
                }
                if(!$this->instantRuleForMap($map, $projection['activity'], true)) {
                    throw new RuntimeException('instant_rule_publication_changed');
                }
                $existing = $this->repo->participationForUid((int)$map['activity_pk'], $uid, true);
                $owner = $this->repo->participationForTogyaUser((int)$map['activity_pk'], $bound['togya_user_id'], true);
                if($existing && !hash_equals((string)$existing['togya_user_id'], (string)$bound['togya_user_id'])) {
                    throw new RuntimeException('activity_join_identity_changed');
                }
                if($owner && (int)$owner['uid'] !== $uid) { throw new RuntimeException('activity_join_identity_in_use'); }
                if($existing && $existing['state_code'] === 'revoked') { throw new RuntimeException('activity_join_revoked'); }
                $values = array('binding_generation'=>(int)$bound['generation'],
                    'stage_version'=>$projection['activity']['stageVersion'], 'state_code'=>'active',
                    'last_verified_at'=>$now, 'updated_at'=>$now);
                if(!$existing) {
                    $this->repo->insertParticipation(array('activity_pk'=>(int)$map['activity_pk'], 'uid'=>$uid,
                        'togya_user_id'=>$bound['togya_user_id'], 'first_joined_at'=>$now) + $values);
                } elseif($existing['state_code'] !== 'active' ||
                    (int)$existing['binding_generation'] !== (int)$bound['generation'] ||
                    (int)$existing['stage_version'] !== $projection['activity']['stageVersion']) {
                    $this->repo->updateParticipation((int)$map['activity_pk'], $uid, $bound['togya_user_id'], $values);
                }
                $this->repo->commit();
            } catch(Throwable $error) {
                $this->repo->rollback();
                throw $error;
            }
        } finally { $this->repo->releaseUidLock($lock); }
    }

    public function participants($uid, $tid, $locale, $afterPk) {
        if(!is_int($uid) || $uid < 0 || !is_int($tid) || $tid < 1 || $tid > 4294967295 ||
            !is_int($afterPk) || $afterPk < 0) {
            throw new InvalidArgumentException('activity_participants_request_invalid');
        }
        $projection = $this->thread->publicProjection($tid, $locale);
        // TOGYA_PLUGIN: pintogya_public_participant_avatars -- forum sign-ups are public.
        $map = $this->checkedMap($tid, $projection, false);
        $rows = $this->repo->participationPage((int)$map['activity_pk'], $afterPk, 20);
        $hasMore = count($rows) > 20;
        if($hasMore) { array_pop($rows); }
        $items = array();
        foreach($rows as $row) {
            if((int)($row['uid'] ?? 0) < 1 || (int)($row['participant_pk'] ?? 0) <= $afterPk ||
                (int)($row['last_verified_at'] ?? 0) < 1) {
                throw new RuntimeException('activity_participants_row_invalid');
            }
            $items[] = array('uid' => (int)$row['uid']);
        }
        $last = $hasMore ? end($rows) : null;
        return array('items' => $items,
            'nextCursor' => $last ? (string)$last['participant_pk'] : '0');
    }

    private function markInactive($uid, $tid, $projection, $bound, $now) {
        global $_G;
        $map = $this->checkedMap($tid, $projection, false);
        $participant = $this->repo->participationForUid((int)$map['activity_pk'], $uid);
        if(!$participant || $participant['state_code'] !== 'active') { return; }
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                if(!$currentBinding || (int)$currentBinding['generation'] !== (int)$bound['generation'] ||
                    !hash_equals((string)$currentBinding['togya_user_id'], (string)$bound['togya_user_id'])) {
                    throw new RuntimeException('activity_join_binding_changed');
                }
                $map = $this->checkedMap($tid, $projection, true);
                $instant = $this->instantRuleForMap($map, $projection['activity'], true);
                $pool = $instant ? null : $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], true);
                if(!$instant && !$pool) { throw new RuntimeException('activity_join_pool_unavailable'); }
                $participant = $this->repo->participationForUid((int)$map['activity_pk'], $uid, true);
                if($participant && $participant['state_code'] === 'active') {
                    if(!hash_equals((string)$participant['togya_user_id'], (string)$bound['togya_user_id'])) {
                        throw new RuntimeException('activity_join_identity_changed');
                    }
                    $member = $instant ? null : $this->repo->activityRewardMemberForUid((int)$map['activity_pk'], $uid, true);
                    if($member && (int)$member['participant_pk'] !== (int)$participant['participant_pk']) {
                        throw new RuntimeException('activity_join_member_mismatch');
                    }
                    $leavePlan = !$instant && !$member && $pool['state_code'] === 'open' ?
                        PintogyaActivityRewardProgress::planUnconfirmedLeave($this->repo, $pool,
                            (int)$map['activity_pk'], $projection['activity']['id'],
                            $_G['setting']['extcredits'] ?? array()) : null;
                    $this->repo->updateParticipation((int)$map['activity_pk'], $uid, $bound['togya_user_id'], array(
                        'binding_generation' => (int)$bound['generation'], 'state_code' => 'inactive',
                        'last_verified_at' => $now, 'updated_at' => $now,
                    ));
                    if($leavePlan) {
                        PintogyaActivityRewardProgress::recordUnconfirmedLeave($this->repo, $leavePlan, $now);
                    }
                }
                $this->repo->commit();
            } catch(Throwable $error) {
                $this->repo->rollback();
                throw $error;
            }
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    private function checkedMap($tid, $projection, $lock) {
        $map = $this->repo->activityByTid($tid, $lock);
        $activity = $projection['activity'];
        if(!$map || $map['state_code'] !== 'active' || (int)$map['activity_pk'] !== $projection['activityPk'] ||
            (int)$map['fid'] !== $projection['fid'] || (int)$map['tid'] !== $tid ||
            (int)$map['stage_version'] !== $activity['stageVersion'] ||
            !hash_equals((string)$map['activity_id'], (string)$activity['id']) ||
            !hash_equals((string)$map['source_hash'], PintogyaActivityPublish::sourceHash($activity))) {
            throw new RuntimeException('activity_join_source_changed');
        }
        return $map;
    }

    private function instantRuleForMap($map, $activity, $lock) {
        $row = $this->repo->activityInstantRuleById($activity['id'], $lock);
        if(!$row) { return null; }
        $rule = PintogyaActivityInstantRule::storedSnapshot($row);
        if($row['state_code'] !== 'active' || (int)$row['published_at'] < 1 ||
            (int)$row['published_at'] !== (int)$map['published_at'] ||
            $rule['stage_version'] !== $activity['stageVersion']) { throw new RuntimeException('instant_rule_publication_changed'); }
        return $rule;
    }

    private function assertJoinOpen($map, $activity, $pool, $now, $instant = null) {
        global $_G;
        // Long-term instant activities have no legacy capacity, growth or enrollment deadline.
        if($instant) { return; }
        if(($pool['state_code'] ?? null) !== 'open') { throw new RuntimeException('activity_join_pool_inactive'); }
        $rule = $this->repo->activityRewardRuleByPk((int)$pool['rule_pk']);
        if(!$rule || $rule['activity_id'] !== $activity['id'] ||
            !is_string($rule['rules_json'] ?? null) || !is_string($rule['rule_hash'] ?? null) ||
            !hash_equals((string)$pool['rule_hash'], $rule['rule_hash']) ||
            !hash_equals($rule['rule_hash'], hash('sha256', "pintogya/activity-reward-rule/v1\0".$rule['rules_json']))) {
            throw new RuntimeException('activity_join_rule_mismatch');
        }
        try { $defaults = PintogyaActivitySettings::rewardDefaultsFromJson($rule['rules_json'],
            $_G['setting']['extcredits'] ?? array()); }
        catch(InvalidArgumentException $error) { throw new RuntimeException('activity_join_rule_mismatch', 0, $error); }
        if($defaults['version'] !== 2 || (int)$rule['credit_id'] !== $defaults['credit_id'] ||
            (int)$pool['credit_id'] !== $defaults['credit_id']) {
            throw new RuntimeException('activity_join_rule_mismatch');
        }
        PintogyaActivityDeadlines::assertOpen((int)$map['published_at'], $defaults['window_days'], 'join', $now);
    }

    private function activeIdentity($uid) {
        $bound = $this->session->binding($uid);
        if(!$bound || !PintogyaCrypto::validOpaqueId($bound['togya_user_id'] ?? '') ||
            !isset($bound['generation']) || (int)$bound['generation'] < 1) {
            throw new RuntimeException('activity_join_binding_invalid');
        }
        return $bound;
    }

    private function instance($uid, $tid, $activity) {
        return 'activity-join-'.substr(hash('sha256', "pintogya/activity-join-instance/v1\0"
            .$this->siteHost."\0".$uid."\0".$tid."\0".PintogyaCrypto::assertUuid($activity['id'])
            ."\0".$activity['stageVersion']), 0, 32);
    }

    private function actionDigest($uid, $tid, $sessionDigest, $challengeId, $togyaUserId) {
        return hash('sha256', "pintogya/activity-join/v1\0".$this->siteHost."\0".$uid."\0".$tid
            ."\0".$sessionDigest."\0".$challengeId."\0".$togyaUserId);
    }

    private function approved($challenge, $now) {
        return is_array($challenge) && ($challenge['state'] ?? '') === 'approved' &&
            (int)($challenge['approvedAt'] ?? 0) >= $now - 300 &&
            (int)($challenge['approvedAt'] ?? 0) <= $now &&
            (int)($challenge['expiresAt'] ?? 0) >= (int)($challenge['approvedAt'] ?? 0);
    }

    private function assertRequest($uid, $tid, $sessionDigest, $now) {
        if(!is_int($uid) || $uid < 1 || !is_int($tid) || $tid < 1 || $tid > 4294967295 ||
            !is_string($sessionDigest) || !preg_match('/^[0-9a-f]{64}$/D', $sessionDigest) ||
            !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('activity_join_request_invalid');
        }
    }
}
