<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_reward_progress.php';
require_once __DIR__.'/activity_native_post.php';
require_once __DIR__.'/activity_publish.php';

final class PintogyaActivityRewardPromotion {
    private $repo;
    private $thread;
    private $evidence;

    public function __construct($repo, $thread, $evidence) {
        $this->repo = $repo;
        $this->thread = $thread;
        $this->evidence = $evidence;
    }

    public function promoteTimeoutCompletion($reviewPk, $extcredits) {
        if(!is_int($reviewPk) || $reviewPk < 1 || !is_array($extcredits)) {
            throw new InvalidArgumentException('activity_completion_input_invalid');
        }
        $review = $this->repo->reviewCaseByPk($reviewPk);
        if(!$review || ($review['kind'] ?? null) !== 'completion' ||
            ($review['state_code'] ?? null) !== 'approved' ||
            ($review['decision_source'] ?? null) !== 'timeout') {
            return 'not_approved';
        }
        $uid = (int)$review['uid'];
        $activityPk = (int)$review['activity_pk'];
        $map = $this->repo->activityByPk($activityPk);
        if(!$map || ($map['state_code'] ?? null) !== 'active' || (int)$map['tid'] < 1) {
            return 'source_unavailable';
        }
        try {
            $projection = $this->thread->publicProjection((int)$map['tid'], 'en-US');
            $binding = $this->repo->activeBindingForUid($uid);
            $participant = $this->repo->participationForUid($activityPk, $uid);
            $this->assertContext($review, $map, $projection, $binding, $participant);
            $observed = $this->evidence->observe($uid, $map['activity_id'],
                (int)$map['stage_version'], $binding['togya_user_id'], 'completion', null,
                $map['activity_id'], 'en-US');
        } catch(Exception $error) {
            return 'source_unavailable';
        }
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentMap = $this->repo->activityByPk($activityPk, true);
                $currentThread = $this->repo->activityThread((int)$map['tid'], true);
                $pool = $this->repo->activityRewardPoolByActivity($activityPk, true);
                $member = $this->repo->activityRewardMemberForUid($activityPk, $uid, true);
                $currentParticipant = $this->repo->participationForUid($activityPk, $uid, true);
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                $currentReview = $this->repo->reviewCaseByPk($reviewPk, true);
                $decision = $this->repo->reviewDecisionForReview($reviewPk, true);
                $this->assertContext($currentReview, $currentMap, $projection,
                    $currentBinding, $currentParticipant);
                $this->assertDecision($currentReview, $decision, $observed,
                    $currentThread, $currentMap, $this->repo->currentUnixTime());
                if($member) {
                    if((int)$member['activity_pk'] !== $activityPk || (int)$member['uid'] !== $uid ||
                        (int)$member['participant_pk'] !== (int)$currentReview['participant_pk'] ||
                        !hash_equals((string)$member['decision_digest'],
                            (string)$currentReview['decision_digest'])) {
                        throw new RuntimeException('activity_completion_member_conflict');
                    }
                    $this->repo->commit();
                    return 'already_recorded';
                }
                $now = $this->repo->currentUnixTime();
                $plan = PintogyaActivityRewardProgress::planNewCompletion($this->repo, $pool,
                    $activityPk, $currentMap['activity_id'], $extcredits);
                $this->repo->insertActivityRewardMember(array(
                    'activity_pk' => $activityPk,
                    'participant_pk' => (int)$currentReview['participant_pk'],
                    'uid' => $uid,
                    'rule_hash' => $pool['rule_hash'],
                    'credit_id' => (int)$pool['credit_id'],
                    'decision_digest' => $currentReview['decision_digest'],
                    'approved_by_uid' => 0,
                    'receipt_digest' => null,
                    'receipt_state' => 'pending',
                    'state_code' => 'awaiting_receipt',
                    'target_amount' => $plan['target_after'],
                    'paid_amount' => 0,
                    'approved_at' => (int)$currentReview['decided_at'],
                    'receipt_verified_at' => 0,
                    'updated_at' => $now,
                ));
                PintogyaActivityRewardProgress::recordNewCompletion($this->repo, $plan, $now);
                $this->repo->commit();
                return 'recorded';
            } catch(Throwable $error) {
                $this->repo->rollback();
                if($error->getMessage() === 'activity_completion_source_changed') {
                    return 'source_unavailable';
                }
                throw $error;
            }
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    private function assertContext($review, $map, $projection, $binding, $participant) {
        if(!$review || !$map || !$projection || !$binding || !$participant ||
            ($review['kind'] ?? null) !== 'completion' ||
            ($review['state_code'] ?? null) !== 'approved' ||
            ($review['decision_source'] ?? null) !== 'timeout' ||
            $review['decided_by_uid'] !== null || $review['stage_id'] !== null ||
            $map['state_code'] !== 'active' || $participant['state_code'] !== 'active' ||
            $binding['state_code'] !== 'active' ||
            (int)$map['activity_pk'] !== (int)$review['activity_pk'] ||
            (int)$map['tid'] !== (int)$projection['tid'] ||
            (int)$projection['activityPk'] !== (int)$map['activity_pk'] ||
            $map['activity_id'] !== $projection['activity']['id'] ||
            $map['activity_id'] !== $review['source_ref'] ||
            (int)$map['stage_version'] !== (int)$projection['activity']['stageVersion'] ||
            (int)$map['stage_version'] !== (int)$review['stage_version'] ||
            !hash_equals((string)$map['source_hash'],
                PintogyaActivityPublish::sourceHash($projection['activity'])) ||
            (int)$participant['participant_pk'] !== (int)$review['participant_pk'] ||
            (int)$participant['activity_pk'] !== (int)$map['activity_pk'] ||
            (int)$participant['uid'] !== (int)$review['uid'] ||
            (int)$participant['stage_version'] !== (int)$map['stage_version'] ||
            (int)$binding['uid'] !== (int)$review['uid'] ||
            (int)$binding['generation'] !== (int)$review['binding_generation'] ||
            (int)$participant['binding_generation'] !== (int)$binding['generation'] ||
            !hash_equals((string)$participant['togya_user_id'],
                (string)$binding['togya_user_id'])) {
            throw new RuntimeException('activity_completion_source_changed');
        }
    }

    private function assertDecision($review, $decision, $observed, $thread, $map, $now) {
        if(!$decision || !$thread || (int)$thread['tid'] !== (int)$map['tid'] ||
            (int)$thread['fid'] !== (int)$map['fid'] ||
            (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
            (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
            $this->repo->activityFirstPostMessage((int)$map['tid']) !==
                PintogyaActivityNativePost::messageForDigest((string)$map['reservation_digest']) ||
            (int)$review['auto_due_at'] < 1 ||
            (int)$review['decided_at'] < (int)$review['auto_due_at'] ||
            (int)$review['decided_at'] > $now ||
            (int)$decision['review_pk'] !== (int)$review['review_pk'] ||
            (int)$decision['activity_pk'] !== (int)$review['activity_pk'] ||
            (int)$decision['participant_pk'] !== (int)$review['participant_pk'] ||
            (int)$decision['uid'] !== (int)$review['uid'] ||
            $decision['kind'] !== 'completion' ||
            $decision['decision'] !== 'approved' ||
            $decision['decision_source'] !== 'timeout' ||
            $decision['decided_by_uid'] !== null ||
            (int)$decision['decided_at'] !== (int)$review['decided_at'] ||
            !self::sameHash($decision['decision_digest'] ?? null,
                $review['decision_digest'] ?? null) ||
            !self::sameHash($decision['source_digest'] ?? null,
                $review['source_digest'] ?? null) ||
            !self::sameHash($observed['source_digest'] ?? null,
                $review['source_digest'] ?? null)) {
            throw new RuntimeException('activity_completion_source_changed');
        }
    }

    private static function sameHash($left, $right) {
        return is_string($left) && preg_match('/^[0-9a-f]{64}$/D', $left) === 1 &&
            is_string($right) && preg_match('/^[0-9a-f]{64}$/D', $right) === 1 &&
            hash_equals($left, $right);
    }
}
