<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_review_access.php';

final class PintogyaActivityReviewStatus {
    private $repo;
    private $thread;

    public function __construct($repo, $thread) {
        $this->repo = $repo;
        $this->thread = $thread;
    }

    public function forMember($uid, $tid, $locale) {
        if(!is_int($uid) || $uid < 1 || !is_int($tid) || $tid < 1 || $tid > 4294967295) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        $projection = $this->thread->publicProjection($tid, $locale);
        $activityPk = (int)$projection['activityPk'];
        $map = $this->repo->activityByTid($tid);
        $binding = $this->repo->activeBindingForUid($uid);
        $participant = $this->repo->participationForUid($activityPk, $uid);
        if(!$map || !$binding || !$participant || $map['state_code'] !== 'active' ||
            (int)$map['activity_pk'] !== $activityPk || (int)$map['tid'] !== $tid ||
            !hash_equals((string)$map['activity_id'], (string)$projection['activity']['id']) ||
            (int)$map['stage_version'] !== (int)$projection['activity']['stageVersion'] ||
            !hash_equals((string)$map['source_hash'],
                PintogyaActivityPublish::sourceHash($projection['activity'])) ||
            $participant['state_code'] !== 'active' ||
            (int)$participant['uid'] !== $uid || (int)$participant['activity_pk'] !== $activityPk ||
            (int)$participant['stage_version'] !== (int)$map['stage_version'] ||
            (int)$binding['uid'] !== $uid ||
            (int)$participant['binding_generation'] !== (int)$binding['generation'] ||
            !hash_equals((string)$participant['togya_user_id'], (string)$binding['togya_user_id'])) {
            throw new RuntimeException('activity_review_participation_required');
        }
        $rows = $this->repo->reviewCasesForMember($activityPk, $uid, 100);
        $items = array();
        $seen = array();
        foreach($rows as $row) {
            if((int)$row['stage_version'] !== (int)$map['stage_version'] ||
                (int)$row['binding_generation'] !== (int)$binding['generation']) {
                continue;
            }
            $kind = (string)$row['kind'];
            $ref = (string)$row['source_ref'];
            if(!in_array($kind, array('checkin', 'completion'), true) ||
                !in_array($row['state_code'], array('pending', 'approved', 'rejected', 'invalid'), true) ||
                !in_array($row['decision_source'], array('none', 'moderator', 'timeout'), true)) {
                throw new RuntimeException('activity_review_state_invalid');
            }
            PintogyaCrypto::assertUuid($ref);
            if($kind === 'completion' && !hash_equals((string)$map['activity_id'], $ref)) {
                throw new RuntimeException('activity_review_state_invalid');
            }
            $key = $kind === 'completion' ? 'completion' : 'checkin:'.$ref;
            if(isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $items[] = array('kind' => $kind, 'sourceRef' => $ref,
                'state' => $row['state_code'], 'autoDueAt' => (int)$row['auto_due_at'],
                'decisionSource' => $row['decision_source']);
        }
        return array('items' => $items);
    }

    public function forModerator($reviewerUid, $tid, $afterPk, $locale) {
        if(!is_int($reviewerUid) || $reviewerUid < 1 || !is_int($tid) || $tid < 1 ||
            $tid > 4294967295 || !is_int($afterPk) || $afterPk < 0) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        $scope = (new PintogyaActivityReviewAccess($this->repo))->assertCurrentModerator($reviewerUid, $tid);
        $projection = $this->thread->publicProjection($tid, $locale);
        if((int)$projection['activityPk'] !== $scope['activity_pk'] ||
            (int)$projection['activity']['stageVersion'] !== $scope['stage_version']) {
            throw new RuntimeException('activity_review_context_changed');
        }
        $rows = $this->repo->pendingReviewCasesForActivity($scope['activity_pk'], $afterPk, 21);
        $hasMore = count($rows) > 20;
        $page = array_slice($rows, 0, 20);
        $items = array();
        foreach($page as $row) {
            if((int)$row['stage_version'] !== $scope['stage_version']) { continue; }
            if(!in_array($row['kind'], array('checkin', 'completion'), true) ||
                (int)$row['uid'] < 1 || (int)$row['review_pk'] <= $afterPk) {
                throw new RuntimeException('activity_review_state_invalid');
            }
            PintogyaCrypto::assertUuid((string)$row['source_ref']);
            if($row['kind'] === 'checkin') { PintogyaCrypto::assertUuid((string)$row['stage_id']); }
            elseif($row['stage_id'] !== null ||
                !hash_equals((string)$projection['activity']['id'], (string)$row['source_ref'])) {
                throw new RuntimeException('activity_review_state_invalid');
            }
            $items[] = array('reviewPk' => (int)$row['review_pk'], 'uid' => (int)$row['uid'],
                'kind' => $row['kind'],
                'submittedAt' => (int)$row['submitted_at'], 'autoDueAt' => (int)$row['auto_due_at'],
                'canDecide' => (int)$row['uid'] !== $reviewerUid);
        }
        $currentScope = (new PintogyaActivityReviewAccess($this->repo))->assertCurrentModerator($reviewerUid, $tid);
        if($currentScope !== $scope) { throw new RuntimeException('activity_review_context_changed'); }
        return array('items' => $items, 'nextCursor' => $page ? (int)end($page)['review_pk'] : $afterPk,
            'hasMore' => $hasMore);
    }
}
