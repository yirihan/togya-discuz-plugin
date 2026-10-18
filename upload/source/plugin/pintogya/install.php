<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once dirname(__FILE__).'/lib/pintogya.php';
require_once dirname(__FILE__).'/lib/activity_settings.php';
PintogyaInstallation::assertInstallable();
pintogya_apply_install_migrations();
PintogyaInstallation::initialize();
PintogyaActivitySettings::registerModules();
$finish = true;
