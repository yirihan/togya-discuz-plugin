<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_official_examples.php';

final class PintogyaActivityPublicRead {
    private $api;

    public function __construct($api) { $this->api = $api; }

    public function feed($activityId, $version, $locale, $stageId, $cursor = '') {
        $stageId = PintogyaCrypto::assertUuid($stageId);
        $source = $this->source($activityId, $version, $locale);
        if($source['stageId'] !== $stageId) { throw new RuntimeException('activity_public_feed_unavailable'); }
        return $this->projectFeed($source, self::cursor($cursor));
    }

    public function checkinSources($activityId, $version, $locale, $stageId, $cursor = '') {
        $source=$this->source($activityId,$version,$locale);
        if($source['stageId']!==PintogyaCrypto::assertUuid($stageId)) { throw new RuntimeException('activity_public_feed_unavailable'); }
        $page=$this->readFeed($source,self::cursor($cursor));$owners=array();
        foreach($page['items'] as $item) { $owners[$item['id']]=$item['nativeSubject']; }
        return $owners; // Server-side identity lookup only; never returned by the public feed controller.
    }

    public function image($activityId, $version, $locale, $stageId, $cursor, $checkinId, $index) {
        $checkinId = PintogyaCrypto::assertUuid($checkinId);
        $stageId = PintogyaCrypto::assertUuid($stageId);
        if(!is_int($index) || $index < 0 || $index > 5) { throw new InvalidArgumentException('activity_public_image_invalid'); }
        $source = $this->source($activityId, $version, $locale);
        if($source['stageId'] !== $stageId) { throw new RuntimeException('activity_public_feed_unavailable'); }
        $page = $this->readFeed($source, self::cursor($cursor));
        foreach($page['items'] as $item) {
            if($item['id'] === $checkinId && isset($item['imageKeys'][$index])) {
                return $this->api->getPublicCheckinImage($item['imageKeys'][$index]);
            }
        }
        throw new RuntimeException('activity_public_image_unavailable');
    }

    public function comments($activityId, $version, $locale, $cursor = '') {
        $source = $this->source($activityId, $version, $locale);
        $data = $this->api->getPublicActivityComments($source['activityId'], self::cursor($cursor));
        if(!is_array($data) || !is_array($data['comments'] ?? null) || !array_is_list($data['comments']) || count($data['comments']) > 20) {
            throw new RuntimeException('activity_public_comments_invalid');
        }
        $rows = array();
        foreach($data['comments'] as $item) {
            if(!is_array($item) || ($item['activityId'] ?? null) !== $source['activityId'] ||
                ($item['kind'] ?? null) !== 'comment' || ($item['visibility'] ?? null) !== 'public' ||
                !is_int($item['revision'] ?? null) || $item['revision'] < 1) {
                throw new RuntimeException('activity_public_comments_invalid');
            }
            $parent = $item['parentId'] ?? null;
            if($parent !== null) { $parent = PintogyaCrypto::assertUuid($parent); }
            $rows[] = array('id'=>PintogyaCrypto::assertUuid($item['id'] ?? null), 'parentId'=>$parent,
                'author'=>self::author($item['author']['displayName'] ?? null),
                'body'=>self::text($item['body'] ?? null, 8192),
                'createdAt'=>self::text($item['createdAt'] ?? null, 64));
        }
        return array('activityId'=>$source['activityId'], 'comments'=>$rows,
            'nextCursor'=>self::nextCursor($data['nextCursor'] ?? null));
    }

    private function source($activityId, $version, $locale) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if(!is_int($version) || $version < 1) { throw new InvalidArgumentException('activity_public_version_invalid'); }
        $data = $this->api->getPublicActivity($activityId, $locale);
        if(!is_array($data) || ($data['id'] ?? null) !== $activityId || ($data['isPublic'] ?? null) !== true ||
            ($data['status'] ?? null) !== 'active' || ($data['stageVersion'] ?? null) !== $version ||
            !is_array($data['stageDetails'] ?? null) || !array_is_list($data['stageDetails']) ||
            count($data['stageDetails']) < 1 || count($data['stageDetails']) > 100 ||
            ($data['totalStages'] ?? null) !== count($data['stageDetails'])) {
            throw new RuntimeException('activity_public_feed_unavailable');
        }
        if(!empty($data['isExample']) || isset(PintogyaActivityOfficialExamples::choices()[$activityId])) {
            PintogyaActivityOfficialExamples::verifyPublic($activityId, $data);
        }
        $first = $data['stageDetails'][0];
        if(!is_array($first) || ($first['position'] ?? null) !== 0 || ($first['version'] ?? null) !== $version ||
            ($first['canViewFeed'] ?? null) !== true || ($first['canReadInstructions'] ?? null) !== true) {
            throw new RuntimeException('activity_public_feed_unavailable');
        }
        return array('activityId'=>$activityId, 'stageVersion'=>$version,
            'stageId'=>PintogyaCrypto::assertUuid($first['id'] ?? null));
    }

    private function projectFeed($source, $cursor) {
        $page = $this->readFeed($source, $cursor);
        $items = array();
        foreach($page['items'] as $item) {
            $media = array();
            foreach($item['imageKeys'] as $index=>$ignored) { $media[] = $index; }
            if(method_exists($this->api,'displayImageUrls')) {
                $item['imageUrls']=$this->api->displayImageUrls($item['imageKeys']);
            }
            unset($item['imageKeys'],$item['nativeSubject']);
            $items[] = $item + array('media'=>$media);
        }
        return array('stageId'=>$source['stageId'], 'items'=>$items, 'nextCursor'=>$page['nextCursor']);
    }

    private function readFeed($source, $cursor) {
        $data = $this->api->getPublicStageFeed($source['activityId'], $source['stageId'], $source['stageVersion'], $cursor);
        if(!is_array($data) || ($data['activityId'] ?? null) !== $source['activityId'] ||
            ($data['stageId'] ?? null) !== $source['stageId'] || ($data['stageVersion'] ?? null) !== $source['stageVersion'] ||
            ($data['view'] ?? null) !== 'latest' || !is_array($data['items'] ?? null) ||
            !array_is_list($data['items']) || count($data['items']) > 20) {
            throw new RuntimeException('activity_public_feed_invalid');
        }
        $rows = array();$seen = array();
        foreach($data['items'] as $item) {
            if(!is_array($item) || ($item['activityId'] ?? null) !== $source['activityId'] ||
                ($item['stageId'] ?? null) !== $source['stageId'] || ($item['stageVersion'] ?? null) !== $source['stageVersion'] ||
                ($item['isSameActivity'] ?? null) !== true || ($item['canInteract'] ?? null) !== false ||
                ($item['kind'] ?? null) !== 'completion') {
                throw new RuntimeException('activity_public_feed_invalid');
            }
            $id = PintogyaCrypto::assertUuid($item['id'] ?? null);
            $owner = PintogyaCrypto::assertUuid($item['author']['id'] ?? null);
            if(isset($seen[$id])) { throw new RuntimeException('activity_public_feed_invalid'); }
            $seen[$id] = true;
            $keys = $item['imageKeys'] ?? array();
            if(!is_array($keys) || !array_is_list($keys) || count($keys) > 6) { throw new RuntimeException('activity_public_feed_invalid'); }
            $primary = $item['imageKey'] ?? '';
            if(!is_string($primary)) { throw new RuntimeException('activity_public_feed_invalid'); }
            if($primary !== '') { array_unshift($keys, $primary); }
            $images = array();
            foreach($keys as $key) {
                if(!is_string($key) || !preg_match('~^checkins/([0-9a-f-]{36})/[0-9a-f]{32}\.(?:jpg|png|webp)$~D', $key, $match) ||
                    !hash_equals($owner, $match[1])) { throw new RuntimeException('activity_public_feed_invalid'); }
                if(!in_array($key, $images, true)) { $images[] = $key; }
            }
            if(count($images) > 6) { throw new RuntimeException('activity_public_feed_invalid'); }
            $rows[] = array('id'=>$id, 'nativeSubject'=>$owner,'author'=>self::author($item['author']['displayName'] ?? null),
                'body'=>self::text($item['body'] ?? null, 8192), 'createdAt'=>self::text($item['createdAt'] ?? null, 64),
                'imageKeys'=>$images);
        }
        return array('items'=>$rows,'nextCursor'=>self::nextCursor($data['nextCursor'] ?? null));
    }

    private static function author($name) {
        $name = trim(self::text($name, 200));
        return preg_match('/^([\p{L}\p{N}])./us', $name, $match) === 1 ? $match[1].'***' : '***';
    }

    private static function text($value, $limit) {
        if(!is_string($value) || strlen($value) > $limit) { throw new RuntimeException('activity_public_response_invalid'); }
        return $value;
    }

    private static function cursor($value) {
        if(!is_string($value) || ($value !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $value))) {
            throw new InvalidArgumentException('activity_public_cursor_invalid');
        }
        return $value;
    }

    private static function nextCursor($value) {
        return $value === null ? null : self::cursor($value);
    }
}
