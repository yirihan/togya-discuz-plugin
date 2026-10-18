<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }
if(($operation ?? ($_GET['operation'] ?? '')) === 'delete') {
    require_once __DIR__.'/lib/uninstall_guard.php';
    try { PintogyaUninstallGuard::assertCanUninstall(); }
    catch(RuntimeException $error) {
        cpmsg('请先通过插件解除有效账户连接，并等待或取消未过期的扫码请求，再卸载。插件数据会保留，不会自动清空。','action=plugins','error');
    }
}
