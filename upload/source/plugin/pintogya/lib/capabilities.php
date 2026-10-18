<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaCapabilities {
    // These gates stay closed until the standalone native/financial fixtures pass.
    public static function available($name) {
        return false;
    }

    public static function assertAvailable($name) {
        if(!self::available($name)) { throw new RuntimeException('extension_not_verified'); }
    }
}
