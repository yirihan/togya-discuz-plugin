<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaSiteProfile {
    const SETTING_KEY = 'pintogya_site_profile';

    public static function validHost($host) {
        return is_string($host) && preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/D', $host) === 1;
    }

    public static function validate($value) {
        if(!is_array($value) || count($value) !== 2 || !array_key_exists('request_name', $value) ||
            !array_key_exists('show_account_link', $value) || !is_string($value['request_name']) ||
            !preg_match('/\A[^\x00-\x1f\x7f<>]{1,60}\z/uD', $value['request_name']) ||
            trim($value['request_name']) !== $value['request_name'] ||
            !in_array($value['show_account_link'], array('0', '1'), true)) {
            throw new InvalidArgumentException('site_profile_invalid');
        }
        return array('request_name' => $value['request_name'], 'show_account_link' => $value['show_account_link']);
    }

    public static function fromSettings($settings) {
        if(!is_array($settings)) { $settings = array(); }
        if(isset($settings[self::SETTING_KEY]) && $settings[self::SETTING_KEY] !== '') {
            if(!is_string($settings[self::SETTING_KEY])) { throw new InvalidArgumentException('site_profile_invalid'); }
            return self::validate(json_decode($settings[self::SETTING_KEY], true, 8, JSON_THROW_ON_ERROR));
        }
        $name = isset($settings['bbname']) && is_string($settings['bbname']) ? trim(strip_tags($settings['bbname'])) : 'Forum';
        try { return self::validate(array('request_name' => $name, 'show_account_link' => '0')); }
        catch(InvalidArgumentException $error) { return array('request_name' => 'Forum', 'show_account_link' => '0'); }
    }

    public static function hostFromSettings($settings) {
        $url = is_array($settings) && isset($settings['siteurl']) ? $settings['siteurl'] : null;
        if(!is_string($url)) { throw new RuntimeException('site_origin_not_configured'); }
        $parts = parse_url($url);
        if(!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', array('', '/'), true) ||
            !self::validHost($parts['host'])) {
            throw new RuntimeException('site_origin_invalid');
        }
        return $parts['host'];
    }
}

function pintogya_site_profile() {
    global $_G;
    return PintogyaSiteProfile::fromSettings(isset($_G['setting']) ? $_G['setting'] : array());
}

function pintogya_configured_site_host() {
    global $_G;
    return PintogyaSiteProfile::hostFromSettings(isset($_G['setting']) ? $_G['setting'] : array());
}
