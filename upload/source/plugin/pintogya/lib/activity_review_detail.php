<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_review_access.php';

final class PintogyaActivityReviewDetail {
    private $repo;
    private $thread;
    private $access;

    public function __construct($repo, $thread, $access) {
        $this->repo = $repo;
        $this->thread = $thread;
        $this->access = $access;
    }

    public function checkinForModerator($reviewerUid, $tid, $reviewPk, $locale) {
        if(!is_int($reviewerUid) || $reviewerUid < 1 || !is_int($tid) || $tid < 1 ||
            !is_int($reviewPk) || $reviewPk < 1 || !is_string($locale)) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        $scope = $this->access->assertCurrentModerator($reviewerUid, $tid);
        $review = $this->repo->reviewCaseByPk($reviewPk);
        $projection = $this->thread->publicProjection($tid, $locale);
        if(!$review || $review['kind'] !== 'checkin' || $review['state_code'] !== 'pending' ||
            $review['decision_source'] !== 'none' || (int)$review['uid'] === $reviewerUid ||
            (int)$review['activity_pk'] !== $scope['activity_pk'] ||
            (int)$review['stage_version'] !== $scope['stage_version'] ||
            (int)$projection['activityPk'] !== $scope['activity_pk'] ||
            (int)$projection['activity']['stageVersion'] !== $scope['stage_version']) {
            throw new RuntimeException('activity_review_evidence_unavailable');
        }
        $stageId = PintogyaCrypto::assertUuid((string)$review['stage_id']);
        $sourceRef = PintogyaCrypto::assertUuid((string)$review['source_ref']);
        $cursor = '';
        $seen = array();
        for($page = 0; $page < 10; $page++) {
            // This uses the moderator's own Togya session and stage visibility, never the subject's.
            $feed = $this->thread->stageFeed($reviewerUid, $tid, $locale, $stageId, $cursor);
            if(!is_array($feed) || ($feed['stageId'] ?? null) !== $stageId ||
                !is_array($feed['items'] ?? null) || count($feed['items']) > 20) {
                throw new RuntimeException('activity_review_evidence_unavailable');
            }
            foreach($feed['items'] as $item) {
                if(!is_array($item) || ($item['checkinId'] ?? null) !== $sourceRef) { continue; }
                if(!is_string($item['author'] ?? null) || !is_string($item['body'] ?? null) ||
                    !is_string($item['createdAt'] ?? null) || !is_bool($item['hasMedia'] ?? null)) {
                    throw new RuntimeException('activity_review_evidence_unavailable');
                }
                $currentScope = $this->access->assertCurrentModerator($reviewerUid, $tid);
                $current = $this->repo->reviewCaseByPk($reviewPk);
                if($currentScope !== $scope || !$current || $current['state_code'] !== 'pending' ||
                    $current['decision_source'] !== 'none' ||
                    (int)$current['uid'] !== (int)$review['uid'] ||
                    (int)$current['activity_pk'] !== $scope['activity_pk'] ||
                    (int)$current['stage_version'] !== $scope['stage_version'] ||
                    (string)$current['stage_id'] !== $stageId ||
                    (string)$current['source_ref'] !== $sourceRef) {
                    throw new RuntimeException('activity_review_evidence_unavailable');
                }
                return array('reviewPk' => $reviewPk, 'kind' => 'checkin',
                    'author' => $item['author'], 'body' => $item['body'],
                    'createdAt' => $item['createdAt'], 'hasMedia' => $item['hasMedia'],
                    'stageId' => $stageId, 'checkinId' => $sourceRef, 'cursor' => $cursor);
            }
            $next = $feed['nextCursor'] ?? null;
            if($next === null) { break; }
            if(!is_string($next) || !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $next) || isset($seen[$next])) {
                throw new RuntimeException('activity_review_evidence_unavailable');
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        throw new RuntimeException('activity_review_evidence_unavailable');
    }
}
