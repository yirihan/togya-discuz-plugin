<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once dirname(__FILE__).'/site_profile.php';
require_once __DIR__.'/refresh_budget.php';

final class PintogyaApiClient {
    const MAX_RESPONSE_BYTES = 2097152;
    const MAX_IMAGE_BYTES = 12582912;
    private $baseUrl;
    private $sender;
    private $deadline;
    private $refreshBudget;
    private static $sourceReads=array('public'=>0,'member'=>0,'refresh'=>0,'public_image'=>0,'member_image'=>0,'other'=>0);

    public static function sourceReads() { return self::$sourceReads; }
    private static function assertDisplayTransportAllowed() {
        // A display path must never silently fall back to a live client, even on cold/error paths.
        $display=defined('PINTOGYA_DISPLAY_REQUEST') && PINTOGYA_DISPLAY_REQUEST===true;
        if(PHP_SAPI!=='cli') {
            $display=$display || (defined('CURMODULE') && CURMODULE==='viewthread') ||
                (($_GET['mod'] ?? null)==='viewthread') ||
                preg_match('~^/thread-[0-9]+-~D',(string)($_SERVER['REQUEST_URI'] ?? ''))===1;
        }
        if($display) { throw new RuntimeException('activity_display_transport_forbidden'); }
    }
    private static function countSource($method,$path,$token,$image=false) {
        $key=$image ? ($token===''?'public_image':'member_image') :
            ($path==='/v1/auth/refresh'?'refresh':($method==='GET'?($token===''?'public':'member'):'other'));
        self::$sourceReads[$key]++;
    }

    public function __construct($baseUrl = 'https://api.togya.com', $sender = null, $deadline = null, $refreshBudget = null) {
        if($deadline !== null && (!is_float($deadline) || !is_finite($deadline) || $deadline <= 0)) {
            throw new InvalidArgumentException('api_deadline_invalid');
        }
        $this->deadline = $deadline;
        $this->baseUrl = rtrim((string)$baseUrl, '/');
        $this->sender = $sender;
        if($refreshBudget !== null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true &&
            is_callable($sender) && is_callable($refreshBudget))) {
            throw new RuntimeException('api_refresh_budget_injection_forbidden');
        }
        $this->refreshBudget = $refreshBudget;
        if($sender !== null && !(defined('PINTOGYA_STATIC_TEST') && is_callable($sender))) {
            throw new RuntimeException('api_sender_forbidden');
        }
        if($this->baseUrl !== 'https://api.togya.com' && !(defined('PINTOGYA_STATIC_TEST') && is_callable($sender))) {
            throw new RuntimeException('api_origin_invalid');
        }
    }

    public function startBrowserLogin($verifierHash, $locale) {
        if(!is_string($verifierHash) || !preg_match('/^[0-9a-f]{64}$/D', $verifierHash)) { throw new InvalidArgumentException('verifier_hash_invalid'); }
        if(!in_array($locale, array('en-US','zh-Hant','es'), true)) { $locale = 'en-US'; }
        return $this->request('POST', '/v1/auth/browser/qr/start', array(
            'verifierHash' => $verifierHash,
            'browser' => pintogya_site_profile()['request_name'],
            'operatingSystem' => 'Web',
            'deviceName' => pintogya_site_profile()['request_name'],
            'locale' => $locale,
            'requestKind' => 'browser_login',
        ));
    }

    public function pollBrowserLogin($challengeId, $verifier, $deviceId) {
        PintogyaCrypto::assertUuid($challengeId);
        PintogyaCrypto::b64urlDecode($verifier, 32);
        if(!PintogyaCrypto::validOpaqueId($deviceId)) { throw new InvalidArgumentException('device_id_invalid'); }
        return $this->request('POST', '/v1/auth/browser/qr/'.$challengeId.'/poll', array(
            'verifier' => $verifier,
            'deviceId' => $deviceId,
            'deviceName' => pintogya_site_profile()['request_name'],
        ));
    }

    public function refreshSession($refreshToken) {
        if(!is_string($refreshToken) || strlen($refreshToken) < 32 || strlen($refreshToken) > 512) { throw new InvalidArgumentException('refresh_token_invalid'); }
        return $this->request('POST', '/v1/auth/refresh', array('refreshToken' => $refreshToken));
    }

    public function logoutSession($refreshToken) {
        if(!is_string($refreshToken) || strlen($refreshToken) < 32 || strlen($refreshToken) > 512) { throw new InvalidArgumentException('refresh_token_invalid'); }
        return $this->request('POST', '/v1/auth/logout', array('refreshToken' => $refreshToken), '', true);
    }

    public function getConnections($accessToken) {
        return $this->request('GET', '/v1/me/stage-share-connections', null, $accessToken);
    }

    public function getBootstrap($accessToken, $locale = 'en-US') {
        if(!in_array($locale, array('en-US','zh-Hant','es'), true)) { $locale = 'en-US'; }
        return $this->request('GET', '/v1/app/bootstrap?locale='.rawurlencode($locale), null, $accessToken);
    }

    public function getPublicActivity($activityId, $locale = 'en-US') {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!in_array($locale, array('en-US','zh-Hant','es'), true)) { $locale = 'en-US'; }
        return $this->request('GET', '/v1/activities/'.$activityId.'?locale='.rawurlencode($locale));
    }

    public function getMemberActivity($accessToken, $activityId, $locale = 'en-US') {
        if(!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 16384) {
            throw new InvalidArgumentException('access_token_invalid');
        }
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!in_array($locale, array('en-US','zh-Hant','es'), true)) { $locale = 'en-US'; }
        return $this->request('GET', '/v1/activities/'.$activityId.'?locale='.rawurlencode($locale), null, $accessToken);
    }

    public function getStageFeed($accessToken, $activityId, $stageId, $stageVersion, $cursor = null) {
        if(!PintogyaCrypto::validOpaqueId($activityId) || !PintogyaCrypto::validOpaqueId($stageId) || intval($stageVersion) < 1 ||
            ($cursor !== null && (!is_string($cursor) || ($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor))))) {
            throw new InvalidArgumentException('stage_feed_input');
        }
        $path = '/v1/activities/'.rawurlencode($activityId).'/stages/'.rawurlencode($stageId)
            .'/feed?stageVersion='.intval($stageVersion);
        if($cursor !== null) { $path .= '&view=latest&limit=20'; }
        if($cursor !== null && $cursor !== '') { $path .= '&cursor='.$cursor; }
        return $this->request('GET', $path, null, $accessToken);
    }

    public function getPublicStageFeed($activityId, $stageId, $stageVersion, $cursor = '') {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $stageId = PintogyaCrypto::assertUuid($stageId);
        return $this->getStageFeed('', $activityId, $stageId, $stageVersion, $cursor);
    }

    public function getPublicActivityComments($activityId, $cursor = '') {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_string($cursor) || ($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor))) {
            throw new InvalidArgumentException('activity_comment_cursor_invalid');
        }
        return $this->request('GET', '/v1/activities/'.$activityId.'/comments?limit=20'
            .($cursor === '' ? '' : '&cursor='.$cursor));
    }

    public function getCheckinImage($accessToken, $imageKey) {
        if(!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 16384) {
            throw new InvalidArgumentException('checkin_image_input_invalid');
        }
        return $this->readCheckinImage($imageKey, $accessToken);
    }

    public function getPublicCheckinImage($imageKey) {
        return $this->readCheckinImage($imageKey, '');
    }

    private function readCheckinImage($imageKey, $accessToken) {
        self::assertDisplayTransportAllowed();
        if(!is_string($imageKey) ||
            !preg_match('~^checkins/([0-9a-f-]{36})/([0-9a-f]{32}\.(?:jpg|png|webp))$~D', $imageKey, $match)) {
            throw new InvalidArgumentException('checkin_image_input_invalid');
        }
        PintogyaCrypto::assertUuid($match[1]);
        $path = '/v1/media/checkins/'.$match[1].'/'.$match[2];
        $headers = array('Accept' => 'image/jpeg, image/png, image/webp');
        if($accessToken !== '') { $headers['Authorization'] = 'Bearer '.$accessToken; }
        if(is_callable($this->sender)) {
            self::countSource('GET',$path,$accessToken,true);
            $response = call_user_func($this->sender, 'GET', $this->baseUrl.$path, $headers, '');
        } else {
            if(!function_exists('curl_init')) { throw new RuntimeException('api_transport_unavailable'); }
            $body = '';
            $ch = curl_init($this->baseUrl.$path);
            $flat = array(); foreach($headers as $name=>$value) { $flat[] = $name.': '.$value; }
            curl_setopt_array($ch, array(
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $flat,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_TIMEOUT_MS => 10000,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function($handle, $chunk) use (&$body) {
                    if(strlen($body) + strlen($chunk) > self::MAX_IMAGE_BYTES) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
            ));
            self::countSource('GET',$path,$accessToken,true);
            $ok = curl_exec($ch);
            $response = array('code' => intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE)),
                'contentType' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => $body);
            $error = curl_error($ch);
            unset($ch);
            if($ok === false || $error !== '') { throw new RuntimeException('api_transport_failed'); }
        }
        if(!is_array($response) || !isset($response['code'], $response['contentType'], $response['body']) ||
            !is_string($response['contentType']) || !is_string($response['body'])) {
            throw new RuntimeException('api_image_response_invalid');
        }
        $code = intval($response['code']);
        if($code === 401) { throw new RuntimeException('api_unauthorized'); }
        if($code !== 200) { throw new RuntimeException('api_image_unavailable'); }
        $body = $response['body'];
        $types = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
        $extension = substr(strrchr($match[2], '.'), 1);
        $type = $types[$extension];
        if($response['contentType'] !== $type || $body === '' || strlen($body) > self::MAX_IMAGE_BYTES ||
            ($extension === 'jpg' && substr($body, 0, 3) !== "\xFF\xD8\xFF") ||
            ($extension === 'png' && substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") ||
            ($extension === 'webp' && (substr($body, 0, 4) !== 'RIFF' || substr($body, 8, 4) !== 'WEBP'))) {
            throw new RuntimeException('api_image_response_invalid');
        }
        return array('contentType' => $type, 'body' => $body);
    }

    public function getActivityFinishStory($accessToken, $activityId) {
        if(!PintogyaCrypto::validOpaqueId($activityId)) { throw new InvalidArgumentException('activity_finish_story_input'); }
        return $this->request('GET', '/v1/activities/'.rawurlencode($activityId).'/finish-story?limit=1', null, $accessToken);
    }

    public function getParticipantJourney($accessToken, $activityId, $stageId, $stageVersion, $participantId) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $stageId = PintogyaCrypto::assertUuid($stageId);
        $participantId = PintogyaCrypto::assertUuid($participantId);
        if(!is_int($stageVersion) || $stageVersion < 1 || $stageVersion > 2147483647) {
            throw new InvalidArgumentException('participant_journey_input_invalid');
        }
        return $this->request('GET', '/v1/activities/'.$activityId.'/stages/'.$stageId
            .'/participants/'.$participantId.'/journey?stageVersion='.$stageVersion.'&limit=100', null, $accessToken);
    }

    private function request($method, $path, $payload = null, $accessToken = '', $expectNoContent = false) {
        self::assertDisplayTransportAllowed();
        $this->assertAllowedRequest($method, $path, $payload, $accessToken);
        $timeoutMs = $this->requestTimeoutMs();
        $url = $this->baseUrl.$path;
        $headers = array('Accept' => 'application/json');
        $body = '';
        if($payload !== null) {
            $body = PintogyaCrypto::canonicalJson($payload);
            $headers['Content-Type'] = 'application/json';
        }
        if($accessToken !== '') { $headers['Authorization'] = 'Bearer '.$accessToken; }
        if(is_callable($this->sender)) {
            if($path === '/v1/auth/refresh' && $this->refreshBudget !== null) { call_user_func($this->refreshBudget); }
            self::countSource($method,$path,$accessToken);
            return $this->validateResponse(call_user_func($this->sender, $method, $url, $headers, $body), $expectNoContent);
        }
        if(!function_exists('curl_init')) { throw new RuntimeException('api_transport_unavailable'); }
        if($path === '/v1/auth/refresh') { PintogyaRefreshBudget::reserve(); }
        $flat = array(); foreach($headers as $name => $value) { $flat[] = $name.': '.$value; }
        $responseBody = '';
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function($handle, $chunk) use (&$responseBody) {
                if(strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { return 0; }
                $responseBody .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function($handle, $line) use (&$retryAfter) {
                if(preg_match('/^Retry-After:\s*([0-9]{1,4})\s*$/i', trim($line), $match)) {
                    $retryAfter = max(1, min(900, intval($match[1])));
                }
                return strlen($line);
            },
        ));
        if($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        self::countSource($method,$path,$accessToken);
        $ok = curl_exec($ch); $code = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE)); $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $error = curl_error($ch); unset($ch);
        if($ok === false || $error !== '') { throw new RuntimeException('api_transport_failed'); }
        return $this->validateResponse(array('code' => $code, 'contentType' => $contentType, 'body' => $responseBody, 'retryAfter' => $retryAfter), $expectNoContent);
    }

    private function requestTimeoutMs() {
        if($this->deadline === null) { return 4000; }
        $remaining = (int)floor(($this->deadline - hrtime(true) / 1000000000) * 1000);
        if($remaining < 1) { throw new RuntimeException('api_deadline_exhausted'); }
        return min(4000, $remaining);
    }

    private function validateResponse($response, $expectNoContent = false) {
        if(!is_array($response) || !isset($response['code'], $response['body'])) { throw new RuntimeException('api_response_invalid'); }
        $code = intval($response['code']); $body = (string)$response['body'];
        if(strlen($body) > self::MAX_RESPONSE_BYTES) { throw new RuntimeException('api_response_too_large'); }
        if($code === 401) { throw new RuntimeException('api_unauthorized'); }
        if($code < 200 || $code >= 300) {
            $retryAfter = !empty($response['retryAfter']) ? max(1, min(900, intval($response['retryAfter']))) : 60;
            throw new RuntimeException($code === 429 ? 'api_http_429_'.$retryAfter : 'api_http_error', $code);
        }
        if($expectNoContent) {
            if($code !== 204 || $body !== '') { throw new RuntimeException('api_logout_response_invalid'); }
            return true;
        }
        if(isset($response['contentType']) && $response['contentType'] !== '' && stripos((string)$response['contentType'], 'application/json') !== 0) { throw new RuntimeException('api_content_type'); }
        try { $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR); } catch(Throwable $e) { throw new RuntimeException('api_json_invalid'); }
        if(!is_array($decoded)) { throw new RuntimeException('api_json_invalid'); }
        return $decoded;
    }

    private function assertAllowedRequest($method, $path, $payload, $accessToken) {
        $method = strtoupper((string)$method);
        $publicPost = $method === 'POST' && ($path === '/v1/auth/browser/qr/start' || $path === '/v1/auth/refresh' || $path === '/v1/auth/logout' || preg_match('#^/v1/auth/browser/qr/[0-9a-f-]{36}/poll$#D', $path));
        $activityDetail = $method === 'GET' && preg_match('#^/v1/activities/[0-9a-f-]{36}\?locale=(?:en-US|zh-Hant|es)$#D', $path);
        $publicActivity = $activityDetail && $accessToken === '';
        // TOGYA_PLUGIN: pintogya_public_stage_feed -- these read routes never borrow a member token.
        $publicStage = $method === 'GET' && $accessToken === '' && (
            preg_match('#^/v1/activities/[0-9a-f-]{36}/stages/[0-9a-f-]{36}/feed\?stageVersion=[1-9][0-9]*&view=latest&limit=20(?:&cursor=[A-Za-z0-9_-]{1,2048})?$#D', $path)
            || preg_match('#^/v1/activities/[0-9a-f-]{36}/comments\?limit=20(?:&cursor=[A-Za-z0-9_-]{1,2048})?$#D', $path));
        $readOnly = $method === 'GET' && ($path === '/v1/me/stage-share-connections'
            || preg_match('#^/v1/app/bootstrap\?locale=(?:en-US|zh-Hant|es)$#D', $path)
            || ($activityDetail && $accessToken !== '')
            || preg_match('#^/v1/activities/[0-9a-f-]{36}/stages/[0-9a-f-]{36}/participants/[0-9a-f-]{36}/journey\?stageVersion=[1-9][0-9]*&limit=100$#D', $path)
            || ($accessToken !== '' && preg_match('#^/v1/activities/[A-Za-z0-9._%:-]+/stages/[A-Za-z0-9._%:-]+/feed\?stageVersion=[1-9][0-9]*(?:&view=latest&limit=20(?:&cursor=[A-Za-z0-9_-]{1,2048})?)?$#D', $path))
            || preg_match('#^/v1/activities/[A-Za-z0-9._%:-]+/finish-story\?limit=1$#D', $path));
        if(!$publicPost && !$readOnly && !$publicActivity && !$publicStage) { throw new RuntimeException('api_route_forbidden'); }
        if($publicPost && $accessToken !== '') { throw new RuntimeException('api_route_forbidden'); }
        if($readOnly && (!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 16384)) { throw new RuntimeException('access_token_invalid'); }
        if($publicActivity && $accessToken !== '') { throw new RuntimeException('api_route_forbidden'); }
        if($method === 'GET' && $payload !== null) { throw new RuntimeException('api_payload_forbidden'); }
    }
}
