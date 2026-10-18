<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_official_examples.php';

final class PintogyaActivityRead {
    private $session;
    private $api;
    private $publicDisplaySources = array();
    private $displayOnly;

    public function __construct($session, $api, $displayOnly = false) {
        if(!is_bool($displayOnly)) { throw new InvalidArgumentException('activity_display_mode_invalid'); }
        $this->session = $session;
        $this->api = $api;
        $this->displayOnly = $displayOnly;
    }

    public function ownedPublicActivities($uid, $locale, $readOnly = false) {
        $binding = $this->session->binding($uid);
        $bootstrap = $readOnly ? $this->session->bootstrapReadOnly($uid, $locale) : $this->session->bootstrap($uid, $locale);
        if(!is_array($bootstrap) || !isset($bootstrap['user']['id'], $bootstrap['activities'])
            || !is_array($bootstrap['activities'])
            || !hash_equals((string)$binding['togya_user_id'], (string)$bootstrap['user']['id'])) {
            throw new RuntimeException('activity_identity_mismatch');
        }

        $activities = array();
        foreach($bootstrap['activities'] as $activity) {
            if(!is_array($activity) || !isset($activity['creator']['id'], $activity['creator']['isSelf'])
                || !is_string($activity['creator']['id'])
                || !hash_equals((string)$binding['togya_user_id'], $activity['creator']['id'])
                || $activity['creator']['isSelf'] !== true
                || !isset($activity['isPublic']) || $activity['isPublic'] !== true
                || !isset($activity['status']) || $activity['status'] !== 'active'
                || !empty($activity['isExample'])) { continue; }
            try { $id = PintogyaCrypto::assertUuid($activity['id'] ?? null); }
            catch(InvalidArgumentException $error) { continue; }
            try { $activities[$id] = $this->projection($activity, $id); }
            catch(RuntimeException $error) { continue; }
        }
        return array_values($activities);
    }

    public function ownedInstantActivity($uid, $activityId, $locale) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $binding = $this->session->binding($uid);
        $detail = $this->session->activityDetailReadOnly($uid, $activityId, $locale);
        if(!is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
            ($detail['isPublic'] ?? null) !== true || ($detail['status'] ?? null) !== 'active' ||
            !empty($detail['isExample']) || ($detail['creator']['isSelf'] ?? null) !== true ||
            !is_string($detail['creator']['id'] ?? null) ||
            !hash_equals((string)$binding['togya_user_id'], $detail['creator']['id'])) {
            throw new RuntimeException('activity_not_owned_public');
        }
        $current = $this->publicActivity($activityId, $locale);
        if(!hash_equals($current['creatorId'], $detail['creator']['id']) ||
            $current['stageVersion'] !== ($detail['stageVersion'] ?? null) ||
            !is_array($detail['stageDetails'] ?? null) || !is_int($detail['totalStages'] ?? null)) {
            throw new RuntimeException('activity_source_changed');
        }
        $fresh = $this->session->binding($uid);
        if((int)$fresh['generation'] !== (int)$binding['generation'] ||
            !hash_equals((string)$fresh['togya_user_id'], (string)$binding['togya_user_id'])) {
            throw new RuntimeException('binding_changed');
        }
        // Only public metadata needed for the local rule, never private instructions or check-in media.
        $stages = array();
        foreach($detail['stageDetails'] as $stage) {
            if(!is_array($stage)) { throw new RuntimeException('instant_stages_invalid'); }
            $row = array();
            foreach(array('id','position','version','stageKind','title') as $key) { $row[$key] = $stage[$key] ?? null; }
            $stages[] = $row;
        }
        return $current + array('totalStages'=>$detail['totalStages'], 'stageDetails'=>$stages);
    }

    public function ownedPublicActivity($uid, $activityId, $locale) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $owned = null;
        foreach($this->ownedPublicActivities($uid, $locale) as $candidate) {
            if(hash_equals($candidate['id'], $activityId)) { $owned = $candidate; break; }
        }
        if($owned === null) { throw new RuntimeException('activity_not_owned_public'); }
        $current = $this->publicActivity($activityId, $locale);
        if(!hash_equals($owned['creatorId'], $current['creatorId'])
            || $owned['stageVersion'] !== $current['stageVersion']) {
            throw new RuntimeException('activity_version_changed');
        }
        return $current;
    }

    public function publicActivity($activityId, $locale, $allowCache = false) {
        global $_G;
        $activityId = PintogyaCrypto::assertUuid($activityId);
        // pintogya_public_authority_before_cache: cache content, never publication permission.
        $activity = $this->publicDisplaySource($activityId, $locale, $allowCache);
        // TOGYA_PLUGIN: pintogya_public_dx_cache -- display projection only, never member facts or tokens.
        $cacheKey = $allowCache && !$this->displayOnly && function_exists('loadcache') && function_exists('savecache')
            ? 'ptgl_p_'.substr(hash('sha256', $activityId."\0".$locale),0,25) : '';
        if($cacheKey !== '') {
            try {
                loadcache($cacheKey);
                $cached = $_G['cache'][$cacheKey] ?? null;
                if(is_array($cached) && ($cached['schema'] ?? null) === 1 &&
                    is_int($cached['expiresAt'] ?? null) && $cached['expiresAt'] > time() &&
                    $cached['expiresAt'] <= time() + 300 && is_array($cached['projection'] ?? null) &&
                    array_keys($cached['projection']) === array('id','title','summary','creatorId','stageVersion','participantCount','thumbnailUrl') &&
                    ($cached['projection']['id'] ?? null) === $activityId &&
                    is_string($cached['projection']['title']) && is_string($cached['projection']['summary']) &&
                    is_string($cached['projection']['creatorId']) && is_int($cached['projection']['stageVersion']) &&
                    $cached['projection']['stageVersion'] > 0 && is_int($cached['projection']['participantCount']) &&
                    $cached['projection']['creatorId'] === ($activity['creator']['id'] ?? null) &&
                    $cached['projection']['stageVersion'] === ($activity['stageVersion'] ?? null) &&
                    is_string($cached['projection']['thumbnailUrl']) &&
                    $cached['projection']['thumbnailUrl'] === self::publicThumbnailUrl($activity)) { return $cached['projection']; }
            } catch(Throwable $error) { /* A missing DX cache must not block authoritative reads. */ }
        }
        if(!is_string($activity['creator']['id'] ?? null) || $activity['creator']['id'] === '') {
            throw new RuntimeException('activity_not_public');
        }
        $projection = $this->projection($activity, $activityId);
        if($cacheKey !== '') {
            $nativeTtl = (int)($_G['setting']['cachethreadlife'] ?? 0);
            $ttl = $nativeTtl > 0 ? min(300, $nativeTtl) : 300;
            $entry = array('schema'=>1, 'expiresAt'=>time()+$ttl, 'projection'=>$projection);
            try { savecache($cacheKey, $entry); $_G['cache'][$cacheKey] = $entry; }
            catch(Throwable $error) { /* Display remains available when cache persistence fails. */ }
        }
        return $projection;
    }

    public function publicStages($activityId, $locale, $expectedStageVersion) {
        global $_G;
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_int($expectedStageVersion) || $expectedStageVersion < 1) {
            throw new InvalidArgumentException('activity_public_stages_version_invalid');
        }
        $detail = $this->publicDisplaySource($activityId, $locale, true);
        if(($detail['id'] ?? null) !== $activityId ||
            ($detail['stageVersion'] ?? null) !== $expectedStageVersion ||
            !is_int($detail['totalStages'] ?? null) || !is_array($detail['stageDetails'] ?? null) ||
            count($detail['stageDetails']) !== $detail['totalStages']) {
            throw new RuntimeException('activity_public_stages_invalid');
        }
        $rows = self::publicStageRows($detail['stageDetails']);
        // pintogya_public_stages_cache: anonymous source only; never borrow a member session.
        $key = 'ptgl_s_'.substr(hash('sha256', $activityId."\0".$locale."\0".$expectedStageVersion),0,25);
        $cache = !$this->displayOnly && function_exists('loadcache') && function_exists('savecache');
        if($cache) {
            try {
                loadcache($key);
                $entry = $_G['cache'][$key] ?? null;
                if(is_array($entry) && array_keys($entry) === array('schema','expiresAt','stages') &&
                    $entry['schema'] === 1 && is_int($entry['expiresAt']) &&
                    $entry['expiresAt'] > time() && $entry['expiresAt'] <= time()+300 &&
                    is_array($entry['stages']) && $entry['stages'] === self::publicStageRows($entry['stages'], true) &&
                    array_column($entry['stages'], 'id') === array_column($rows, 'id') &&
                    array_column($entry['stages'], 'readable') === array_column($rows, 'readable')) {
                    return $entry['stages'];
                }
            } catch(Throwable $ignored) {}
        }
        if($cache) {
            $ttl = intval($_G['setting']['cachethreadlife'] ?? 0);
            $ttl = $ttl > 0 ? min(300, $ttl) : 300;
            $entry = array('schema'=>1, 'expiresAt'=>time()+$ttl, 'stages'=>$rows);
            try { savecache($key, $entry); $_G['cache'][$key] = $entry; }
            catch(Throwable $ignored) {}
        }
        return $rows;
    }

    private function publicDisplaySource($activityId, $locale, $requestSnapshot) {
        $key = $activityId."\0".$locale;
        if(!$requestSnapshot) { unset($this->publicDisplaySources[$key]); }
        if($requestSnapshot && isset($this->publicDisplaySources[$key])) {
            return $this->publicDisplaySources[$key];
        }
        $activity = $this->api->getPublicActivity($activityId, $locale);
        if(!is_array($activity) || !is_string($activity['id'] ?? null) ||
            strtolower($activity['id']) !== $activityId || ($activity['isPublic'] ?? null) !== true ||
            ($activity['status'] ?? null) !== 'active') {
            throw new RuntimeException('activity_not_public');
        }
        // TOGYA_PLUGIN pintogya_official_reference_display: only verified official examples may render.
        if(!empty($activity['isExample']) || isset(PintogyaActivityOfficialExamples::choices()[$activityId])) {
            PintogyaActivityOfficialExamples::verifyPublic($activityId,$activity);
        }
        if($requestSnapshot) { $this->publicDisplaySources[$key] = $activity; }
        return $activity;
    }

    private static function publicStageRows($stages, $cached = false) {
        if(!is_array($stages) || count($stages) < 1 || count($stages) > 100 ||
            array_keys($stages) !== range(0, count($stages)-1)) {
            throw new RuntimeException('activity_public_stages_invalid');
        }
        $rows = array();
        $stageIds = array();
        foreach($stages as $position => $stage) {
            $readKey = $cached ? 'readable' : 'canReadInstructions';
            if(!is_array($stage) || ($stage['position'] ?? null) !== $position ||
                !is_string($stage['title'] ?? null) || trim($stage['title']) === '' ||
                strlen($stage['title']) > 500 || !is_bool($stage[$readKey] ?? null)) {
                throw new RuntimeException('activity_public_stages_invalid');
            }
            // pintogya_public_stage_identity: only IDs already present in the public source.
            $stageId = $stage['id'] ?? null;
            if($stageId !== null) {
                try { $stageId = PintogyaCrypto::assertUuid($stageId); }
                catch(InvalidArgumentException $error) { throw new RuntimeException('activity_public_stages_invalid', 0, $error); }
                if(isset($stageIds[$stageId])) { throw new RuntimeException('activity_public_stages_invalid'); }
                $stageIds[$stageId] = true;
            }
            $row = array('id'=>$stageId, 'position'=>$position, 'title'=>$stage['title'], 'readable'=>$stage[$readKey]);
            foreach(array('objective','completionCriteria','checkinPrompt') as $field) {
                $value = $stage[$readKey] ? ($stage[$field] ?? null) : '';
                if(!is_string($value) || strlen($value) > 8192) {
                    throw new RuntimeException('activity_public_stages_invalid');
                }
                $row[$field] = $value;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function viewerState($uid, $activityId, $locale, $expectedStageVersion, $readOnly = false) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_int($uid) || $uid < 1 || !is_int($expectedStageVersion) || $expectedStageVersion < 1) {
            throw new InvalidArgumentException('activity_viewer_input_invalid');
        }
        $detail = $readOnly ? $this->session->activityDetailReadOnly($uid, $activityId, $locale)
            : $this->session->activityDetail($uid, $activityId, $locale);
        if(!is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
            ($detail['stageVersion'] ?? null) !== $expectedStageVersion ||
            ($detail['isPublic'] ?? null) !== true || !is_bool($detail['isJoined'] ?? null) ||
            !in_array($detail['status'] ?? null, array('active', 'archived'), true)) {
            throw new RuntimeException('activity_viewer_response_invalid');
        }
        $stages = $detail['stageDetails'] ?? null;
        $total = $detail['totalStages'] ?? null;
        if(!is_array($stages) || !is_int($total) || $total < 1 || $total > 100 || count($stages) !== $total) {
            throw new RuntimeException('activity_viewer_stages_invalid');
        }
        $visible = array();
        foreach($stages as $position => $stage) {
            if(!is_array($stage) || ($stage['position'] ?? null) !== $position ||
                !is_string($stage['title'] ?? null) || trim($stage['title']) === '' ||
                strlen($stage['title']) > 500 || !is_bool($stage['canReadInstructions'] ?? null) ||
                !is_bool($stage['canViewFeed'] ?? null) || !is_bool($stage['isCompleted'] ?? null)) {
                throw new RuntimeException('activity_viewer_stages_invalid');
            }
            $stageId = $stage['id'] ?? null;
            if($stageId !== null) {
                try { $stageId = PintogyaCrypto::assertUuid($stageId); }
                catch(InvalidArgumentException $error) { throw new RuntimeException('activity_viewer_stages_invalid', 0, $error); }
            }
            if($stage['canViewFeed'] && $stageId === null) { throw new RuntimeException('activity_viewer_stages_invalid'); }
            $readable = $stage['canReadInstructions'];
            $instructions = array();
            foreach(array('objective', 'completionCriteria', 'checkinPrompt') as $field) {
                $value = $readable ? ($stage[$field] ?? null) : '';
                if(!is_string($value) || strlen($value) > 8192) {
                    throw new RuntimeException('activity_viewer_stages_invalid');
                }
                $instructions[$field] = $value;
            }
            $visible[] = array('id' => $stageId, 'position' => $position, 'title' => $stage['title'],
                'readable' => $readable, 'feedAvailable' => $stage['canViewFeed'],
                'completed' => $stage['isCompleted']) + $instructions;
        }
        $bound = $this->session->binding($uid);
        $scope = intval($bound['generation'] ?? 0) > 0 ? hash('sha256', json_encode(array(
            $uid, $bound['togya_user_id'], intval($bound['generation']), $activityId,
            $expectedStageVersion, $locale, $detail['isJoined'], $visible))) : '';
        return array('state' => $detail['isJoined'] ? 'joined' : 'not_joined', 'stages' => $visible, 'cacheScope' => $scope,
            'personalCompleted' => $detail['isJoined'] && in_array($detail['progress'] ?? null, array(1, 1.0), true));
    }

    public function stageFeed($uid, $activityId, $locale, $expectedStageVersion, $stageId, $cursor = '', $allowCache = false, $readOnly = false) {
        $page = $this->feedPage($uid, $activityId, $locale, $expectedStageVersion, $stageId, $cursor, $allowCache, $readOnly);
        $items = array();
        foreach($page['items'] as $item) {
            $row = array('author' => $item['author'], 'body' => $item['body'],
                'createdAt' => $item['createdAt'], 'hasMedia' => $item['hasMedia'],
                'mediaId' => $item['imageKey'] !== '' ? $item['id'] : null,
                'media' => array_keys($item['imageKeys']),
                'checkinId' => $item['id'], 'isSelf' => $item['isSelf']);
            if(method_exists($this->session,'displayImageUrls')) {
                $row['imageUrls']=$this->session->displayImageUrls($item['imageKeys']);
            }
            $items[]=$row;
        }
        return array('stageId' => $stageId, 'items' => $items, 'nextCursor' => $page['nextCursor']);
    }

    // Server-only identity projection; never part of the browser feed payload.
    public function checkinSources($uid,$activityId,$locale,$version,$stageId,$cursor='') {
        $page=$this->feedPage($uid,$activityId,$locale,$version,$stageId,$cursor,false,true);
        $sources=array();
        foreach($page['items'] as $item) {
            $subject=PintogyaCrypto::assertUuid($item['sourceSubject']);
            if(isset($sources[$item['id']])) { throw new RuntimeException('activity_feed_response_invalid'); }
            $sources[$item['id']]=$subject;
        }
        return $sources;
    }

    public function stageImage($uid, $activityId, $locale, $expectedStageVersion, $stageId, $cursor, $checkinId, $readOnly = false, $imageIndex = 0) {
        if(!is_int($imageIndex) || $imageIndex < 0 || $imageIndex > 5) { throw new InvalidArgumentException('activity_image_input_invalid'); }
        $checkinId = PintogyaCrypto::assertUuid($checkinId);
        $page = $this->feedPage($uid, $activityId, $locale, $expectedStageVersion, $stageId, $cursor, true, $readOnly);
        foreach($page['items'] as $item) {
            if($item['id'] === $checkinId && isset($item['imageKeys'][$imageIndex])) {
                if(method_exists($this->session,'displayImageContext')) {
                    $this->session->displayImageContext($uid,$activityId,$locale,$expectedStageVersion,$stageId,$cursor);
                }
                return $readOnly ? $this->session->checkinImageReadOnly($uid, $item['imageKeys'][$imageIndex])
                    : $this->session->checkinImage($uid, $item['imageKeys'][$imageIndex]);
            }
        }
        throw new RuntimeException('activity_stage_image_unavailable');
    }

    private function feedPage($uid, $activityId, $locale, $expectedStageVersion, $stageId, $cursor, $allowCache = false, $readOnly = false) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $stageId = PintogyaCrypto::assertUuid($stageId);
        if(!is_string($cursor) || ($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor))) {
            throw new InvalidArgumentException('activity_feed_cursor_invalid');
        }
        $viewer = $this->viewerState($uid, $activityId, $locale, $expectedStageVersion, $readOnly);
        $allowed = false;
        if($viewer['state'] === 'joined') {
            foreach($viewer['stages'] as $stage) {
                if($stage['id'] === $stageId && $stage['feedAvailable']) { $allowed = true; break; }
            }
        }
        if(!$allowed) { throw new RuntimeException('activity_feed_not_available'); }
        // pintogya_private_stage_dx_cache: fresh permission check always precedes cache lookup.
        global $_G;
        $cacheKey = $allowCache && !$this->displayOnly && !empty($viewer['cacheScope']) && function_exists('loadcache') && function_exists('savecache')
            ? 'pintogya_feed_'.substr(hash('sha256', $viewer['cacheScope']."\0".$stageId."\0".$cursor), 0, 16) : '';
        if($cacheKey !== '') {
            try {
                loadcache($cacheKey);
                $cached = $_G['cache'][$cacheKey] ?? null;
                if(is_array($cached) && ($cached['schema'] ?? null) === 3 &&
                    is_int($cached['expiresAt'] ?? null) && $cached['expiresAt'] > time() &&
                    $cached['expiresAt'] <= time() + 60 && $this->validCachedFeed($cached['page'] ?? null)) {
                    return $cached['page'];
                }
            } catch(Throwable $ignored) {}
        }
        $feed = $readOnly ? $this->session->stageFeedReadOnly($uid, $activityId, $stageId, $expectedStageVersion, $cursor)
            : $this->session->stageFeed($uid, $activityId, $stageId, $expectedStageVersion, $cursor);
        if(!is_array($feed) || ($feed['activityId'] ?? null) !== $activityId ||
            ($feed['stageId'] ?? null) !== $stageId || ($feed['stageVersion'] ?? null) !== $expectedStageVersion ||
            ($feed['view'] ?? null) !== 'latest' || !is_array($feed['items'] ?? null) || count($feed['items']) > 20) {
            throw new RuntimeException('activity_feed_response_invalid');
        }
        $next = $feed['nextCursor'] ?? null;
        if($next !== null && (!is_string($next) || !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $next))) {
            throw new RuntimeException('activity_feed_response_invalid');
        }
        $bound = $this->session->binding($uid);
        $items = array();
        foreach($feed['items'] as $item) {
            if(!is_array($item) || !is_bool($item['isSameActivity'] ?? null)) {
                throw new RuntimeException('activity_feed_response_invalid');
            }
            if(!$item['isSameActivity']) { continue; }
            if(($item['activityId'] ?? null) !== $activityId || ($item['stageId'] ?? null) !== $stageId ||
                ($item['stageVersion'] ?? null) !== $expectedStageVersion ||
                !is_string($item['kind'] ?? null) || !is_string($item['body'] ?? null) ||
                strlen($item['body']) > 8192 || !is_string($item['author']['displayName'] ?? null) ||
                strlen($item['author']['displayName']) > 200 || !is_string($item['createdAt'] ?? null) ||
                strlen($item['createdAt']) > 64) {
                throw new RuntimeException('activity_feed_response_invalid');
            }
            if(!in_array($item['kind'], array('completion', 'progress'), true)) { continue; }
            if(!is_string($item['author']['id'] ?? null)) {
                throw new RuntimeException('activity_feed_response_invalid');
            }
            try { $checkinId = PintogyaCrypto::assertUuid($item['id'] ?? null); }
            catch(InvalidArgumentException $error) { throw new RuntimeException('activity_feed_response_invalid', 0, $error); }
            $imageKeys = self::checkinImageKeys($item);
            $imageKey = $imageKeys[0] ?? '';
            $items[] = array('id' => $checkinId, 'author' => self::maskedCheckinAuthor($item['author']['displayName']),
                'body' => $item['body'], 'createdAt' => $item['createdAt'],
                'hasMedia' => count($imageKeys) > 0, 'imageKey' => $imageKey, 'imageKeys' => $imageKeys,
                'isSelf' => hash_equals((string)$bound['togya_user_id'], $item['author']['id']),
                'sourceSubject' => $item['author']['id']);
        }
        $page = array('items' => $items, 'nextCursor' => $next);
        if($cacheKey !== '') {
            $ttl = intval($_G['setting']['cachethreadlife'] ?? 0);
            $ttl = $ttl > 0 ? min(60, $ttl) : 60;
            try { savecache($cacheKey, array('schema' => 3, 'expiresAt' => time() + $ttl, 'page' => $page)); }
            catch(Throwable $ignored) {}
        }
        return $page;
    }

    private static function maskedCheckinAuthor($name) {
        // TOGYA_PLUGIN: pintogya_checkin_author_mask -- never send the full Togya name to the forum browser.
        $name = trim($name);
        return preg_match('/^([\p{L}\p{N}])./us', $name, $match) === 1 ? $match[1].'***' : '***';
    }

    // Server-only media keys; browser projections contain indices and local attachment URLs, never source keys.
    public static function checkinImageKeys($item) {
        $keys=$item['imageKeys']??array();$primary=$item['imageKey']??'';
        if(!is_array($keys)||!array_is_list($keys)||count($keys)>6||!is_string($primary)) {
            throw new RuntimeException('activity_feed_response_invalid');
        }
        if($primary!=='')array_unshift($keys,$primary);
        $images=array();$owner=$item['author']['id']??null;
        foreach($keys as $key) {
            if(!is_string($owner)||!is_string($key)||
                !preg_match('~^checkins/([0-9a-f-]{36})/[0-9a-f]{32}\.(?:jpg|png|webp)$~D',$key,$match)||
                !hash_equals($owner,$match[1]))throw new RuntimeException('activity_feed_response_invalid');
            try { PintogyaCrypto::assertUuid($match[1]); }
            catch(InvalidArgumentException $error) { throw new RuntimeException('activity_feed_response_invalid',0,$error); }
            if(!in_array($key,$images,true))$images[]=$key;
        }
        if(count($images)>6)throw new RuntimeException('activity_feed_response_invalid');
        return $images;
    }

    private function validCachedFeed($page) {
        if(!is_array($page) || array_keys($page) !== array('items', 'nextCursor') ||
            !is_array($page['items']) || count($page['items']) > 20 ||
            ($page['nextCursor'] !== null && (!is_string($page['nextCursor']) ||
                !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $page['nextCursor'])))) { return false; }
        foreach($page['items'] as $item) {
            if(!is_array($item) || array_keys($item) !== array('id','author','body','createdAt','hasMedia','imageKey','imageKeys','isSelf','sourceSubject') ||
                !is_string($item['id']) || !is_string($item['author']) ||
                preg_match('/^[\p{L}\p{N}]?\*{3}$/uD', $item['author']) !== 1 ||
                !is_string($item['body']) || strlen($item['body']) > 8192 ||
                !is_string($item['createdAt']) || strlen($item['createdAt']) > 64 ||
                !is_bool($item['hasMedia']) || !is_bool($item['isSelf']) || !is_string($item['sourceSubject']) || !is_string($item['imageKey']) ||
                ($item['imageKey'] !== '' && !preg_match('~^checkins/[0-9a-f-]{36}/[0-9a-f]{32}\.(?:jpg|png|webp)$~D', $item['imageKey']))) { return false; }
            try {
                PintogyaCrypto::assertUuid($item['id']);
                if(self::checkinImageKeys(array('imageKey'=>$item['imageKey'],'imageKeys'=>$item['imageKeys'],
                    'author'=>array('id'=>$item['sourceSubject'])))!==$item['imageKeys'])return false;
            } catch(Throwable $ignored) { return false; }
        }
        return true;
    }

    public function joinedActive($uid, $activityId, $locale, $expectedStageVersion, $readOnly = false) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_int($uid) || $uid < 1 || !is_int($expectedStageVersion) || $expectedStageVersion < 1) {
            throw new InvalidArgumentException('activity_join_input_invalid');
        }
        $detail = $readOnly ? $this->session->activityDetailReadOnly($uid, $activityId, $locale)
            : $this->session->activityDetail($uid, $activityId, $locale);
        if(!is_array($detail) || ($detail['id'] ?? null) !== $activityId ||
            ($detail['stageVersion'] ?? null) !== $expectedStageVersion ||
            ($detail['isPublic'] ?? null) !== true || !is_bool($detail['isJoined'] ?? null) ||
            ($detail['status'] ?? null) !== 'active') {
            throw new RuntimeException('activity_join_response_invalid');
        }
        return $detail['isJoined'];
    }

    private function projection($activity, $id) {
        if(!isset($activity['title'], $activity['summary'], $activity['creator']['id'], $activity['stageVersion'])
            || !is_string($activity['title']) || trim($activity['title']) === ''
            || !is_string($activity['summary'])
            || !is_int($activity['stageVersion']) || $activity['stageVersion'] < 1) {
            throw new RuntimeException('activity_response_invalid');
        }
        return array(
            'id' => $id,
            'title' => $activity['title'],
            'summary' => $activity['summary'],
            'creatorId' => $activity['creator']['id'],
            'stageVersion' => $activity['stageVersion'],
            'participantCount' => isset($activity['participantCount']) && is_int($activity['participantCount']) && $activity['participantCount'] >= 0 ? $activity['participantCount'] : 0,
            'thumbnailUrl' => self::publicThumbnailUrl($activity),
        );
    }

    private static function publicThumbnailUrl($activity) {
        $official = PintogyaActivityOfficialExamples::coverUrl($activity);
        if($official !== '') { return $official; }
        if(!in_array($activity['sourceAvailability'] ?? null, array('available', 'unchecked'), true) ||
            !is_string($activity['thumbnailUrl'] ?? null) || strlen($activity['thumbnailUrl']) > 500) {
            return '';
        }
        $url = $activity['thumbnailUrl'];
        $parts = parse_url($url);
        if(!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            !isset($parts['host'], $parts['path'])) { return ''; }
        $host = strtolower($parts['host']);
        $provider = $activity['tutorialProvider'] ?? '';
        if($provider === 'youtube' && $host === 'i.ytimg.com' &&
            is_string($activity['tutorialProviderVideoId'] ?? null) &&
            preg_match('~^/vi(?:_webp)?/([A-Za-z0-9_-]{11})/[A-Za-z0-9_-]+\.(?:jpg|jpeg|webp)$~D', $parts['path'], $match) &&
            hash_equals($activity['tutorialProviderVideoId'], $match[1])) { return $url; }
        if($provider === 'vimeo' && $host === 'i.vimeocdn.com' &&
            preg_match('~^/video/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$~D', $parts['path'])) { return $url; }
        return '';
    }
}
