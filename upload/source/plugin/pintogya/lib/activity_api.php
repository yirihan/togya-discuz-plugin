<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/capabilities.php';

final class PintogyaActivityApi {
    public static function publicForumAllowsRoster($row) {
        return is_array($row) && (int)($row['status'] ?? 0) === 1 &&
            ($row['type'] ?? '') === 'forum' && trim((string)($row['viewperm'] ?? '')) === '' &&
            (string)($row['password'] ?? '') === '' && (int)($row['readperm'] ?? 1) === 0 &&
            (int)($row['price'] ?? 1) === 0;
    }

    private $binding;
    private $reader;
    private $publisher;
    private $qrRenderer;

    public function __construct($binding, $reader, $publisher, $qrRenderer) {
        if(!is_callable($qrRenderer)) { throw new InvalidArgumentException('activity_qr_renderer_invalid'); }
        $this->binding = $binding;
        $this->reader = $reader;
        $this->publisher = $publisher;
        $this->qrRenderer = $qrRenderer;
    }

    public function execute($operation, $uid, $fid, $sessionDigest, $locale, $now, $activityId = '') {
        PintogyaCapabilities::assertAvailable('native_composer');
        if(!is_int($uid) || $uid < 1 || !is_int($fid) || $fid < 1 ||
            !is_string($sessionDigest) || !preg_match('/^[0-9a-f]{64}$/D', $sessionDigest) ||
            !is_string($locale) || !preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) ||
            !is_int($now) || $now < 1) { throw new InvalidArgumentException('activity_api_request_invalid'); }

        if($operation === 'start') {
            $challenge = $this->binding->latestChallenge($uid, 0, 'activity-publish', $sessionDigest);
            if($this->freshApproval($challenge, $now)) {
                return array('state' => 'approved');
            }
            if(!$challenge || $challenge['state'] !== 'pending' || (int)$challenge['expiresAt'] < $now) {
                $challenge = $this->binding->startChallenge($uid, 0, 'activity-publish', $sessionDigest, $locale);
            }
            $qr = call_user_func($this->qrRenderer, $challenge['qrUrl']);
            if(!is_string($qr) || $qr === '') { throw new RuntimeException('activity_qr_unavailable'); }
            return array('state' => 'pending', 'qrImage' => $qr, 'expiresAt' => (int)$challenge['expiresAt']);
        }
        if($operation === 'poll') {
            $approved = $this->binding->pollLatest($uid, 0, 'activity-publish', $sessionDigest);
            $challenge = $approved ? $this->binding->latestChallenge($uid, 0, 'activity-publish', $sessionDigest) : null;
            return array('state' => $this->freshApproval($challenge, $now) ? 'approved' : 'pending');
        }
        if($operation === 'list') {
            return array('activities' => $this->reader->ownedPublicActivities($uid, $locale, true));
        }
        if($operation === 'select') {
            $selected = $this->publisher->issue($uid, $fid, $activityId, $sessionDigest, $now);
            return array('token' => $selected['token'], 'expiresAt' => (int)$selected['expiresAt'],
                'activity' => $selected['activity']);
        }
        throw new InvalidArgumentException('activity_api_operation_invalid');
    }

    private function freshApproval($challenge, $now) {
        return is_array($challenge) && ($challenge['state'] ?? '') === 'approved' &&
            (int)($challenge['approvedAt'] ?? 0) >= $now - 300 &&
            (int)($challenge['approvedAt'] ?? 0) <= $now;
    }
}
