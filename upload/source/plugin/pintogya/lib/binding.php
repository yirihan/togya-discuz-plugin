<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaBinding {
    private $repo;
    private $api;
    private $tokenKey;

    public function __construct($repo, $api, $tokenKey = null) {
        $this->repo = $repo;
        $this->api = $api;
        $this->tokenKey = $tokenKey === null ? PintogyaCrypto::tokenKeyFromEnvironment() : $tokenKey;
        if(!is_string($this->tokenKey) || strlen($this->tokenKey) !== 32) { throw new RuntimeException('token_key_invalid'); }
    }

    public function startChallenge($uid, $taskId, $taskInstance, $sessionDigest, $locale) {
        $this->assertOwnerInput($uid, $taskId, $taskInstance, $sessionDigest);
        $this->repo->assertCurrentTaskInstance($uid, $taskId, $taskInstance);
        $existing = $this->repo->bindingForUid($uid, false);
        if(strpos($taskInstance, 'account-unlink-g') === 0) {
            if(!$existing || $existing['state_code'] !== 'active' ||
                $taskInstance !== self::unlinkInstance((int)$existing['generation'])) {
                throw new RuntimeException('binding_changed');
            }
        }
        if($existing && $existing['state_code'] === 'revoked' && $existing['refresh_ciphertext'] !== '') {
            throw new RuntimeException('remote_revoke_pending');
        }
        $now = $this->now();
        $verifier = PintogyaCrypto::b64urlEncode(random_bytes(32));
        $remote = $this->api->startBrowserLogin(hash('sha256', $verifier), $locale);
        $parsed = $this->validateStartResponse($remote, $now);
        $deviceId = 'togya-forum-'.PintogyaCrypto::uuid();
        $ciphertext = PintogyaCrypto::seal($verifier, PintogyaCrypto::challengeAad($uid, $taskId, $taskInstance, $parsed['challengeId']), $this->tokenKey);

        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $current = $this->repo->bindingForUid($uid, true);
                if(strpos($taskInstance, 'account-unlink-g') === 0 &&
                    (!$current || $current['state_code'] !== 'active' ||
                    $taskInstance !== self::unlinkInstance((int)$current['generation']))) {
                    throw new RuntimeException('binding_changed');
                }
                if(($existing === null) !== ($current === null) || ($existing &&
                    ($existing['state_code'] !== $current['state_code'] ||
                    (int)$existing['generation'] !== (int)$current['generation'] ||
                    !hash_equals((string)$existing['togya_user_id'], (string)$current['togya_user_id'])))) {
                    throw new RuntimeException('binding_changed');
                }
                $this->repo->supersedePendingChallenges($uid, $taskId, $taskInstance, $now);
                $this->repo->insertChallenge(array(
                    'uid' => intval($uid), 'task_id' => intval($taskId), 'task_instance' => $taskInstance,
                    'session_digest' => $sessionDigest, 'challenge_id' => $parsed['challengeId'],
                    'verifier_ciphertext' => $ciphertext, 'device_id' => $deviceId, 'qr_url' => $parsed['qrUrl'],
                    'state_code' => 'pending', 'created_at' => $now, 'expires_at' => $parsed['expiresAt'],
                    'approved_at' => 0, 'updated_at' => $now,
                ));
                $this->repo->commit();
            } catch(Throwable $e) { $this->repo->rollback(); throw $e; }
        } finally { $this->repo->releaseUidLock($lock); }
        return array('challengeId' => $parsed['challengeId'], 'qrUrl' => $parsed['qrUrl'], 'expiresAt' => $parsed['expiresAt'], 'pollIntervalSeconds' => $parsed['pollIntervalSeconds']);
    }

    public function latestChallenge($uid, $taskId, $taskInstance, $sessionDigest) {
        $this->assertOwnerInput($uid, $taskId, $taskInstance, $sessionDigest);
        $this->repo->assertCurrentTaskInstance($uid, $taskId, $taskInstance);
        $row = $this->repo->latestChallenge($uid, $taskId, $taskInstance, false);
        if(!$row || !hash_equals((string)$row['session_digest'], $sessionDigest)) { return null; }
        if($row['state_code'] === 'pending' && intval($row['expires_at']) < $this->now()) { return null; }
        return array('challengeId' => $row['challenge_id'], 'qrUrl' => $row['qr_url'], 'state' => $row['state_code'], 'expiresAt' => intval($row['expires_at']), 'approvedAt' => intval($row['approved_at']));
    }

    public function pollLatest($uid, $taskId, $taskInstance, $sessionDigest, $reportErrors = false) {
        $this->assertOwnerInput($uid, $taskId, $taskInstance, $sessionDigest);
        if(strpos($taskInstance, 'account-unlink-g') === 0) { throw new RuntimeException('unlink_proof_required'); }
        $this->repo->assertCurrentTaskInstance($uid, $taskId, $taskInstance);
        $row = $this->repo->latestChallenge($uid, $taskId, $taskInstance, false);
        if(!$row || !hash_equals((string)$row['session_digest'], $sessionDigest)) {
            if($reportErrors) { throw new RuntimeException('activity_join_approval_changed'); }
            return false;
        }
        if($row['state_code'] === 'approved') { return true; }
        if($row['state_code'] !== 'pending') {
            if($reportErrors) { throw new RuntimeException('activity_join_approval_expired'); }
            return false;
        }
        $now = $this->now();
        if(intval($row['expires_at']) < $now) {
            try { $this->repo->updateChallenge($row['challenge_id'], array('state_code' => 'expired', 'verifier_ciphertext' => '', 'updated_at' => $now), 'pending'); } catch(Throwable $ignored) {}
            if($reportErrors) { throw new RuntimeException('activity_join_approval_expired'); }
            return false;
        }
        try {
            $verifier = PintogyaCrypto::open($row['verifier_ciphertext'], PintogyaCrypto::challengeAad($uid, $taskId, $taskInstance, $row['challenge_id']), $this->tokenKey);
            $remote = $this->api->pollBrowserLogin($row['challenge_id'], $verifier, $row['device_id']);
            $session = $this->validatePollResponse($remote);
            if($session === null) { return false; }
        } catch(Throwable $e) {
            if($reportErrors) { throw new RuntimeException('activity_join_verification_unavailable'); }
            return false;
        }

        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $latest = $this->repo->latestChallenge($uid, $taskId, $taskInstance, true);
                if(!$latest || $latest['challenge_id'] !== $row['challenge_id'] || $latest['state_code'] !== 'pending' || !hash_equals((string)$latest['session_digest'], $sessionDigest) || intval($latest['expires_at']) < $now) {
                    throw new RuntimeException('challenge_not_current');
                }
                $this->storeApprovedSession($uid, $session, $now);
                $this->repo->updateChallenge($row['challenge_id'], array(
                    'state_code' => 'approved', 'verifier_ciphertext' => '', 'approved_at' => $now, 'updated_at' => $now,
                ), 'pending');
                $this->repo->commit();
            } catch(Throwable $e) {
                $this->repo->rollback();
                if($reportErrors) {
                    $code = in_array($e->getMessage(), array('binding_identity_mismatch', 'togya_identity_in_use'), true)
                        ? 'activity_join_identity_mismatch' : 'activity_join_verification_unavailable';
                    throw new RuntimeException($code);
                }
                return false;
            }
        } finally { $this->repo->releaseUidLock($lock); }
        return true;
    }

    public function startUnlinkChallenge($uid, $sessionDigest, $locale) {
        $instance = $this->activeUnlinkInstance($uid);
        $this->assertOwnerInput($uid, 0, $instance, $sessionDigest);
        $latest = $this->repo->latestChallenge($uid, 0, $instance, false);
        if($latest && $latest['state_code'] === 'approved' && $latest['verifier_ciphertext'] !== '') {
            $this->finishUnlinkProof($uid, $instance, $latest['session_digest'], $latest);
            $latest = $this->repo->latestChallenge($uid, 0, $instance, false);
        }
        if($latest && hash_equals((string)$latest['session_digest'], $sessionDigest)) {
            if(($latest['state_code'] === 'pending' && (int)$latest['expires_at'] >= $this->now()) ||
                ($latest['state_code'] === 'approved' && (int)$latest['approved_at'] + 300 >= $this->now())) {
                return array('challengeId' => $latest['challenge_id'], 'qrUrl' => $latest['qr_url'],
                    'expiresAt' => (int)$latest['expires_at'], 'pollIntervalSeconds' => 3,
                    'state' => $latest['state_code']);
            }
        }
        $started = $this->startChallenge($uid, 0, $instance, $sessionDigest, $locale);
        return $started + array('state' => 'pending');
    }

    public function pollUnlinkChallenge($uid, $sessionDigest, $challengeId) {
        PintogyaCrypto::assertUuid($challengeId);
        $instance = $this->activeUnlinkInstance($uid);
        $this->assertOwnerInput($uid, 0, $instance, $sessionDigest);
        $row = $this->repo->latestChallenge($uid, 0, $instance, false);
        if(!$row || $row['challenge_id'] !== $challengeId ||
            !hash_equals((string)$row['session_digest'], $sessionDigest)) { return false; }
        if($row['state_code'] === 'approved') {
            if($row['verifier_ciphertext'] !== '') { $this->finishUnlinkProof($uid, $instance, $sessionDigest, $row); }
            return (int)$row['approved_at'] + 300 >= $this->now();
        }
        if($row['state_code'] !== 'pending') { return false; }
        $now = $this->now();
        if((int)$row['expires_at'] < $now) { return false; }
        $verifier = PintogyaCrypto::open($row['verifier_ciphertext'],
            PintogyaCrypto::challengeAad($uid, 0, $instance, $challengeId), $this->tokenKey);
        $session = $this->validatePollResponse($this->api->pollBrowserLogin($challengeId, $verifier, $row['device_id']));
        if($session === null) { return false; }
        $bound = $this->repo->activeBindingForUid($uid, false);
        if(!$bound || !hash_equals((string)$bound['togya_user_id'], (string)$session['user']['id'])) {
            try { $this->api->logoutSession($session['refreshToken']); } catch(Throwable $ignored) {}
            try { $this->denyUnlinkChallenge($uid, $instance, $sessionDigest, $challengeId); } catch(Throwable $ignored) {}
            throw new RuntimeException('binding_identity_mismatch');
        }
        try {
            $ciphertext = PintogyaCrypto::seal($session['refreshToken'],
                $this->unlinkProofAad($uid, $instance, $challengeId), $this->tokenKey);
            $lock = $this->repo->acquireUidLock($uid);
        } catch(Throwable $error) {
            try { $this->api->logoutSession($session['refreshToken']); } catch(Throwable $ignored) {}
            throw $error;
        }
        try {
            $this->repo->begin();
            try {
                $current = $this->repo->bindingForUid($uid, true);
                $latest = $this->repo->latestChallenge($uid, 0, $instance, true);
                if(!$current || $current['state_code'] !== 'active' ||
                    $instance !== self::unlinkInstance((int)$current['generation']) ||
                    !hash_equals((string)$current['togya_user_id'], (string)$session['user']['id']) ||
                    !$latest || $latest['challenge_id'] !== $challengeId || $latest['state_code'] !== 'pending' ||
                    !hash_equals((string)$latest['session_digest'], $sessionDigest) || (int)$latest['expires_at'] < $now) {
                    throw new RuntimeException('unlink_challenge_changed');
                }
                $this->repo->updateChallenge($challengeId, array('state_code' => 'approved',
                    'verifier_ciphertext' => $ciphertext, 'approved_at' => $now, 'updated_at' => $now), 'pending');
                $this->repo->commit();
            } catch(Throwable $error) {
                $this->repo->rollback();
                try { $this->api->logoutSession($session['refreshToken']); } catch(Throwable $ignored) {}
                throw $error;
            }
        } finally { $this->repo->releaseUidLock($lock); }
        $approved = $this->repo->latestChallenge($uid, 0, $instance, false);
        if(!$approved || $approved['challenge_id'] !== $challengeId) {
            throw new RuntimeException('unlink_challenge_changed');
        }
        $this->finishUnlinkProof($uid, $instance, $sessionDigest, $approved);
        return true;
    }

    public function unlinkAfterProof($uid, $sessionDigest, $challengeId) {
        PintogyaCrypto::assertUuid($challengeId);
        return $this->unlinkInternal($uid, $sessionDigest, $challengeId);
    }

    public function unlink($uid) { return $this->unlinkInternal($uid, '', ''); }

    private function unlinkInternal($uid, $sessionDigest, $challengeId) {
        if(intval($uid) < 1) { throw new InvalidArgumentException('uid_invalid'); }
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $row = $this->repo->bindingForUid($uid, true);
                if(!$row) { $this->repo->commit(); return 'disconnected'; }
                if($row['state_code'] === 'active') {
                    $proof = $this->repo->latestChallenge($uid, 0,
                        self::unlinkInstance((int)$row['generation']), true);
                    if($challengeId !== '') {
                        $this->assertOwnerInput($uid, 0, self::unlinkInstance((int)$row['generation']), $sessionDigest);
                        if(!$proof || $proof['challenge_id'] !== $challengeId ||
                            !hash_equals((string)$proof['session_digest'], $sessionDigest) ||
                            $proof['state_code'] !== 'approved' || $proof['verifier_ciphertext'] !== '' ||
                            (int)$proof['approved_at'] + 300 < $this->now()) {
                            throw new RuntimeException('unlink_proof_required');
                        }
                    } elseif($proof && $proof['state_code'] === 'approved' && $proof['verifier_ciphertext'] !== '') {
                        throw new RuntimeException('unlink_proof_revoke_pending');
                    }
                    if(!is_string($row['refresh_ciphertext'] ?? null) || $row['refresh_ciphertext'] === '') {
                        throw new RuntimeException('binding_token_unavailable');
                    }
                    $now = $this->now();
                    $this->repo->supersedeAllChallengesForUid($uid, $now);
                    $this->repo->deactivateActivityForUidIfInstalled($uid, $now);
                    $this->repo->revokeBinding($uid, $now);
                } elseif($row['state_code'] !== 'revoked') {
                    throw new RuntimeException('binding_revoke_state_invalid');
                }
                $this->repo->commit();
            } catch(Throwable $error) { $this->repo->rollback(); throw $error; }
            if($row['refresh_ciphertext'] === '') { return 'disconnected'; }
            $refresh = PintogyaCrypto::open($row['refresh_ciphertext'], PintogyaCrypto::tokenAad(
                $uid, $row['togya_user_id'], $row['generation'], 'refresh'), $this->tokenKey);
            try {
                if($this->api->logoutSession($refresh) !== true) { throw new RuntimeException('api_logout_response_invalid'); }
            }
            catch(Throwable $error) { throw new RuntimeException('remote_revoke_pending'); }
            $this->repo->begin();
            try {
                $current = $this->repo->bindingForUid($uid, true);
                if(!$current || $current['state_code'] !== 'revoked' ||
                    (int)$current['generation'] !== (int)$row['generation'] ||
                    !hash_equals((string)$current['togya_user_id'], (string)$row['togya_user_id'])) {
                    throw new RuntimeException('binding_revoke_state_changed');
                }
                $this->repo->finishBindingRevoke($uid, (int)$row['generation'], $this->now());
                $this->repo->commit();
            } catch(Throwable $error) { $this->repo->rollback(); throw $error; }
            return 'disconnected';
        } finally { $this->repo->releaseUidLock($lock); }
    }

    private function activeUnlinkInstance($uid) {
        if((int)$uid < 1) { throw new InvalidArgumentException('uid_invalid'); }
        $row = $this->repo->activeBindingForUid($uid, false);
        if(!$row) { throw new RuntimeException('binding_inactive'); }
        return self::unlinkInstance((int)$row['generation']);
    }

    private static function unlinkInstance($generation) {
        if($generation < 1 || $generation > 4294967295) { throw new RuntimeException('binding_generation_invalid'); }
        return 'account-unlink-g'.$generation;
    }

    private function unlinkProofAad($uid, $instance, $challengeId) {
        return PintogyaCrypto::challengeAad($uid, 0, $instance, $challengeId)."\0unlink-proof-refresh";
    }

    private function denyUnlinkChallenge($uid, $instance, $sessionDigest, $challengeId) {
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $latest = $this->repo->latestChallenge($uid, 0, $instance, true);
                if($latest && $latest['challenge_id'] === $challengeId &&
                    $latest['state_code'] === 'pending' &&
                    hash_equals((string)$latest['session_digest'], $sessionDigest)) {
                    $this->repo->updateChallenge($challengeId, array('state_code' => 'denied',
                        'verifier_ciphertext' => '', 'updated_at' => $this->now()), 'pending');
                }
                $this->repo->commit();
            } catch(Throwable $error) { $this->repo->rollback(); throw $error; }
        } finally { $this->repo->releaseUidLock($lock); }
    }

    private function finishUnlinkProof($uid, $instance, $sessionDigest, $row) {
        if($row['state_code'] !== 'approved' || $row['verifier_ciphertext'] === '') { return; }
        $refresh = PintogyaCrypto::open($row['verifier_ciphertext'],
            $this->unlinkProofAad($uid, $instance, $row['challenge_id']), $this->tokenKey);
        try {
            if($this->api->logoutSession($refresh) !== true) { throw new RuntimeException('api_logout_response_invalid'); }
        } catch(Throwable $error) { throw new RuntimeException('unlink_proof_revoke_pending'); }
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $this->repo->begin();
            try {
                $current = $this->repo->bindingForUid($uid, true);
                $latest = $this->repo->latestChallenge($uid, 0, $instance, true);
                if(!$current || $current['state_code'] !== 'active' ||
                    $instance !== self::unlinkInstance((int)$current['generation']) ||
                    !$latest || $latest['challenge_id'] !== $row['challenge_id'] ||
                    $latest['state_code'] !== 'approved' ||
                    !hash_equals((string)$latest['session_digest'], $sessionDigest)) {
                    throw new RuntimeException('unlink_challenge_changed');
                }
                if($latest['verifier_ciphertext'] !== '') {
                    $this->repo->updateChallenge($row['challenge_id'], array(
                        'verifier_ciphertext' => '', 'updated_at' => $this->now()), 'approved');
                }
                $this->repo->commit();
            } catch(Throwable $error) { $this->repo->rollback(); throw $error; }
        } finally { $this->repo->releaseUidLock($lock); }
    }

    public function status($uid) {
        if(intval($uid) < 1) { throw new InvalidArgumentException('uid_invalid'); }
        $row = $this->repo->bindingForUid($uid, false);
        if(!$row) { return 'disconnected'; }
        if($row['state_code'] === 'active') { return 'connected'; }
        if($row['state_code'] === 'revoked') {
            return $row['refresh_ciphertext'] === '' ? 'disconnected' : 'remote_revoke_pending';
        }
        throw new RuntimeException('binding_state_invalid');
    }

    public function activeBinding($uid) { return $this->repo->activeBindingForUid(intval($uid), false); }

    private function storeApprovedSession($uid, $session, $now) {
        $togyaUserId = $session['user']['id'];
        $existing = $this->repo->bindingForUid($uid, true);
        $owner = $this->repo->bindingForTogyaUser($togyaUserId, true);
        if($owner && intval($owner['uid']) !== intval($uid)) { throw new RuntimeException('togya_identity_in_use'); }
        if($existing && !hash_equals((string)$existing['togya_user_id'], $togyaUserId)) { throw new RuntimeException('binding_identity_mismatch'); }
        if($existing && $existing['state_code'] === 'revoked' && $existing['refresh_ciphertext'] !== '') { throw new RuntimeException('remote_revoke_pending'); }
        $generation = $existing && $existing['state_code'] === 'active' ? intval($existing['generation']) : ($existing ? intval($existing['generation']) + 1 : 1);
        $accessCiphertext = PintogyaCrypto::seal($session['accessToken'], PintogyaCrypto::tokenAad($uid, $togyaUserId, $generation, 'access'), $this->tokenKey);
        $refreshCiphertext = PintogyaCrypto::seal($session['refreshToken'], PintogyaCrypto::tokenAad($uid, $togyaUserId, $generation, 'refresh'), $this->tokenKey);
        $values = array(
            'togya_user_id' => $togyaUserId, 'generation' => $generation, 'state_code' => 'active',
            'access_ciphertext' => $accessCiphertext, 'refresh_ciphertext' => $refreshCiphertext,
            'access_expires_at' => $now + intval($session['expiresIn']), 'updated_at' => $now, 'revoked_at' => 0,
        );
        if($existing) { $this->repo->updateBinding($uid, $values); }
        else { $this->repo->createBinding(array('uid' => intval($uid), 'created_at' => $now) + $values); }
    }

    private function validateStartResponse($body, $now) {
        self::assertExactKeys($body, array('challengeId','createdAt','expiresAt','pollIntervalSeconds','qrUrl'));
        $challengeId = PintogyaCrypto::assertUuid($body['challengeId']);
        $createdAt = self::parseTimestamp($body['createdAt']); $expiresAt = self::parseTimestamp($body['expiresAt']);
        if($createdAt > $now + 30 || $createdAt < $now - 300 || $expiresAt <= $now || $expiresAt > $now + 900 || !is_int($body['pollIntervalSeconds']) || $body['pollIntervalSeconds'] < 1 || $body['pollIntervalSeconds'] > 30) { throw new RuntimeException('qr_start_response_invalid'); }
        self::assertQrUrl($body['qrUrl'], $challengeId);
        return array('challengeId' => $challengeId, 'qrUrl' => $body['qrUrl'], 'expiresAt' => $expiresAt, 'pollIntervalSeconds' => $body['pollIntervalSeconds']);
    }

    private function validatePollResponse($body) {
        if(!is_array($body) || !isset($body['status']) || !in_array($body['status'], array('pending','approved'), true)) { throw new RuntimeException('qr_poll_response_invalid'); }
        if($body['status'] === 'pending') {
            // pintogya_qr_pending_null: the API serializes an absent pending session as null.
            if(array_key_exists('session', $body) && $body['session'] === null) { unset($body['session']); }
            self::assertExactKeys($body, array('status'));
            return null;
        }
        self::assertExactKeys($body, array('session','status'));
        $session = $body['session']; self::assertExactKeys($session, array('accessToken','expiresIn','refreshToken','user'));
        // pintogya_qr_optional_native_language: metadata is not part of forum identity.
        $user = $session['user'];
        if(is_array($user) && array_key_exists('nativeAudioLanguagePreference', $user)) {
            if($user['nativeAudioLanguagePreference'] !== null && !is_string($user['nativeAudioLanguagePreference'])) {
                throw new RuntimeException('qr_poll_response_invalid');
            }
            unset($user['nativeAudioLanguagePreference']);
        }
        self::assertExactKeys($user, array('avatarUrl','displayName','email','id'));
        if(!PintogyaCrypto::validOpaqueId($session['user']['id']) || !is_string($session['accessToken']) || $session['accessToken'] === '' || strlen($session['accessToken']) > 16384 || !is_string($session['refreshToken']) || strlen($session['refreshToken']) < 32 || strlen($session['refreshToken']) > 512 || !is_int($session['expiresIn']) || $session['expiresIn'] < 60 || $session['expiresIn'] > 86400) {
            throw new RuntimeException('qr_session_invalid');
        }
        return $session;
    }

    private function assertOwnerInput($uid, $taskId, $taskInstance, $sessionDigest) {
        if(intval($uid) < 1 || intval($taskId) < 0 || !is_string($taskInstance) || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $taskInstance) || !is_string($sessionDigest) || !preg_match('/^[0-9a-f]{64}$/D', $sessionDigest)) {
            throw new InvalidArgumentException('challenge_owner_invalid');
        }
    }

    private static function assertQrUrl($url, $challengeId) {
        if(!is_string($url) || strlen($url) > 512 || $url !== trim($url)) { throw new RuntimeException('qr_url_invalid'); }
        $parts = parse_url($url);
        if(!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'togya.com' || ($parts['path'] ?? '') !== '/auth/browser' || isset($parts['fragment']) || !isset($parts['query'])) { throw new RuntimeException('qr_url_invalid'); }
        $pairs = explode('&', $parts['query']); $query = array();
        foreach($pairs as $pair) {
            if(substr_count($pair, '=') !== 1) { throw new RuntimeException('qr_url_invalid'); }
            list($key, $value) = explode('=', $pair, 2); $key = rawurldecode($key); $value = rawurldecode($value);
            if(isset($query[$key])) { throw new RuntimeException('qr_url_invalid'); } $query[$key] = $value;
        }
        ksort($query, SORT_STRING);
        if(array_keys($query) !== array('challenge','nonce') || !hash_equals($challengeId, $query['challenge'])) { throw new RuntimeException('qr_url_invalid'); }
        PintogyaCrypto::b64urlDecode($query['nonce'], 32);
    }

    private static function assertExactKeys($value, $expected) {
        if(!is_array($value)) { throw new RuntimeException('closed_object_invalid'); }
        $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if($actual !== $expected) { throw new RuntimeException('closed_object_invalid'); }
    }
    private static function parseTimestamp($value) {
        if(!is_string($value) || ($parsed = strtotime($value)) === false) { throw new RuntimeException('timestamp_invalid'); }
        return intval($parsed);
    }
    private function now() { return defined('TIMESTAMP') ? intval(TIMESTAMP) : time(); }
}
