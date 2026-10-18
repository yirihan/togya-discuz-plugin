<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityNativePost {
    const NEUTRAL_TEXT = 'Togya activity';
    const MARKER_PREFIX = "Togya activity\0\0\0pintogya:";

    public static function messageForDigest($digest) {
        if(!is_string($digest) || !preg_match('/^[0-9a-f]{64}$/D', $digest)) {
            throw new InvalidArgumentException('activity_reservation_digest_invalid');
        }
        return self::MARKER_PREFIX.$digest;
    }

    public static function digestFromMessage($message) {
        if(!is_string($message) || strlen($message) !== strlen(self::MARKER_PREFIX) + 64 ||
            substr($message, 0, strlen(self::MARKER_PREFIX)) !== self::MARKER_PREFIX) { return null; }
        $digest = substr($message, strlen(self::MARKER_PREFIX));
        return preg_match('/^[0-9a-f]{64}$/D', $digest) ? $digest : null;
    }

    public static function actionToken($post) {
        $token = is_array($post) ? ($post['pintogya_action_token'] ?? null) : null;
        if(!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new InvalidArgumentException('activity_action_token_invalid');
        }
        return $token;
    }

    public static function canonicalParams($params, $prepared, $now) {
        if(!is_array($params) || !is_array($prepared) ||
            ($prepared['subject'] ?? null) !== self::NEUTRAL_TEXT ||
            ($prepared['message'] ?? null) !== self::NEUTRAL_TEXT ||
            !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('activity_native_post_invalid');
        }
        PintogyaCrypto::assertUuid($prepared['activityId'] ?? null);
        $nativeMessage = self::messageForDigest($prepared['reservationDigest'] ?? null);
        foreach(array(
            'subject' => self::NEUTRAL_TEXT, 'message' => $nativeMessage,
            'content' => '', 'contentType' => 'text', 'contentEditor' => 'default',
            'typeid' => 0, 'sortid' => 0, 'readperm' => 0, 'price' => 0,
            'isanonymous' => 0, 'save' => 1, 'publishdate' => $now,
            'sticktopic' => 0, 'digest' => 0, 'ordertype' => 0,
            'hiddenreplies' => 0, 'tags' => '', 'htmlon' => 0,
            'imgcontent' => 0, 'imgcontentwidth' => 0, 'usesig' => 0,
            'typeexpiration' => 0, 'original' => '', 'source' => '',
            'geoloc' => '', 'allownoticeauthor' => 0,
            'bbcodeoff' => 0, 'smileyoff' => 0, 'parseurloff' => 0,
        ) as $key => $value) {
            $params[$key] = $value;
        }
        return $params;
    }
}
