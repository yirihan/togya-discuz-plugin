<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaSession {
    private $repo;
    private $api;
    private $tokenKey;

    public function __construct($repo, $api, $tokenKey = null) {
        $this->repo = $repo;
        $this->api = $api;
        $this->tokenKey = $tokenKey === null ? PintogyaCrypto::tokenKeyFromEnvironment() : $tokenKey;
        if(!is_string($this->tokenKey) || strlen($this->tokenKey) !== 32) { throw new RuntimeException('token_key_invalid'); }
    }

    public function connections($uid) {
        return $this->authorizedGet($uid, function($token) { return $this->api->getConnections($token); });
    }

    public function bootstrap($uid, $locale) {
        return $this->authorizedGet($uid, function($token) use ($locale) { return $this->api->getBootstrap($token, $locale); });
    }

    public function bootstrapReadOnly($uid, $locale) {
        return $this->authorizedReadOnly($uid, function($token) use ($locale) { return $this->api->getBootstrap($token, $locale); });
    }

    public function activityDetailReadOnly($uid, $activityId, $locale) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        return $this->authorizedReadOnly($uid, function($token) use ($activityId, $locale) {
            return $this->api->getMemberActivity($token, $activityId, $locale);
        });
    }

    public function activityDetail($uid, $activityId, $locale) {
        return $this->authorizedGet($uid, function($token) use ($activityId, $locale) {
            return $this->api->getMemberActivity($token, $activityId, $locale);
        });
    }

    public function stageFeed($uid, $activityId, $stageId, $stageVersion, $cursor = '') {
        return $this->authorizedGet($uid, function($token) use ($activityId, $stageId, $stageVersion, $cursor) {
            return $this->api->getStageFeed($token, $activityId, $stageId, $stageVersion, $cursor);
        });
    }

    public function stageFeedReadOnly($uid, $activityId, $stageId, $stageVersion, $cursor = '') {
        return $this->authorizedReadOnly($uid, function($token) use ($activityId, $stageId, $stageVersion, $cursor) {
            return $this->api->getStageFeed($token, $activityId, $stageId, $stageVersion, $cursor);
        });
    }

    public function checkinImage($uid, $imageKey) {
        return $this->authorizedGet($uid, function($token) use ($imageKey) {
            return $this->api->getCheckinImage($token, $imageKey);
        });
    }

    public function instantRewardStageFeedReadOnly($uid, $expected, $activityId, $stageId, $version, $cursor) {
        $binding = $this->binding($uid);
        if(!is_array($expected) || (int)$binding['uid'] !== (int)($expected['uid'] ?? 0) ||
            (int)$binding['generation'] !== (int)($expected['generation'] ?? 0) ||
            $binding['togya_user_id'] !== ($expected['togya_user_id'] ?? null)) {
            throw new RuntimeException('instant_binding_changed');
        }
        return $this->authorizedGet($uid, function($token) use($activityId,$stageId,$version,$cursor) {
            return $this->api->getStageFeed($token,$activityId,$stageId,$version,$cursor);
        }, $binding);
    }

    public function checkinImageReadOnly($uid, $imageKey) {
        return $this->authorizedReadOnly($uid, function($token) use ($imageKey) {
            return $this->api->getCheckinImage($token, $imageKey);
        });
    }

    public function activityFinishStory($uid, $activityId) {
        return $this->authorizedGet($uid, function($token) use ($activityId) {
            return $this->api->getActivityFinishStory($token, $activityId);
        });
    }

    public function instantRewardSourceReadOnly($uid, $activityId, $locale) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $binding = $this->binding($uid);
        return $this->authorizedGet($uid, function($token) use($uid,$activityId,$locale,$binding) {
            $detail = $this->api->getMemberActivity($token, $activityId, $locale);
            $this->assertStillBound($uid, $binding);
            if(!is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
                ($detail['isJoined'] ?? null) !== true || !is_array($detail['stageDetails'] ?? null) ||
                !is_int($detail['stageVersion'] ?? null) || $detail['stageVersion'] < 1) {
                throw new RuntimeException('instant_read_source_invalid');
            }
            $anchor = null;
            foreach($detail['stageDetails'] as $stage) {
                if(is_array($stage) && ($stage['canViewFeed'] ?? null) === true) {
                    $anchor = PintogyaCrypto::assertUuid($stage['id'] ?? null);
                }
            }
            if($anchor === null) { throw new RuntimeException('instant_read_permission_unavailable'); }
            $journey = $this->api->getParticipantJourney($token, $activityId, $anchor,
                $detail['stageVersion'], $binding['togya_user_id']);
            $this->assertStillBound($uid, $binding);
            return array('binding'=>$binding, 'detail'=>$detail, 'journey'=>$journey, 'observed_at'=>time());
        }, $binding);
    }

    public function maintainBinding($uid, $expectedGeneration) {
        $binding = $this->binding($uid);
        if((int)$binding['generation'] !== (int)$expectedGeneration) { throw new RuntimeException('binding_changed'); }
        if((int)$binding['updated_at'] > time() - 86400 || (int)$binding['access_expires_at'] > time() + 30) {
            return false;
        }
        $this->refreshAccess($uid, $binding);
        $this->assertStillBound($uid, $binding);
        return true;
    }

    public function binding($uid) {
        $row = $this->repo->activeBindingForUid(intval($uid), false);
        if(!$row || !PintogyaCrypto::validOpaqueId($row['togya_user_id']) || intval($row['generation']) < 1) { throw new RuntimeException('binding_inactive'); }
        return $row;
    }

    private function authorizedReadOnly($uid, $callback) {
        // TOGYA_PLUGIN pintogya_binding_renewal: business reads stay read-only; standard auth rotation is authorized.
        return $this->authorizedGet($uid, $callback);
    }

    private function authorizedGet($uid, $callback, $expected = null) {
        $binding = $this->binding($uid);
        if($expected !== null && ((int)$binding['generation'] !== (int)$expected['generation'] ||
            !hash_equals((string)$binding['togya_user_id'], (string)$expected['togya_user_id']))) {
            throw new RuntimeException('binding_changed');
        }
        $access = $this->decryptToken($binding, 'access');
        if(intval($binding['access_expires_at']) > time() + 30) {
            try { $result = call_user_func($callback, $access); $this->assertStillBound($uid, $binding); return $result; }
            catch(Throwable $error) { if($error->getMessage() !== 'api_unauthorized') { throw $error; } }
        }
        $access = $this->refreshAccess($uid, $binding);
        $result = call_user_func($callback, $access);
        $this->assertStillBound($uid, $binding);
        return $result;
    }

    private function assertStillBound($uid, $observed) {
        $current = $this->binding($uid);
        if((int)$current['generation'] !== (int)$observed['generation'] ||
            !hash_equals((string)$current['togya_user_id'], (string)$observed['togya_user_id'])) {
            throw new RuntimeException('binding_changed');
        }
    }

    private function refreshAccess($uid, $observed) {
        $lock = $this->repo->acquireUidLock($uid);
        try {
            $current = $this->binding($uid);
            if(intval($current['generation']) !== intval($observed['generation']) || !hash_equals((string)$current['togya_user_id'], (string)$observed['togya_user_id'])) { throw new RuntimeException('binding_changed'); }
            if(!hash_equals((string)$current['access_ciphertext'], (string)$observed['access_ciphertext']) && intval($current['access_expires_at']) > time() + 30) {
                return $this->decryptToken($current, 'access');
            }
            $session = $this->api->refreshSession($this->decryptToken($current, 'refresh'));
            $this->validateSession($session, $current['togya_user_id']);
            $now = time();
            $generation = intval($current['generation']);
            $this->repo->updateBinding($uid, array(
                'access_ciphertext' => PintogyaCrypto::seal($session['accessToken'], PintogyaCrypto::tokenAad($uid, $current['togya_user_id'], $generation, 'access'), $this->tokenKey),
                'refresh_ciphertext' => PintogyaCrypto::seal($session['refreshToken'], PintogyaCrypto::tokenAad($uid, $current['togya_user_id'], $generation, 'refresh'), $this->tokenKey),
                'access_expires_at' => $now + intval($session['expiresIn']),
                'updated_at' => $now,
            ), $generation);
            return $session['accessToken'];
        } finally {
            $this->repo->releaseUidLock($lock);
        }
    }

    private function decryptToken($binding, $type) {
        $column = $type.'_ciphertext';
        if(!isset($binding[$column]) || $binding[$column] === '') { throw new RuntimeException('binding_token_unavailable'); }
        return PintogyaCrypto::open($binding[$column], PintogyaCrypto::tokenAad($binding['uid'], $binding['togya_user_id'], $binding['generation'], $type), $this->tokenKey);
    }

    private function validateSession($session, $expectedUserId) {
        if(!is_array($session)) { throw new RuntimeException('refresh_session_invalid'); }
        foreach(array('accessToken', 'expiresIn', 'refreshToken', 'user') as $key) {
            if(!array_key_exists($key, $session)) { throw new RuntimeException('refresh_session_invalid'); }
        }
        if(!is_array($session['user'])) { throw new RuntimeException('refresh_session_invalid'); }
        // Additive response fields may grow; the bound user ID must not change.
        if(!isset($session['user']['id']) || !is_string($session['user']['id']) || !hash_equals((string)$expectedUserId, $session['user']['id'])
            || !is_string($session['accessToken']) || $session['accessToken'] === '' || strlen($session['accessToken']) > 16384
            || !is_string($session['refreshToken']) || strlen($session['refreshToken']) < 32 || strlen($session['refreshToken']) > 512
            || !is_int($session['expiresIn']) || $session['expiresIn'] < 60 || $session['expiresIn'] > 86400) {
            throw new RuntimeException('refresh_session_invalid');
        }
    }
}
