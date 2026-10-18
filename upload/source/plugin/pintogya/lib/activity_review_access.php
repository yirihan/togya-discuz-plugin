<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_native_post.php';
require_once __DIR__.'/activity_settings.php';

final class PintogyaActivityReviewAccess {
    private $repo;

    public function __construct($repo) { $this->repo = $repo; }

    public function assertCurrentModerator($reviewerUid, $tid, $lock = false) {
        if(!is_int($reviewerUid) || $reviewerUid < 1 || !is_int($tid) || $tid < 1) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        $adminId = $this->repo->memberAdminId($reviewerUid, $lock);
        $map = $this->repo->activityByTid($tid, $lock);
        $thread = $this->repo->activityThread($tid, $lock);
        if(!$map || !$thread || ($map['state_code'] ?? null) !== 'active' ||
            (int)$map['tid'] !== $tid || (int)$map['fid'] < 1 ||
            (int)$thread['tid'] !== $tid ||
            (int)$thread['fid'] !== (int)$map['fid'] ||
            (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
            (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
            $this->repo->activityFirstPostMessage($tid) !==
                PintogyaActivityNativePost::messageForDigest((string)($map['reservation_digest'] ?? ''))) {
            throw new RuntimeException('activity_review_thread_changed');
        }
        if(!in_array($adminId, array(1, 2), true) &&
            ($adminId !== 3 || !$this->repo->isForumModerator((int)$map['fid'], $reviewerUid, $lock))) {
            throw new RuntimeException('activity_review_forbidden');
        }
        return array('activity_pk' => (int)$map['activity_pk'],
            'stage_version' => (int)$map['stage_version']);
    }

    // Recheck inside the decision transaction; this only authorizes local review, not a Togya receipt or payout.
    public function assertCurrentReviewer($reviewerUid, $tid, $subjectUid, $extcredits) {
        if(!is_int($reviewerUid) || $reviewerUid < 1 || !is_int($tid) || $tid < 1 ||
            !is_int($subjectUid) || $subjectUid < 1 || !is_array($extcredits)) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        if($reviewerUid === $subjectUid) { throw new RuntimeException('activity_review_self_forbidden'); }
        $adminId = $this->repo->memberAdminId($reviewerUid, true);
        $map = $this->repo->activityByTid($tid, true);
        $thread = $this->repo->activityThread($tid, true);
        if(!$map || !$thread || ($map['state_code'] ?? null) !== 'active' ||
            (int)$map['tid'] !== $tid || (int)$map['fid'] < 1 ||
            (int)$thread['tid'] !== $tid || (int)$thread['fid'] !== (int)$map['fid'] ||
            (int)$thread['authorid'] !== (int)$map['owner_uid'] || (int)$thread['special'] !== 127 ||
            (int)$thread['displayorder'] < 0 ||
            $this->repo->activityFirstPostMessage($tid) !==
                PintogyaActivityNativePost::messageForDigest((string)($map['reservation_digest'] ?? ''))) {
            throw new RuntimeException('activity_review_thread_changed');
        }
        if(!in_array($adminId, array(1, 2), true) &&
            ($adminId !== 3 || !$this->repo->isForumModerator((int)$map['fid'], $reviewerUid, true))) {
            throw new RuntimeException('activity_review_forbidden');
        }
        $participant = $this->repo->participationForUid((int)$map['activity_pk'], $subjectUid, true);
        if(!$participant || ($participant['state_code'] ?? null) !== 'active' ||
            (int)$participant['uid'] !== $subjectUid ||
            (int)$participant['activity_pk'] !== (int)$map['activity_pk'] ||
            (int)$participant['stage_version'] !== (int)$map['stage_version']) {
            throw new RuntimeException('activity_review_participant_unavailable');
        }
        $pool = $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], true);
        $rule = $pool ? $this->repo->activityRewardRuleByPk((int)$pool['rule_pk']) : null;
        if(!$pool || !in_array($pool['state_code'] ?? null, array('open', 'closing'), true) || !$rule ||
            (int)$pool['activity_pk'] !== (int)$map['activity_pk'] ||
            !hash_equals((string)$rule['activity_id'], (string)$map['activity_id']) ||
            !hash_equals((string)$pool['rule_hash'], (string)$rule['rule_hash']) ||
            !hash_equals((string)$rule['rule_hash'], hash('sha256',
                "pintogya/activity-reward-rule/v1\0".(string)$rule['rules_json']))) {
            throw new RuntimeException('activity_review_rule_unavailable');
        }
        try {
            $defaults = PintogyaActivitySettings::rewardDefaultsFromJson((string)$rule['rules_json'], $extcredits);
        } catch(InvalidArgumentException $error) {
            throw new RuntimeException('activity_review_rule_unavailable', 0, $error);
        }
        if($defaults['version'] !== 2 || (int)$pool['credit_id'] !== $defaults['credit_id'] ||
            (int)$rule['credit_id'] !== $defaults['credit_id']) {
            throw new RuntimeException('activity_review_rule_unavailable');
        }
        PintogyaActivityDeadlines::assertOpen((int)$map['published_at'], $defaults['window_days'], 'review',
            $this->repo->currentUnixTime());
        return array('activity_pk' => (int)$map['activity_pk'], 'participant_pk' => (int)$participant['participant_pk'],
            'fid' => (int)$map['fid'], 'stage_version' => (int)$map['stage_version']);
    }
}
