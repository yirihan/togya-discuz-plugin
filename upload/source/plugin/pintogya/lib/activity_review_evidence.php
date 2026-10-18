<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityReviewEvidence {
    private $session;
    private $digestKey;

    public function __construct($session, $digestKey = null) {
        $this->session = $session;
        $this->digestKey = $digestKey === null ? PintogyaCrypto::tokenKeyFromEnvironment() : $digestKey;
        if(!is_string($this->digestKey) || strlen($this->digestKey) !== 32) {
            throw new RuntimeException('activity_review_key_unavailable');
        }
    }

    public function observe($uid, $activityId, $stageVersion, $togyaUserId, $kind, $stageId, $sourceRef, $locale, $startCursor = '') {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_int($uid) || $uid < 1 || !is_int($stageVersion) || $stageVersion < 1 ||
            !PintogyaCrypto::validOpaqueId($togyaUserId) || !in_array($kind, array('checkin', 'completion'), true) ||
            !is_string($startCursor) || ($startCursor !== '' &&
                !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $startCursor))) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        $binding = $this->session->binding($uid);
        if(!hash_equals((string)$binding['togya_user_id'], $togyaUserId)) {
            throw new RuntimeException('activity_review_identity_changed');
        }
        $detail = $this->session->activityDetail($uid, $activityId, $locale);
        if(!is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
            ($detail['stageVersion'] ?? null) !== $stageVersion || ($detail['isPublic'] ?? null) !== true ||
            ($detail['isJoined'] ?? null) !== true ||
            !in_array($detail['status'] ?? null, array('active', 'archived'), true)) {
            throw new RuntimeException('activity_review_source_unavailable');
        }
        if($kind === 'completion') {
            if($stageId !== null || $sourceRef !== $activityId ||
                !in_array($detail['progress'] ?? null, array(1, 1.0), true)) {
                throw new RuntimeException('activity_review_source_unavailable');
            }
            $digest = $this->digest(array('completion', $activityId, $stageVersion, $togyaUserId, 1));
            return array('kind' => $kind, 'source_ref' => $activityId, 'stage_id' => null,
                'stage_version' => $stageVersion, 'source_digest' => $digest,
                'source_page_cursor' => '');
        }
        $stageId = PintogyaCrypto::assertUuid($stageId);
        $sourceRef = PintogyaCrypto::assertUuid($sourceRef);
        $stages = $detail['stageDetails'] ?? null;
        if(!is_array($stages)) { throw new RuntimeException('activity_review_source_unavailable'); }
        $allowed = false;
        foreach($stages as $stage) {
            if(is_array($stage) && ($stage['id'] ?? null) === $stageId && ($stage['canViewFeed'] ?? null) === true) {
                $allowed = true;
                break;
            }
        }
        if(!$allowed) { throw new RuntimeException('activity_review_source_unavailable'); }
        $cursor = $startCursor;
        $seen = $cursor === '' ? array() : array($cursor => true);
        for($page = 0; $page < 10; $page++) {
            $feed = $this->session->stageFeed($uid, $activityId, $stageId, $stageVersion, $cursor);
            if(!is_array($feed) || ($feed['activityId'] ?? null) !== $activityId ||
                ($feed['stageId'] ?? null) !== $stageId || ($feed['stageVersion'] ?? null) !== $stageVersion ||
                ($feed['view'] ?? null) !== 'latest' || !is_array($feed['items'] ?? null) ||
                count($feed['items']) > 20) {
                throw new RuntimeException('activity_review_source_unavailable');
            }
            foreach($feed['items'] as $item) {
                if(!is_array($item) || ($item['id'] ?? null) !== $sourceRef) { continue; }
                if(($item['activityId'] ?? null) !== $activityId || ($item['stageId'] ?? null) !== $stageId ||
                    ($item['stageVersion'] ?? null) !== $stageVersion || ($item['isSameActivity'] ?? null) !== true ||
                    !in_array($item['kind'] ?? null, array('progress', 'completion'), true) ||
                    !is_string($item['author']['id'] ?? null) ||
                    !hash_equals($togyaUserId, $item['author']['id']) ||
                    !is_string($item['body'] ?? null) || strlen($item['body']) > 8192 ||
                    !is_string($item['createdAt'] ?? null) || strlen($item['createdAt']) > 64 ||
                    !is_bool($item['completesStage'] ?? null) ||
                    !in_array($item['evidenceVerificationStatus'] ?? null,
                        array('not_requested', 'verified'), true) ||
                    !is_string($item['imageKey'] ?? null) || !is_array($item['imageKeys'] ?? null) ||
                    count($item['imageKeys']) > 20) {
                    throw new RuntimeException('activity_review_source_unavailable');
                }
                foreach($item['imageKeys'] as $key) {
                    if(!is_string($key) || strlen($key) > 500) {
                        throw new RuntimeException('activity_review_source_unavailable');
                    }
                }
                $digest = $this->digest(array('checkin', $activityId, $stageId, $stageVersion,
                    $togyaUserId, $sourceRef, $item['kind'], $item['body'], $item['createdAt'],
                    $item['completesStage'], $item['evidenceVerificationStatus'],
                    $item['imageKey'], $item['imageKeys']));
                return array('kind' => $kind, 'source_ref' => $sourceRef, 'stage_id' => $stageId,
                    'stage_version' => $stageVersion, 'source_digest' => $digest,
                    'source_page_cursor' => $cursor !== '' ? $cursor :
                        $this->anchorCursor($togyaUserId, $activityId, $stageId, $stageVersion,
                            $sourceRef, $item['createdAt']));
            }
            $next = $feed['nextCursor'] ?? null;
            if($next === null) { break; }
            if(!is_string($next) || !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $next) || isset($seen[$next])) {
                throw new RuntimeException('activity_review_source_unavailable');
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        throw new RuntimeException('activity_review_source_unavailable');
    }

    private function anchorCursor($togyaUserId, $activityId, $stageId, $stageVersion, $sourceRef, $createdAt) {
        try {
            $stamp = (new DateTimeImmutable($createdAt))->modify('+1 microsecond');
            $scope = array($togyaUserId, $activityId, $stageId, $stageVersion, 'latest');
            $raw = json_encode(array($scope, $stamp->format('Y-m-d\TH:i:s.uP'), $sourceRef, 0),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $cursor = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            if(strlen($cursor) > 2048) { throw new RuntimeException('activity_review_cursor_invalid'); }
            return $cursor;
        } catch(Exception $error) {
            throw new RuntimeException('activity_review_source_unavailable', 0, $error);
        }
    }

    private function digest($facts) {
        return hash_hmac('sha256', "pintogya/review/source/v1\0".
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->digestKey);
    }
}
