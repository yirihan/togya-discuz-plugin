<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

// A local request is not a Togya deletion or an activity review decision.
final class PintogyaActivityPhotoRemoval {
    private $repo;
    private $thread;
    private $access;
    private $store;

    public function __construct($repo, $thread, $access, $store) {
        $this->repo = $repo;
        $this->thread = $thread;
        $this->access = $access;
        $this->store = $store;
    }

    public function request($uid, $tid, $stageId, $checkinId, $cursor, $locale) {
        if(!is_int($uid) || $uid < 1 || !is_int($tid) || $tid < 1 ||
            !is_string($cursor) || ($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor)) ||
            !is_string($locale) || !preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $locale)) {
            throw new InvalidArgumentException('activity_photo_removal_input_invalid');
        }
        $stageId = PintogyaCrypto::assertUuid($stageId);
        $checkinId = PintogyaCrypto::assertUuid($checkinId);
        $scope = $this->access->assertCurrentModerator($uid, $tid);
        $binding = $this->repo->activeBindingForUid($uid);
        if(!$binding || (int)($binding['generation'] ?? 0) < 1) {
            throw new RuntimeException('activity_photo_removal_binding_unavailable');
        }
        // Explicitly bypass display feed cache; never accept a client-supplied URL or image digest.
        $feed = $this->thread->stageFeed($uid, $tid, $locale, $stageId, $cursor, false);
        $found = false;
        foreach(($feed['items'] ?? array()) as $item) {
            if(($item['checkinId'] ?? null) === $checkinId && ($item['mediaId'] ?? null) === $checkinId &&
                ($item['hasMedia'] ?? null) === true) { $found = true; break; }
        }
        if(($feed['stageId'] ?? null) !== $stageId || !$found) {
            throw new RuntimeException('activity_photo_removal_evidence_unavailable');
        }
        $image = $this->thread->stageImage($uid, $tid, $locale, $stageId, $cursor, $checkinId);
        if(!is_string($image['body'] ?? null) || $image['body'] === '' ||
            strlen($image['body']) > 12582912 ||
            !in_array($image['contentType'] ?? null, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            throw new RuntimeException('activity_photo_removal_evidence_unavailable');
        }
        $digest = hash('sha256', "pintogya/photo-removal/primary/v1\0".json_encode(array(
            $scope['activity_pk'], $scope['stage_version'], $stageId, $checkinId,
            $image['contentType'], hash('sha256', $image['body'])), JSON_THROW_ON_ERROR));
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $currentScope = $this->access->assertCurrentModerator($uid, $tid, true);
                $currentBinding = $this->repo->activeBindingForUid($uid, true);
                if($currentScope !== $scope || !$currentBinding ||
                    (int)$currentBinding['generation'] !== (int)$binding['generation'] ||
                    !hash_equals((string)$currentBinding['togya_user_id'], (string)$binding['togya_user_id'])) {
                    throw new RuntimeException('activity_photo_removal_context_changed');
                }
                $row = array('activity_pk' => $scope['activity_pk'], 'stage_id' => $stageId,
                    'stage_version' => $scope['stage_version'], 'checkin_id' => $checkinId,
                    'media_ref' => 'primary', 'evidence_digest' => $digest,
                    'requested_by_uid' => $uid, 'requested_at' => $this->repo->currentUnixTime(),
                    'state_code' => 'pending_provider');
                $saved = $this->store->reserve($row);
                if(!is_array($saved) || (int)($saved['request_pk'] ?? 0) < 1 ||
                    ($saved['state_code'] ?? null) !== 'pending_provider' ||
                    !hash_equals($digest, (string)($saved['evidence_digest'] ?? ''))) {
                    throw new RuntimeException('activity_photo_removal_store_invalid');
                }
                $this->repo->commit();
                return array('requestId' => (string)$saved['request_pk'], 'state' => 'pending_provider');
            } catch(Throwable $error) {
                $this->repo->rollback();
                throw $error;
            }
        } finally { $this->repo->releaseUidLock($lock); }
    }
}

final class PintogyaActivityPhotoRemovalStore {
    public function isInstalled() {
        $table = DB::table('pintogya_photo_removal_request');
        $row = DB::fetch_first("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".
            daddslashes($table)."' LIMIT 1");
        return $row && strtoupper((string)($row['ENGINE'] ?? '')) === 'INNODB';
    }

    public function reserve($row) {
        // The unique evidence key serializes different moderators requesting the same primary image.
        $table = DB::table('pintogya_photo_removal_request');
        $fields = array_keys($row);
        $values = array();
        foreach($row as $value) { $values[] = "'".daddslashes((string)$value)."'"; }
        DB::query('INSERT INTO '.$table.' (`'.implode('`,`', $fields).'`) VALUES ('.implode(',', $values).
            ') ON DUPLICATE KEY UPDATE request_pk=request_pk');
        return DB::fetch_first('SELECT request_pk,state_code,evidence_digest FROM '.$table.
            " WHERE activity_pk=".(int)$row['activity_pk']." AND stage_id='".daddslashes($row['stage_id']).
            "' AND stage_version=".(int)$row['stage_version']." AND checkin_id='".daddslashes($row['checkin_id']).
            "' AND media_ref='primary' AND evidence_digest='".$row['evidence_digest']."' FOR UPDATE");
    }
}
