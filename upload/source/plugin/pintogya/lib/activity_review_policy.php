<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityReviewPolicy {
    const MAX_TIME = 4294967295;

    public static function dueAt($kind, $submittedAt, $days) {
        self::kind($kind);
        $submittedAt = self::number($submittedAt, 1, self::MAX_TIME, 'activity_review_time_invalid');
        $days = self::number($days, 0, 365, 'activity_review_days_invalid');
        if($days === 0) { return 0; }
        $due = $submittedAt + $days * 86400;
        if($due > self::MAX_TIME) { throw new InvalidArgumentException('activity_review_time_invalid'); }
        return $due;
    }

    public static function timeoutDecision($review, $verified, $now) {
        $context = self::pendingContext($review, $verified);
        $now = self::number($now, 1, self::MAX_TIME, 'activity_review_decision_invalid');
        if($context['due_at'] <= $context['submitted_at'] || $now < $context['due_at']) {
            throw new RuntimeException('activity_review_not_due');
        }
        return array('review_pk' => $context['review_pk'], 'kind' => $context['kind'],
            'decision' => 'approved', 'decision_source' => 'timeout',
            'decided_by_uid' => null, 'decided_at' => $now,
            'decision_digest' => hash('sha256', "pintogya/review/timeout/v1\0".
                $context['review_pk']."\0".$context['digest']."\0".$context['due_at']));
    }

    public static function moderatorDecision($review, $verified, $reviewerUid, $decision, $now) {
        $context = self::pendingContext($review, $verified);
        $reviewerUid = self::number($reviewerUid, 1, 16777215, 'activity_review_decision_invalid');
        $now = self::number($now, 1, self::MAX_TIME, 'activity_review_decision_invalid');
        if(!in_array($decision, array('approved', 'rejected'), true)) {
            throw new InvalidArgumentException('activity_review_decision_invalid');
        }
        if($context['due_at'] > 0 && $now >= $context['due_at']) {
            throw new RuntimeException('activity_review_deadline_elapsed');
        }
        return array('review_pk' => $context['review_pk'], 'kind' => $context['kind'],
            'decision' => $decision, 'decision_source' => 'moderator',
            'decided_by_uid' => $reviewerUid, 'decided_at' => $now,
            'decision_digest' => hash('sha256', "pintogya/review/moderator/v1\0".
                $context['review_pk']."\0".$context['digest']."\0".$reviewerUid."\0".$decision));
    }

    private static function pendingContext($review, $verified) {
        if(!is_array($review) || !is_array($verified)) {
            throw new InvalidArgumentException('activity_review_decision_invalid');
        }
        $kind = self::kind($review['kind'] ?? null);
        $reviewPk = self::number($review['review_pk'] ?? null, 1, PHP_INT_MAX, 'activity_review_decision_invalid');
        $activityPk = self::number($review['activity_pk'] ?? null, 1, PHP_INT_MAX, 'activity_review_decision_invalid');
        $participantPk = self::number($review['participant_pk'] ?? null, 1, PHP_INT_MAX, 'activity_review_decision_invalid');
        $uid = self::number($review['uid'] ?? null, 1, 16777215, 'activity_review_decision_invalid');
        $stageVersion = self::number($review['stage_version'] ?? null, 1, PHP_INT_MAX, 'activity_review_decision_invalid');
        $generation = self::number($review['binding_generation'] ?? null, 1, PHP_INT_MAX, 'activity_review_decision_invalid');
        $submittedAt = self::number($review['submitted_at'] ?? null, 1, self::MAX_TIME, 'activity_review_decision_invalid');
        $dueAt = self::number($review['auto_due_at'] ?? null, 0, self::MAX_TIME, 'activity_review_decision_invalid');
        $sourceRef = self::uuid($review['source_ref'] ?? null);
        $stageId = $review['stage_id'] ?? null;
        if($kind === 'checkin') { $stageId = self::uuid($stageId); }
        elseif($stageId !== null) { throw new InvalidArgumentException('activity_review_decision_invalid'); }
        $digest = $review['source_digest'] ?? null;
        if(!is_string($digest) || !preg_match('/^[0-9a-f]{64}$/D', $digest) ||
            ($review['state_code'] ?? null) !== 'pending' || ($review['decision_source'] ?? null) !== 'none' ||
            ($review['decided_by_uid'] ?? null) !== null ||
            self::number($review['decided_at'] ?? null, 0, self::MAX_TIME, 'activity_review_decision_invalid') !== 0 ||
            ($dueAt > 0 && $dueAt <= $submittedAt)) {
            throw new RuntimeException('activity_review_not_due');
        }
        if(($verified['current'] ?? null) !== true ||
            ($verified['kind'] ?? null) !== $kind ||
            self::number($verified['activity_pk'] ?? null, 1, PHP_INT_MAX, 'activity_review_evidence_changed') !== $activityPk ||
            self::number($verified['participant_pk'] ?? null, 1, PHP_INT_MAX, 'activity_review_evidence_changed') !== $participantPk ||
            self::number($verified['uid'] ?? null, 1, 16777215, 'activity_review_evidence_changed') !== $uid ||
            ($verified['source_ref'] ?? null) !== $sourceRef ||
            ($verified['stage_id'] ?? null) !== $stageId ||
            self::number($verified['stage_version'] ?? null, 1, PHP_INT_MAX, 'activity_review_evidence_changed') !== $stageVersion ||
            self::number($verified['binding_generation'] ?? null, 1, PHP_INT_MAX, 'activity_review_evidence_changed') !== $generation ||
            !is_string($verified['source_digest'] ?? null) ||
            !hash_equals($digest, $verified['source_digest'])) {
            throw new RuntimeException('activity_review_evidence_changed');
        }
        return array('review_pk' => $reviewPk, 'kind' => $kind, 'digest' => $digest,
            'submitted_at' => $submittedAt, 'due_at' => $dueAt);
    }

    private static function kind($value) {
        if(!in_array($value, array('checkin', 'completion'), true)) {
            throw new InvalidArgumentException('activity_review_kind_invalid');
        }
        return $value;
    }

    private static function uuid($value) {
        if(!is_string($value) ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidArgumentException('activity_review_source_invalid');
        }
        return $value;
    }

    private static function number($value, $minimum, $maximum, $error) {
        if(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $parsed = (int)$value;
            if((string)$parsed !== $value) { throw new InvalidArgumentException($error); }
            $value = $parsed;
        }
        if(!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }
}
