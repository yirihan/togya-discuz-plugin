<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }
require_once __DIR__.'/lib/activity_instant_config.php';
require_once __DIR__.'/lib/api_client.php';
require_once libfile('function/forumlist');
// Reopening GET reads the current site settings after an acknowledgement/cache update was lost.
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET' && empty($_POST)) {
    $_G['setting']=PintogyaActivityInstantRuleStore::readSettings($_G['setting']);
}

$instantUrl=(isset($_GET['app']) && $_GET['app'] === 'admin' ? '?app=admin&platform=system&action=plugins' : ADMINSCRIPT.'?action=plugins')
    .'&operation=config&do='.(int)$plugin['pluginid'].'&identifier=pintogya&pmod=admin&view=instant';
$instantMessageUrl=rtrim((string)($_G['setting']['siteurl'] ?? $_G['siteurl']),'/').'/'.$instantUrl;
$credits=is_array($_G['setting']['extcredits'] ?? null) ? $_G['setting']['extcredits'] : array();
$previews=array(); $publicationResults=null;
$usergroups=DB::fetch_all('SELECT groupid,grouptitle FROM %t ORDER BY groupid',array('common_usergroup'));
$saveInstantSettings=static function($changes,$expected=array()) use ($instantMessageUrl) {
    try { PintogyaActivityInstantRuleStore::saveSettings($changes,$expected); }
    catch(Throwable $error) {
        cpmsg($error->getMessage()==='instant_settings_outcome_unknown'
            ? '保存结果暂不确定。请重新打开后台读回设置，不要依据此提示自动重试或开启结算。'
            : '保存失败或设置已被另一个请求更改。请重新打开后台核对；本站设置表必须为 InnoDB。',$instantMessageUrl,'error');
    }
    updatecache('setting');
};
$settingGuard=array();
foreach(PintogyaActivityInstantConfig::settingsKeys() as $key) { $settingGuard[$key]=(string)($_G['setting'][$key] ?? ''); }

// TOGYA_PLUGIN pintogya_instant_native_controls: native selectors and independent explicit operation switches.
if(submitcheck('instantpublicationsubmit')) {
    $raw=$_POST['activity_forum_fid'] ?? '';
    $forum=(is_string($raw) || is_int($raw)) && preg_match('/^[1-9][0-9]{0,9}$/D',(string)$raw)
        ? DB::fetch_first('SELECT fid,type,status FROM %t WHERE fid=%d',array('forum_forum',(int)$raw)) : null;
    try { $changes=PintogyaActivityInstantConfig::publicationChanges(array('forum_fid'=>$raw,
        'publisher_groups'=>$_POST['activity_publisher_groups'] ?? array()),$forum,$usergroups,$_G['setting']); }
    catch(Throwable $error) {
        cpmsg($error->getMessage()==='activity_forum_invalid'
            ? '请选择本站实际存在的普通版块或子版块；关闭的版块可先保存，启用发布前须在 DX 版块管理中开启。'
            : '请选择本站有效用户组；请使用 DX 原生选择器，不接受不存在的编号。',$instantMessageUrl,'error');
    }
    $saveInstantSettings($changes,$settingGuard);
    cpmsg('版块和用户组已保存。实际变更时仅关闭新发布入口；未改变同步、结算、旧六任务或已成立权益。',$instantMessageUrl,'succeed');
}
if(submitcheck('instantoperationssubmit')) {
    $fid=(int)($_G['setting'][PintogyaActivitySettings::FORUM_KEY] ?? 0);
    $forum=DB::fetch_first('SELECT fid,type,status,allowspecialonly,allowpostspecial FROM %t WHERE fid=%d',array('forum_forum',$fid));
    $field=DB::fetch_first('SELECT threadplugin FROM %t WHERE fid=%d',array('forum_forumfield',$fid));
    if($forum) { $forum['threadplugin']=$field ? dunserialize($field['threadplugin']) : null; }
    $permissions=table_common_setting::t()->fetch_setting('allowthreadplugin',true);
    $tables=array();
    foreach(array('publication','sync','payment') as $operation) {
        if(($_POST['instant_operations'][$operation] ?? null)!=='1') { continue; }
        foreach(PintogyaActivityInstantConfig::requiredTables($operation) as $logical) {
            if(array_key_exists($logical,$tables)) { continue; }
            $engine=DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array(DB::table($logical)));
            $tables[$logical]=strtoupper((string)$engine)==='INNODB';
        }
    }
    try { $changes=PintogyaActivityInstantConfig::operationChanges($_POST['instant_operations'] ?? null,
        $_G['setting'],$forum,$credits,$tables,$permissions,$usergroups); }
    catch(Throwable $error) { cpmsg('启用失败：请核对新版 InnoDB 表、两类默认金额及 DX 版块/用户组特殊主题权限。同步和已有权益结算不依赖旧奖池、截止或审核表。',$instantMessageUrl,'error'); }
    $saveInstantSettings($changes,$settingGuard);
    cpmsg('设置已保存；未发放积分。后台同步由 Togya Plugin 独立维护入口运行，未验收的原生扩展和积分结算仍由后端关闭。',$instantMessageUrl,'succeed');
}

if(submitcheck('instantdefaultssubmit')) {
    try { $changes=PintogyaActivityInstantConfig::defaultChanges($_POST['instant_defaults'] ?? null,$credits); }
    catch(Throwable $error) { cpmsg('请选取已配置的积分字段，填写 0 至 2147483647 的基础额和阶段额。不能留空或使用旧奖池字段。',$instantMessageUrl,'error'); }
    $saveInstantSettings($changes);
    cpmsg('两类奖励默认金额已保存，仅作用于之后创建的规则；现有活动、六任务、同步和结算开关均未更改。',$instantMessageUrl,'succeed');
}
// TOGYA_PLUGIN pintogya_participant_rating_limit: configuration only; never enables a third reward kind.
if(submitcheck('instantratinglimitsubmit')) {
    try { $changes=PintogyaActivityInstantConfig::ratingLimitChanges($_POST['activity_rating_total_cap'] ?? null); }
    catch(Throwable $error) { cpmsg('评分总额上限须为 0 至 2147483647 的整数。0 表示不允许评分加成，不表示无限额。',$instantMessageUrl,'error'); }
    $key=PintogyaActivityInstantConfig::RATING_LIMIT_KEY;
    $saveInstantSettings($changes,array($key=>$settingGuard[$key]));
    cpmsg('评分总额上限已保存；没有启用评分加成，没有改变阶段、Finish Story、六任务或已发积分。',$instantMessageUrl,'succeed');
}
if(submitcheck('instantofficialsubmit') || submitcheck('instantpreviewsubmit') || submitcheck('instantpublishsubmit') ||
    submitcheck('instantpermissionsubmit')) {
    try {
        $input=$_POST['official_example'] ?? null;
        $raw=is_array($input) ? ($input['publisher_uid'] ?? '') : '';
        $member=(is_string($raw) || is_int($raw)) && preg_match('/^[1-9][0-9]{0,7}$/D',(string)$raw)
            ? DB::fetch_first('SELECT uid FROM %t WHERE uid=%d',array('common_member',(int)$raw)) : null;
        $selection=PintogyaActivityInstantConfig::officialSelections($input,$member);
    } catch(Throwable $error) { cpmsg('请至少勾选一条固定官方示例，可多选，并填写本站存在的会员 UID。',$instantMessageUrl,'error'); }
    // TOGYA_PLUGIN pintogya_official_permission_preflight: same-request reentry, no source or publication calls.
    if(submitcheck('instantpermissionsubmit')) {
        require_once __DIR__.'/lib/activity_official_publish.php';
        try {
            $native=new PintogyaActivityOfficialNative();
            $fid=(int)($_G['setting'][PintogyaActivitySettings::FORUM_KEY] ?? 0);
            $native->publisher($selection['publisher_uid'],$fid);
            $native->publisher($selection['publisher_uid'],$fid);
        } catch(Throwable $error) {
            cpmsg('权限预检未通过。'.PintogyaActivityOfficialPublish::failureMessage($error->getMessage()),$instantMessageUrl,'error');
        }
        cpmsg('两次 DX 原生权限预检均通过，管理员上下文已恢复；未读取 Togya、创建主题或发放积分。预检通过不等于真实发布已完成。',$instantMessageUrl,'succeed');
    }
    if(submitcheck('instantpublishsubmit')) {
        require_once __DIR__.'/lib/activity_official_publish.php';
        require_once __DIR__.'/lib/repository.php';
        require_once __DIR__.'/lib/site_profile.php';
        try {
            if(!PintogyaActivityInstantRuleStore::installed()) { throw new RuntimeException('instant_config_schema_unavailable'); }
            $futureDefaults=null;
            try { $futureDefaults=PintogyaActivityInstantConfig::defaultsFromJson(
                $_G['setting'][PintogyaActivityInstantRule::SETTING_KEY] ?? '',$credits); }
            catch(InvalidArgumentException $error) { /* Existing publication can be read back without future defaults. */ }
            $publisher=new PintogyaActivityOfficialPublish(new PintogyaRepository(),
                new PintogyaApiClient('https://api.togya.com'),new PintogyaActivityOfficialNative(),
                pintogya_configured_site_host(),(int)($_G['setting']['pintogya_activity_forum_fid'] ?? 0),
                (string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0')==='1',$credits);
            $publicationResults=$publisher->publishSelected((int)$_G['uid'],$selection,$futureDefaults,time());
        } catch(Throwable $error) {
            cpmsg(PintogyaActivityOfficialPublish::failureMessage($error->getMessage()),$instantMessageUrl,'error');
        }
    }
    if(isset($_POST['instantofficialsubmit'])) {
        $saveInstantSettings(array(PintogyaActivityInstantConfig::OFFICIAL_KEY=>PintogyaCrypto::canonicalJson($selection)));
        cpmsg('官方示例与本地发布 UID 草稿已保存；未创建主题、绑定或奖励。实际发布前仍须复核 DX 原生发帖权限。',$instantMessageUrl,'succeed');
    }
    if(submitcheck('instantpreviewsubmit')) {
        $api=new PintogyaApiClient('https://api.togya.com');
        foreach($selection['activity_ids'] as $id) {
            try { $previews[$id]=array('preview'=>PintogyaActivityOfficialExamples::readReference($api,$id,'en-US')); }
            catch(Throwable $error) {
                $previews[$id]=array('error'=>in_array($error->getMessage(),array('official_example_rules_unavailable','official_example_details_invalid'),true)
                    ? '官方活动或完整阶段元数据未通过校验，未发帖或发奖；阶段正文未解锁本身不会阻止发布。'
                    : '正式公开接口暂不可读或返回未通过受信任来源校验；本次未发帖或发奖。');
            }
        }
    }
}
if(submitcheck('instantdraftsubmit')) {
    try { PintogyaActivityInstantRuleStore::saveDraft($_POST['instant_activity_id'] ?? null,
        $_POST['instant_draft'] ?? null,$credits,time()); }
    catch(Throwable $error) { cpmsg('保存失败：只可修改未发布且没有奖励权益的草稿；请检查阶段金额或重新读回记录。已发布规则不可改写。',$instantMessageUrl,'error'); }
    cpmsg('未发布活动的分阶段金额已保存；未触发同步、发帖或结算。',$instantMessageUrl,'succeed');
}

$formStart=static function() use ($instantUrl,$plugin) {
    echo '<form method="post" action="'.dhtmlspecialchars($instantUrl).'">';
    if(isset($_GET['app']) && $_GET['app'] === 'admin') {
        echo '<input type="hidden" name="app" value="admin" /><input type="hidden" name="platform" value="system" />';
    }
    foreach(array('action'=>'plugins','operation'=>'config','do'=>(string)$plugin['pluginid'],
        'identifier'=>'pintogya','pmod'=>'admin','view'=>'instant','formhash'=>FORMHASH) as $key=>$value) {
        echo '<input type="hidden" name="'.$key.'" value="'.dhtmlspecialchars($value).'" />';
    }
};
echo '<p><a href="'.dhtmlspecialchars(str_replace('&view=instant','&view=site',$instantUrl)).'">站点差异设置</a> | '.
    '<a href="'.dhtmlspecialchars(str_replace('&view=instant','&view=ledger',$instantUrl)).'">历史账本（只读）</a> | '.
    '<a href="'.dhtmlspecialchars(str_replace('&view=instant','&view=reconcile',$instantUrl)).'">待定主题对账（只读）</a> | '.
    '<a href="'.dhtmlspecialchars(str_replace('&view=instant','&view=orphan',$instantUrl)).'">隐藏草稿扫描（只读）</a></p>';
echo '<p><a href="'.dhtmlspecialchars(str_replace('&view=instant','&view=instantledger',$instantUrl)).'">两类即时奖励对账（只读）</a></p>';
showtips('新活动只采用每个普通阶段首次完成打卡奖励，以及 Finish Story 首次完成打卡基础奖励。每人总奖励＝基础额＋各普通阶段金额之和；长期开放，无人数/评分增长、预算封顶、报名截止或默认人工审核。保存草稿不授权发奖。');
$selected=(int)($_G['setting'][PintogyaActivitySettings::FORUM_KEY] ?? 0);
$forumOptions=forumselect(false,0,$selected,true);
$groupOptions=array();
foreach($usergroups as $group) {
    if((int)($group['groupid'] ?? 0)>0) { $groupOptions[]=array((int)$group['groupid'],dhtmlspecialchars($group['grouptitle'])); }
}
$formStart(); showtableheader('新活动版块与原生发布权限');
echo '<tr><th>活动专用版块</th><td><select name="activity_forum_fid"><option value="0">尚未选择</option>'.$forumOptions.'</select></td></tr>';
showtips('可先保存关闭的版块，不会自动开启版块或发布。启用发布前须在 DX 版块管理中开启所选版块。');
showsetting('允许发起活动的用户组（留空则全部）',array('activity_publisher_groups',$groupOptions),
    PintogyaActivitySettings::publisherGroupIds($_G['setting'][PintogyaActivitySettings::PUBLISHER_GROUPS_KEY] ?? ''),'mcheckbox',0,0,
    '留空则全部：不额外限制用户组，实际发帖仍须通过 DX 版块、用户组及 Togya 特殊主题权限。勾选后仅允许所选组；实际变更仅关闭新发布入口，不影响已成立待付权益。');
showsubmit('instantpublicationsubmit','保存版块与用户组'); showtablefooter(); echo '</form>';
$formStart(); showtableheader('独立运行开关（保存不发积分）');
foreach(array('publication'=>array('新发布与扫码参与',PintogyaActivitySettings::ENABLED_KEY),
    'sync'=>array('后台只读事实同步',PintogyaActivityInstantRule::SYNC_ENABLED_KEY),
    'payment'=>array('两类即时奖励结算',PintogyaActivityInstantRule::ENABLED_KEY)) as $operation=>$control) {
    showsetting($control[0],'instant_operations['.$operation.']',
        (string)($_G['setting'][$control[1]] ?? '0')==='1' ? 1 : 0,'radio');
}
echo '<tr><td colspan="2">三项分别控制。当前通用候选的原生扩展和积分结算尚未开放，后台由独立维护入口运行。保存默认金额不代表启用奖励；未知或权限不足保持待同步，不放宽权限、来源核验或入账幂等。</td></tr>';
showsubmit('instantoperationssubmit','保存运行开关'); showtablefooter(); echo '</form>';
$defaults=null;
try { $defaults=PintogyaActivityInstantConfig::defaultsFromJson($_G['setting'][PintogyaActivityInstantRule::SETTING_KEY] ?? '',$credits); }
catch(Throwable $error) { /* Unconfigured values stay blank, never adopt historical amounts. */ }
$options=array(array(0,'请选择积分字段'));
foreach(range(1,8) as $id) {
    if(isset($credits[$id]) && is_array($credits[$id])) {
        $options[]=array($id,'extcredits'.$id.' - '.dhtmlspecialchars((string)($credits[$id]['title'] ?? '')));
    }
}
$formStart(); showtableheader('两类即时奖励：未来活动默认值');
showsetting('奖励积分字段',array('instant_defaults[credit_id]',$options),$defaults['credit_id'] ?? 0,'select');
showsetting('Finish Story 基础奖励','instant_defaults[base]',$defaults['base'] ?? '','text',0,0,'每位参与者首次完成 Finish Story 打卡后一次到账。0 表示该项不发积分。');
showsetting('普通阶段默认奖励','instant_defaults[stage_default]',$defaults['stage_default'] ?? '','text',0,0,'每个普通阶段分别首次完成后到账；未发布草稿可设置不同阶段金额。');
showsubmit('instantdefaultssubmit','保存未来活动默认值'); showtablefooter(); echo '</form>';

$ratingLimit=$_G['setting'][PintogyaActivityInstantConfig::RATING_LIMIT_KEY] ?? (string)PintogyaActivityInstantConfig::DEFAULT_RATING_LIMIT;
$formStart(); showtableheader('评分防放大设置（当前评分加成关闭）');
showsetting('评分总额上限','activity_rating_total_cap',$ratingLimit,'text',0,0,
    '默认 200 Pin。按同一活动内任意参与者历来获得的评分加成计数；任意一人达到上限后，全体停止新增评分奖励，仍可继续评分。晚加入者不补齐到上限，撤销评分不释放额度。阶段和 Finish Story 奖励不计入；0 表示不允许评分加成。当前新版不发评分加成，保存此值不会将其开启。');
showsubmit('instantratinglimitsubmit','保存评分总额上限'); showtablefooter(); echo '</form>';

$selection=$selection ?? null;
if($selection === null) {
    try {
        $raw=$_G['setting'][PintogyaActivityInstantConfig::OFFICIAL_KEY] ?? '';
        $value=is_string($raw) ? json_decode($raw,true) : null;
        $uid=is_array($value) && is_int($value['publisher_uid'] ?? null) ? $value['publisher_uid'] : 0;
        $member=$uid>0 ? DB::fetch_first('SELECT uid FROM %t WHERE uid=%d',array('common_member',$uid)) : null;
        $selection=PintogyaActivityInstantConfig::officialSelectionsFromJson($raw,$member);
    } catch(Throwable $error) { $selection=array('activity_ids'=>array(),'publisher_uid'=>''); }
}
$officialOptions=array();
foreach(PintogyaActivityOfficialExamples::choices() as $id=>$choice) { $officialOptions[]=array($id,$choice['label']); }
$formStart(); showtableheader('官方示例：多选与本站发布会员');
// TOGYA_PLUGIN pintogya_official_multiselect: reuse the native DX checkbox selector.
showsetting('官方示例（可多选）',array('official_example[activity_ids]',$officialOptions),$selection['activity_ids'],'mcheckbox',0,0,
    '至少选择一项，可同时选择两项或三项。保存只更新草稿；预览和发布逐项处理。已发布示例只读回原帖，不覆盖或重复创建。');
showsetting('本站发布会员 UID','official_example[publisher_uid]',$selection['publisher_uid'],'text',0,0,'只作为论坛发布者。不能代替 Togya 创建者或参与身份；发布前必须复核 DX 原生版块及用户组权限。');
showsubmit('instantofficialsubmit','保存示例草稿');
showsubmit('instantpreviewsubmit','读取公开活动预览');
showsubmit('instantpermissionsubmit','只核对发帖权限（不发布）');
showsubmit('instantpublishsubmit','按指定会员发布所选官方示例');
showtablefooter(); echo '</form>';
if($publicationResults !== null) {
    showtableheader('官方示例发布结果（逐项）');
    foreach($publicationResults as $id=>$published) {
        echo '<tr><th>'.dhtmlspecialchars(PintogyaActivityOfficialExamples::choices()[$id]['label']).'</th><td>';
        if($published['status']==='published') {
            echo ($published['publication']===-1 ? '已提交 DX 原生审核' : ($published['created'] ? '已发布' : '已发布，读回原帖')).
                '；<a href="forum.php?mod=viewthread&amp;tid='.(int)$published['tid'].'" target="_blank" rel="noopener noreferrer">查看主题 TID '.(int)$published['tid'].'</a>';
        } else { echo dhtmlspecialchars($published['message']); }
        echo '</td></tr>';
    }
    echo '<tr><td colspan="2">每项沿用原生权限和独立发布事务；未报名或发放新活动奖励，未覆盖既有主题或金额。</td></tr>';
    showtablefooter();
}
foreach($previews as $id=>$item) {
    if(isset($item['error'])) {
        echo '<h3>'.dhtmlspecialchars(PintogyaActivityOfficialExamples::choices()[$id]['label']).'</h3><p role="status">'.dhtmlspecialchars($item['error']).'</p>';
        continue;
    }
    $preview=$item['preview'];
    echo '<h3>'.dhtmlspecialchars($preview['title']).'</h3><p>'.nl2br(dhtmlspecialchars($preview['summary'])).'</p>';
    if(!in_array('finish_story',array_column($preview['stages'],'kind'),true)) {
        echo '<p role="status">该来源尚无 Finish Story 阶段，基础奖励事实待来源支持；不能把最后一个普通阶段当作 Finish Story。</p>';
    }
    echo '<p>分类：'.dhtmlspecialchars($preview['category']).'；模式：'.dhtmlspecialchars($preview['mode']).
        '；节奏：'.dhtmlspecialchars($preview['paceMode']).'；最短完成间隔：'.(int)$preview['minimumCompletionIntervalSeconds'].' 秒</p><ol>';
    foreach($preview['stages'] as $stage) {
        echo '<li><h4>'.dhtmlspecialchars($stage['title']).'</h4>';
        echo '<p>阶段类型：'.dhtmlspecialchars($stage['kind']).'</p></li>';
    }
    echo '</ol><p>预览仅验证公开活动和完整阶段元数据，不复制受限正文。发布按本站 DX 权限创建主题，冻结阶段身份及金额；帖子中的说明和打卡按每位浏览者本人的 Togya 权限动态读取。参与及奖励不随发布自动产生。</p>';
}

showtableheader('已有两类奖励规则（只可编辑未发布草稿）');
if(!PintogyaActivityInstantRuleStore::installed()) {
    echo '<tr><td>0010 新规则表尚未安装为 InnoDB。配置默认值不建表；旧六任务不受影响。</td></tr>';
} else {
    $rows=DB::fetch_all('SELECT * FROM '.DB::table('pintogya_activity_instant_rule').' ORDER BY created_at DESC,activity_id LIMIT 20');
    foreach($rows as $row) {
        try {
            $rule=PintogyaActivityInstantRule::assertSnapshot(json_decode($row['rule_json'],true,512,JSON_THROW_ON_ERROR));
            if($rule['activity_id'] !== $row['activity_id'] || !hash_equals(PintogyaCrypto::digest('pintogya_instant_rule_v3',$rule),$row['rule_hash'])) {
                throw new RuntimeException('instant_rule_corrupt');
            }
        } catch(Throwable $error) { echo '<tr><td>规则内容异常，不能编辑或发奖。</td></tr>'; continue; }
        echo '<tr><td>活动 '.dhtmlspecialchars($row['activity_id']).'；每人总奖励 '.(int)$rule['total'].' '.
            dhtmlspecialchars(strip_tags((string)($credits[$rule['credit_id']]['title'] ?? '积分字段未配置'))).'；'.
            ((int)$row['published_at']>0 ? '已发布（规则锁定）' : '未发布草稿').'</td></tr>';
        if((int)$row['published_at']>0 || !in_array($row['state_code'],array('active','held'),true)) { continue; }
        echo '<tr><td>'; $formStart();
        echo '<input type="hidden" name="instant_activity_id" value="'.dhtmlspecialchars($row['activity_id']).'" />';
        showtableheader('未发布草稿金额');
        showsetting('Finish Story 基础奖励','instant_draft[base]',$rule['base'],'text');
        $stages=array_values($rule['stages']); usort($stages,static function($a,$b) { return $a['position']<=>$b['position']; });
        foreach($stages as $stage) {
            if($stage['kind']==='tutorial') { showsetting(dhtmlspecialchars($stage['title']),
                'instant_draft[stages]['.$stage['id'].']',$stage['amount'],'text'); }
        }
        showsubmit('instantdraftsubmit','保存该草稿阶段金额'); showtablefooter(); echo '</form></td></tr>';
    }
    if(!$rows) { echo '<tr><td>当前尚无两类奖励规则。保存未来默认值不会迁移旧活动。</td></tr>'; }
}
showtablefooter();
return true;
