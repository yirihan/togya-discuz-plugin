<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityReconcile {
    private $repo;

    public function __construct($repo) { $this->repo = $repo; }

    public function inspect($map) {
        if(!is_array($map) || ($map['state_code'] ?? '') !== 'pending' ||
            (int)($map['tid'] ?? 0) < 1 || (int)($map['owner_uid'] ?? 0) < 1 || (int)($map['fid'] ?? 0) < 1 ||
            !preg_match('/^[0-9a-f]{64}$/D', (string)($map['reservation_digest'] ?? ''))) {
            throw new InvalidArgumentException('activity_reconcile_map_invalid');
        }
        $tid = (int)$map['tid'];
        try {
            $thread = $this->repo->activityThread($tid, false);
            if(!$thread) { return 'thread_missing'; }
            if((int)$thread['fid'] !== (int)$map['fid'] ||
                (int)$thread['authorid'] !== (int)$map['owner_uid'] || (int)$thread['special'] !== 127) {
                return 'thread_mismatch';
            }
            if($this->repo->activityFirstPostMessage($tid) !==
                PintogyaActivityNativePost::messageForDigest($map['reservation_digest'])) {
                return 'first_post_mismatch';
            }
            $order = (int)$thread['displayorder'];
            if($order === -4) { return 'hidden_draft'; }
            if($order === -2) { return 'moderation_pending'; }
            return $order >= 0 ? 'publication_uncertain' : 'thread_unavailable';
        } catch(Throwable $error) {
            return 'inspection_error';
        }
    }

    public function inspectUnboundDraft($thread, $now) {
        if(!is_array($thread) || (int)($thread['tid'] ?? 0) < 1 ||
            (int)($thread['fid'] ?? 0) < 1 || (int)($thread['authorid'] ?? 0) < 1 ||
            !is_int($now) || $now < 1) { throw new InvalidArgumentException('activity_draft_invalid'); }
        if((int)$thread['special'] !== 127 || (int)$thread['displayorder'] !== -4) { return null; }
        try {
            $digest = PintogyaActivityNativePost::digestFromMessage(
                $this->repo->activityFirstPostMessage((int)$thread['tid']));
            if($digest === null) { return null; }
            $map = $this->repo->activityByReservationDigest($digest);
            if(!$map) { return array('status' => 'reservation_missing'); }
            if($map['state_code'] !== 'pending' || $map['tid'] !== null ||
                (int)$map['fid'] !== (int)$thread['fid'] ||
                (int)$map['owner_uid'] !== (int)$thread['authorid']) {
                return array('status' => 'mapping_mismatch', 'map' => $map);
            }
            return array('status' => (int)$map['pending_expires_at'] <= $now ? 'unbound_draft' : 'reservation_pending',
                'map' => $map);
        } catch(Throwable $error) {
            return array('status' => 'inspection_error');
        }
    }
}
