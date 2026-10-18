<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

// Account-only QR confirmation never completes a native task or grants credits.
final class PintogyaAccountConnect {
    private $binding;

    public function __construct($binding) { $this->binding = $binding; }

    public function start($uid, $sessionDigest, $locale) {
        $state = $this->binding->status($uid);
        if($state === 'connected') { return array('state' => 'connected'); }
        if($state === 'remote_revoke_pending') { throw new RuntimeException('remote_revoke_pending'); }
        $challenge = $this->binding->latestChallenge($uid, 0, 'account', $sessionDigest);
        if(!$challenge || $challenge['state'] !== 'pending') {
            $challenge = $this->binding->startChallenge($uid, 0, 'account', $sessionDigest, $locale);
        }
        return array('state' => 'pending', 'challengeId' => $challenge['challengeId'],
            'qrUrl' => $challenge['qrUrl'], 'expiresAt' => (int)$challenge['expiresAt']);
    }

    public function poll($uid, $sessionDigest, $challengeId) {
        PintogyaCrypto::assertUuid($challengeId);
        $challenge = $this->binding->latestChallenge($uid, 0, 'account', $sessionDigest);
        if(!$challenge || !hash_equals($challenge['challengeId'], $challengeId)) {
            throw new RuntimeException('activity_join_approval_expired');
        }
        $approved = $this->binding->pollLatest($uid, 0, 'account', $sessionDigest, true);
        if($approved && $this->binding->status($uid) !== 'connected') {
            throw new RuntimeException('binding_inactive');
        }
        return array('state' => $approved ? 'connected' : 'pending');
    }
}

