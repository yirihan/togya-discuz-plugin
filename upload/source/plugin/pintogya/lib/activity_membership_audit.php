<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once __DIR__.'/crypto.php';
require_once __DIR__.'/activity_publish.php';
require_once __DIR__.'/activity_membership_failure.php';

final class PintogyaActivityMembershipAudit {
    private $repo;
    private $session;
    private $reader;
    private $thread;

    public function __construct($repo, $session, $reader, $thread) {
        $this->repo = $repo;
        $this->session = $session;
        $this->reader = $reader;
        $this->thread = $thread;
    }

    public function scanPage($tid, $afterPk, $limit, $locale) {
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295 || !is_int($afterPk) || $afterPk < 0 ||
            !is_int($limit) || $limit < 1 || $limit > 20 || !is_string($locale) ||
            !preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $locale)) {
            throw new InvalidArgumentException('activity_membership_audit_input_invalid');
        }
        $projection = $this->thread->publicProjection($tid, $locale);
        $activity = $projection['activity'];
        $map = $this->repo->activityByTid($tid);
        if(!$map || $map['state_code'] !== 'active' || (int)$map['activity_pk'] !== $projection['activityPk'] ||
            (int)$map['fid'] !== $projection['fid'] || (int)$map['tid'] !== $tid ||
            (int)$map['stage_version'] !== $activity['stageVersion'] ||
            !hash_equals((string)$map['activity_id'], (string)$activity['id']) ||
            !hash_equals((string)$map['source_hash'], PintogyaActivityPublish::sourceHash($activity))) {
            throw new RuntimeException('activity_membership_audit_source_changed');
        }
        $rows = $this->repo->participationAuditPage((int)$map['activity_pk'], $afterPk, $limit);
        if(!is_array($rows) || count($rows) > $limit + 1) {
            throw new RuntimeException('activity_membership_audit_page_invalid');
        }
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array();
        $cursor = $afterPk;
        foreach($rows as $row) {
            if(!is_array($row)) { throw new RuntimeException('activity_membership_audit_row_invalid'); }
            $pk = (int)($row['participant_pk'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            if($pk <= $cursor || $uid < 1 || $row['state_code'] !== 'active' ||
                !PintogyaCrypto::validOpaqueId($row['togya_user_id'] ?? null) ||
                (int)($row['binding_generation'] ?? 0) < 1 ||
                (int)($row['stage_version'] ?? 0) < 1) {
                throw new RuntimeException('activity_membership_audit_row_invalid');
            }
            $cursor = $pk;
            $state = 'unknown';
            $failure = null;
            try {
                $binding = $this->session->binding($uid);
                if(is_array($binding) &&
                    hash_equals((string)$row['togya_user_id'], (string)($binding['togya_user_id'] ?? '')) &&
                    (int)$row['binding_generation'] === (int)($binding['generation'] ?? 0) &&
                    (int)$row['stage_version'] === $activity['stageVersion']) {
                    $state = $this->reader->joinedActive($uid, $activity['id'], $locale,
                        $activity['stageVersion']) ? 'joined' : 'not_joined';
                } else {
                    $state = 'identity_changed';
                }
            } catch(Throwable $error) {
                $state = 'unknown';
                $failure = PintogyaMembershipFailure::classify($error);
            }
            $item = array('participantPk' => $pk, 'state' => $state);
            if($failure !== null) { $item['failure'] = $failure; }
            $items[] = $item;
        }
        $current = $this->thread->publicProjection($tid, $locale);
        if($current['activityPk'] !== $projection['activityPk'] ||
            $current['fid'] !== $projection['fid'] || $current['tid'] !== $projection['tid'] ||
            PintogyaActivityPublish::sourceHash($current['activity']) !==
                PintogyaActivityPublish::sourceHash($activity)) {
            throw new RuntimeException('activity_membership_audit_source_changed');
        }
        return array('activityPk' => (int)$map['activity_pk'], 'items' => $items,
            'nextCursor' => $cursor, 'hasMore' => $hasMore);
    }
}
