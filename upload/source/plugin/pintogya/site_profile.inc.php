<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }
require_once dirname(__FILE__).'/lib/site_profile.php';

$profileUrl = (isset($_GET['app']) && $_GET['app'] === 'admin' ? '?app=admin&platform=system&action=plugins' : ADMINSCRIPT.'?action=plugins')
    .'&operation=config&do='.(int)$plugin['pluginid'].'&identifier=pintogya&pmod=admin&view=site';
$profileMessageUrl = rtrim((string)$_G['setting']['siteurl'], '/').'/'.$profileUrl;
if(submitcheck('siteprofilesubmit')) {
    try {
        $profile = PintogyaSiteProfile::validate(array('request_name' => $_POST['site_request_name'] ?? null,
            'show_account_link' => $_POST['site_account_link'] ?? null));
        if($profile['show_account_link'] === '1' && !is_file(dirname(__FILE__).'/account.inc.php')) {
            throw new InvalidArgumentException('account_page_not_installed');
        }
    } catch(Throwable $error) {
        cpmsg('请填写 1 至 60 字符的纯文本站名，并检查账号管理页面是否已安装。', $profileMessageUrl, 'error');
    }
    DB::insert('common_setting', array('skey' => PintogyaSiteProfile::SETTING_KEY,
        'svalue' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), false, true);
    updatecache('setting');
    cpmsg('站点差异设置已保存；未修改任务、账号、绑定、奖励或活动开关。', $profileMessageUrl, 'succeed');
}
try { $profile = pintogya_site_profile(); }
catch(Throwable $error) { $profile = array('request_name' => '', 'show_account_link' => '0'); }
showtips('公开域名和站点名称沿用 DX 全局设置；版块、用户组、积分和任务周期沿用原生后台。以下设置仅改变连接请求展示和任务入口，不复制任何用户或奖励数据。');
echo '<form method="post" action="'.dhtmlspecialchars($profileUrl).'">';
echo '<input type="hidden" name="formhash" value="'.dhtmlspecialchars(FORMHASH).'" />';
showtableheader('站点差异设置');
showsetting('Togya 扫码请求显示名称', 'site_request_name', $profile['request_name'], 'text', '', 0,
    '只用于 Togya 确认页显示来源。账号身份不按此名称匹配。留意名称变化不会迁移旧绑定。');
showsetting('任务详情显示账号管理入口', 'site_account_link', $profile['show_account_link'], 'radio', '', 0,
    '仅当本站已安装账号管理页面时可开启；此选项不会启用活动或积分结算。');
showsubmit('siteprofilesubmit', 'submit');
showtablefooter();
echo '</form>';
return true;
