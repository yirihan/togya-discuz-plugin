<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }
if(($operation ?? ($_GET['operation'] ?? '')) === 'delete') {
    require_once __DIR__.'/lib/uninstall_guard.php';
    try { PintogyaUninstallGuard::assertCanUninstall(); }
    catch(RuntimeException $error) {
        cpmsg('相关业务表仍有记录，按原有规则拒绝卸载。停用插件不会删除数据；请先核对远端撤销与数据处置，不要直接清表来绕过检查。','action=plugins','error');
    }
}
