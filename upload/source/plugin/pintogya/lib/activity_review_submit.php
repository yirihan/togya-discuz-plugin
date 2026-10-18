<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_review_policy.php';

final class PintogyaActivityReviewSubmit {
    private $repo;
    private $thread;
    private $evidence;

    public function __construct($repo, $thread, $evidence) {
        $this->repo = $repo;
        $this->thread = $thread;
        $this->evidence = $evidence;
    }

    public function submit($uid, $tid, $kind, $stageId, $checkinId, $locale, $daysJson) {
        if(!is_int($uid) || $uid < 1 || !is_int($tid) || $tid < 1 || $tid > 4294967295 ||
            !in_array($kind, array('checkin', 'completion'), true)) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        if($kind === 'checkin') {
            $stageId = PintogyaCrypto::assertUuid($stageId);
            $sourceRef = PintogyaCrypto::assertUuid($checkinId);
        } else {
            if($stageId !== null || $checkinId !== null) {
                throw new InvalidArgumentException('activity_review_input_invalid');
            }
            $stageId = null;
            $sourceRef = null;
        }
        $days = PintogyaActivitySettings::reviewAutoDaysFromJson($daysJson);
        $projection = $this->thread->publicProjection($tid, $locale);
        $activity = $projection['activity'];
        if($sourceRef === null) { $sourceRef = $activity['id']; }
        $activityPk = $projection['activityPk'];
        $binding = $this->repo->activeBindingForUid($uid);
        $participant = $this->repo->participationForUid($activityPk, $uid);
        $this->assertParticipant($participant, $binding, $activity, $uid, $activityPk);
        // Network reads precede all row locks; the transaction rechecks local identity before writing.
        $observed = $this->evidence->observe($uid, $activity['id'], $activity['stageVersion'],
            $binding['togya_user_id'], $kind, $stageId, $sourceRef, $locale);
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                $map = $this->repo->activityByTid($tid, true);
                $currentParticipant = $this->repo->participationForUid($activityPk, $uid, true);
                $this->assertParticipant($currentParticipant, $currentBinding, $activity, $uid, $activityPk);
                if(!$map || (int)$map['activity_pk'] !== $activityPk ||
                    (int)$map['tid'] !== $tid || $map['state_code'] !== 'active' ||
                    $map['activity_id'] !== $activity['id'] ||
                    (int)$map['stage_version'] !== $activity['stageVersion'] ||
                    !hash_equals((string)$map['source_hash'],
                        PintogyaActivityPublish::sourceHash($activity)) ||
                    (int)$currentBinding['generation'] !== (int)$binding['generation'] ||
                    !hash_equals((string)$currentBinding['togya_user_id'], (string)$binding['togya_user_id']) ||
                    (int)$currentParticipant['participant_pk'] !== (int)$participant['participant_pk']) {
                    throw new RuntimeException('activity_review_context_changed');
                }
                $thread = $this->repo->activityThread($tid, true);
                if(!$thread || (int)$thread['fid'] !== (int)$map['fid'] ||
                    (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
                    (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
                    $this->repo->activityFirstPostMessage($tid) !==
                        PintogyaActivityNativePost::messageForDigest((string)$map['reservation_digest'])) {
                    throw new RuntimeException('activity_review_thread_changed');
                }
                $rows = $this->repo->reviewCasesForSource($activityPk, $uid, $kind, $sourceRef, true);
                if(count($rows) > 20) { throw new RuntimeException('activity_review_history_limit'); }
                $latest = $rows ? $rows[count($rows) - 1] : null;
                $matching = null;
                foreach($rows as $row) {
                    if(hash_equals((string)$row['source_digest'], $observed['source_digest'])) {
                        if((int)$row['review_pk'] !== (int)$latest['review_pk']) {
                            throw new RuntimeException('activity_review_evidence_previously_reviewed');
                        }
                        $matching = $row;
                        continue;
                    }
                    if($row['state_code'] === 'pending') {
                        $this->repo->invalidatePendingReviewCase((int)$row['review_pk'],
                            $this->repo->currentUnixTime());
                    }
                }
                if($matching !== null) {
                    $this->repo->commit();
                    return $this->result($matching);
                }
                $now = $this->repo->currentUnixTime();
                $dueAt = PintogyaActivityReviewPolicy::dueAt($kind, $now, $days[$kind]);
                $reviewPk = $this->repo->insertReviewCase(array(
                    'activity_pk' => $activityPk, 'participant_pk' => (int)$currentParticipant['participant_pk'],
                    'uid' => $uid, 'kind' => $kind, 'source_ref' => $sourceRef,
                    'source_digest' => $observed['source_digest'],
                    'source_page_cursor' => $observed['source_page_cursor'], 'stage_id' => $stageId,
                    'stage_version' => $activity['stageVersion'],
                    'binding_generation' => (int)$currentBinding['generation'],
                    'submitted_at' => $now, 'auto_due_at' => $dueAt,
                    'state_code' => 'pending', 'decision_source' => 'none',
                    'decided_by_uid' => null, 'decided_at' => 0, 'decision_digest' => null,
                    'updated_at' => $now,
                ));
                $this->repo->commit();
                return array('reviewPk' => $reviewPk, 'state' => 'pending', 'autoDueAt' => $dueAt);
            } catch(Throwable $error) {
                $this->repo->rollback();
                throw $error;
            }
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    private function assertParticipant($participant, $binding, $activity, $uid, $activityPk) {
        if(!$participant || !$binding || $participant['state_code'] !== 'active' ||
            (int)$participant['activity_pk'] !== $activityPk || (int)$participant['uid'] !== $uid ||
            (int)$binding['uid'] !== $uid ||
            (int)$participant['binding_generation'] !== (int)$binding['generation'] ||
            (int)$participant['stage_version'] !== $activity['stageVersion'] ||
            !hash_equals((string)$participant['togya_user_id'], (string)$binding['togya_user_id'])) {
            throw new RuntimeException('activity_review_participation_required');
        }
    }

    private function result($row) {
        return array('reviewPk' => (int)$row['review_pk'], 'state' => $row['state_code'],
            'autoDueAt' => (int)$row['auto_due_at']);
    }
}
