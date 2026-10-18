<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_review_policy.php';

final class PintogyaActivityReviewTimeout {
    private $repo;
    private $thread;
    private $evidence;

    public function __construct($repo, $thread, $evidence) {
        $this->repo = $repo;
        $this->thread = $thread;
        $this->evidence = $evidence;
    }

    public function decide($reviewPk) {
        if(!is_int($reviewPk) || $reviewPk < 1) { throw new InvalidArgumentException('activity_review_pk_invalid'); }
        $review = $this->repo->reviewCaseByPk($reviewPk);
        if(!$review || $review['state_code'] !== 'pending' || (int)$review['auto_due_at'] < 1) {
            return 'not_pending';
        }
        $uid = (int)$review['uid'];
        $activityPk = (int)$review['activity_pk'];
        $map = $this->repo->activityByPk($activityPk);
        if(!$map || $map['state_code'] !== 'active' || (int)$map['tid'] < 1) {
            return 'source_unavailable';
        }
        try {
            $projection = $this->thread->publicProjection((int)$map['tid'], 'en-US');
            $binding = $this->repo->activeBindingForUid($uid);
            $participant = $this->repo->participationForUid($activityPk, $uid);
            $this->assertContext($review, $map, $projection, $binding, $participant);
            $observed = $this->evidence->observe($uid, $map['activity_id'], (int)$map['stage_version'],
                $binding['togya_user_id'], $review['kind'], $review['stage_id'],
                $review['source_ref'], 'en-US', (string)$review['source_page_cursor']);
        } catch(Exception $error) {
            return 'source_unavailable';
        }
        $verified = array('current' => true, 'kind' => $review['kind'], 'activity_pk' => $activityPk,
            'participant_pk' => (int)$review['participant_pk'], 'uid' => $uid,
            'source_ref' => $observed['source_ref'], 'stage_id' => $observed['stage_id'],
            'stage_version' => $observed['stage_version'],
            'binding_generation' => (int)$binding['generation'],
            'source_digest' => $observed['source_digest']);
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                $currentMap = $this->repo->activityByPk($activityPk, true);
                $currentParticipant = $this->repo->participationForUid($activityPk, $uid, true);
                $currentReview = $this->repo->reviewCaseByPk($reviewPk, true);
                if(!$currentReview || $currentReview['state_code'] !== 'pending' ||
                    $currentReview['decision_source'] !== 'none') {
                    $this->repo->commit();
                    return 'not_pending';
                }
                $this->assertContext($currentReview, $currentMap, $projection,
                    $currentBinding, $currentParticipant);
                $currentThread = $this->repo->activityThread((int)$currentMap['tid'], true);
                if(!$currentThread || (int)$currentThread['fid'] !== (int)$currentMap['fid'] ||
                    (int)$currentThread['authorid'] !== (int)$currentMap['owner_uid'] ||
                    (int)$currentThread['special'] !== 127 ||
                    (int)$currentThread['displayorder'] < 0 ||
                    $this->repo->activityFirstPostMessage((int)$currentMap['tid']) !==
                        PintogyaActivityNativePost::messageForDigest((string)$currentMap['reservation_digest'])) {
                    throw new RuntimeException('activity_review_context_changed');
                }
                if((int)$currentBinding['generation'] !== (int)$binding['generation'] ||
                    !hash_equals((string)$currentBinding['togya_user_id'], (string)$binding['togya_user_id'])) {
                    throw new RuntimeException('activity_review_identity_changed');
                }
                $now = $this->repo->currentUnixTime();
                $decision = PintogyaActivityReviewPolicy::timeoutDecision($currentReview, $verified, $now);
                $this->repo->decideReviewCase($reviewPk, $decision);
                $this->repo->insertReviewDecision($currentReview, $decision);
                $this->repo->commit();
                return 'approved';
            } catch(Throwable $error) {
                $this->repo->rollback();
                if(in_array($error->getMessage(), array('activity_review_evidence_changed',
                    'activity_review_not_due', 'activity_review_context_changed',
                    'activity_review_identity_changed'), true)) {
                    return 'source_unavailable';
                }
                throw $error;
            }
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    private function assertContext($review, $map, $projection, $binding, $participant) {
        if(!$map || !$binding || !$participant ||
            $map['state_code'] !== 'active' ||
            (int)$map['activity_pk'] !== (int)$review['activity_pk'] ||
            (int)$map['tid'] !== (int)$projection['tid'] ||
            $map['activity_id'] !== $projection['activity']['id'] ||
            (int)$map['stage_version'] !== $projection['activity']['stageVersion'] ||
            !hash_equals((string)$map['source_hash'],
                PintogyaActivityPublish::sourceHash($projection['activity'])) ||
            $participant['state_code'] !== 'active' ||
            (int)$participant['participant_pk'] !== (int)$review['participant_pk'] ||
            (int)$participant['activity_pk'] !== (int)$review['activity_pk'] ||
            (int)$participant['uid'] !== (int)$review['uid'] ||
            (int)$binding['uid'] !== (int)$review['uid'] ||
            (int)$binding['generation'] !== (int)$review['binding_generation'] ||
            (int)$participant['binding_generation'] !== (int)$review['binding_generation'] ||
            (int)$participant['stage_version'] !== (int)$review['stage_version'] ||
            !hash_equals((string)$participant['togya_user_id'], (string)$binding['togya_user_id'])) {
            throw new RuntimeException('activity_review_context_changed');
        }
    }
}
