<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// The native pre-delete check runs this guard before DX removes registration.
require_once __DIR__.'/lib/uninstall_guard.php';
PintogyaUninstallGuard::assertCanUninstall();
foreach(PintogyaUninstallGuard::TABLES as $logical) {
    DB::query('DROP TABLE IF EXISTS `'.DB::table($logical).'`');
}
$finish = true;
