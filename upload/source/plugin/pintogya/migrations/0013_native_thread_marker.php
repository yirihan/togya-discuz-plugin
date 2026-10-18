<?php
if(!defined('IN_DISCUZ') || !defined('PINTOGYA_INSTALL_CONTEXT')) { exit('Access Denied'); }
require_once __DIR__.'/../lib/activity_native_post.php';
DB::query('START TRANSACTION');
try {
    PintogyaActivityNativePost::upgradeLegacyMarkers();
    DB::query('COMMIT');
} catch(Throwable $error) {
    DB::query('ROLLBACK');
    throw $error;
}
