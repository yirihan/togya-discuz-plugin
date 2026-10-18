<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaCapabilities {
    // Only the native member composer has scoped adapter evidence; financial/interaction gates remain closed.
    public static function available($name) {
        return $name==='native_composer';
    }

    public static function assertAvailable($name) {
        if(!self::available($name)) { throw new RuntimeException('extension_not_verified'); }
    }

    public static function assertPublicationRule($rule) {
        if(!self::available('rewards')&&(!is_array($rule)||($rule['total']??null)!==0)) {
            throw new RuntimeException('extension_not_verified');
        }
    }
}
