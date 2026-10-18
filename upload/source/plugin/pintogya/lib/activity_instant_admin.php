<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_settlement.php';

final class PintogyaActivityInstantAdmin {
    private const PAGE_SIZE = 20;
    public const REWARD_STATES = array('eligible'=>'待入账','posted'=>'已记录入账','held_policy'=>'待重新核验首次打卡',
        'held_auth'=>'待原身份授权','no_reward'=>'该项金额为零','quarantined'=>'已隔离');
    public const SYNC_STATES = array('ready'=>'正常轮询','retry'=>'退避重试','blocked_auth'=>'待只读授权',
        'held_source'=>'来源待核验','quarantined'=>'已隔离');

    public static function filters($params) {
        if(!is_array($params)) { throw new InvalidArgumentException('instant_admin_filters_invalid'); }
        $id=$params['activity_id'] ?? '';
        if(!is_string($id)) { throw new InvalidArgumentException('instant_admin_filters_invalid'); }
        if($id !== '') { $id=PintogyaCrypto::assertUuid($id); }
        $filters=array('activity_id'=>$id,'uid'=>self::number($params,'uid',16777215),
            'reward_state'=>self::state($params,'reward_state',self::REWARD_STATES),
            'sync_state'=>self::state($params,'sync_state',self::SYNC_STATES),
            'reward_before'=>self::number($params,'reward_before',4294967295),
            'sync_before'=>self::number($params,'sync_before',4294967295));
        return $filters;
    }

    public static function render($baseUrl,$params) {
        if(!defined('IN_ADMINCP') || IN_ADMINCP !== true || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new RuntimeException('instant_admin_readonly_required');
        }
        $filters=self::filters($params);
        $html='<h3>两类即时奖励对账（只读）</h3><p>每类最多显示20条。只有精确原生主、附流水匹配才显示已核验入账；未发现事实不等于未完成。此页不调用 Togya、不重试任务、不修改余额、权益或流水。</p>';
        $tables=array_map(array('DB','table'),array('pintogya_activity_instant_rule','pintogya_activity_instant_reward','pintogya_activity_instant_sync'));
        $schema=DB::fetch_all('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)',$tables);
        $engines=array();
        foreach($schema as $row) { $engines[$row['TABLE_NAME']]=strtoupper((string)$row['ENGINE']); }
        foreach($tables as $table) {
            if(($engines[$table] ?? '') !== 'INNODB') { return $html.'<p>0010 两类奖励表尚未完整安装为 InnoDB。此页不会建表，也不读取旧奖池。</p>'; }
        }
        $site=PintogyaInstallation::scope();
        list($sql,$args)=self::query('pintogya_activity_instant_reward','reward_pk','reward_state','reward_before',$filters);
        $rewards=DB::fetch_all($sql,$args);
        $html.='<h4>阶段与 Finish Story 基础奖励</h4><table class="tb tb2"><tr><th>权益 / UID</th><th>活动 / 项目</th><th>金额</th><th>首次事实</th><th>规则摘要</th><th>状态 / 原生 logid</th><th>事实 / 发现 / 入账时间</th><th>重试 / 下次处理 / 最近错误</th></tr>';
        foreach(array_slice($rewards,0,self::PAGE_SIZE) as $row) {
            $state=self::REWARD_STATES[$row['state_code']] ?? '状态异常';
            $logId=$row['credit_log_id'] === null ? '—' : (string)(int)$row['credit_log_id'];
            if($row['state_code'] === 'posted') {
                try {
                    if((int)$row['amount'] < 1 || (int)$row['posted_at'] < 1 || (int)$row['credit_log_id'] < 1 ||
                        !is_string($row['native_entry_json']) || strlen($row['native_entry_json']) > 16384 ||
                        !hash_equals(PintogyaActivityInstantRule::rewardKey($site,$row['activity_id'],$row['togya_user_id'],
                            $row['kind'],$row['kind'] === 'stage' ? $row['stage_id'] : null),(string)$row['reward_key'])) {
                        throw new RuntimeException('instant_posted_entry_mismatch');
                    }
                    PintogyaActivityInstantSettlement::verifyPostedReadOnly($row);
                    $state='已核验入账（主、附流水一致）'; $logId=(string)(int)$row['credit_log_id'];
                } catch(Throwable $error) { $state='入账结果待核验（凭证不一致或不可读）'; }
            } elseif($row['credit_log_id'] !== null || (int)$row['posted_at'] !== 0) {
                $state='状态与入账凭证冲突';
            }
            $html.='<tr><td>#'.(int)$row['reward_pk'].' / UID '.(int)$row['uid'].'</td><td>'.self::e($row['activity_id']).'<br />'.
                ($row['kind'] === 'base' ? 'Finish Story 基础奖励' : '阶段 '.self::e($row['stage_id'])).'</td><td>'.
                (int)$row['amount'].' / extcredits'.(int)$row['credit_id'].'</td><td>'.self::fact($row).'</td><td>'.
                (preg_match('/^[0-9a-f]{64}$/D',(string)$row['rule_hash']) ? self::e(substr($row['rule_hash'],0,12)).'…' : '规则摘要异常').
                '</td><td>'.$state.'<br />logid '.$logId.'</td><td>'.self::time($row['occurred_at']).'<br />'.self::time($row['observed_at']).
                '<br />'.self::time($row['posted_at']).'</td><td>'.(int)$row['retry_count'].'<br />'.self::time($row['next_attempt_at']).
                '<br />'.self::error($row['last_error']).'</td></tr>';
        }
        $html.='</table>';
        if(!$rewards) { $html.='<p>本页没有符合条件的已识别权益；不能据此断定 Togya 中没有完成事实。</p>'; }
        if(count($rewards)>self::PAGE_SIZE) {
            $html.='<p><a href="'.self::link($baseUrl,$filters,'reward_before',(int)$rewards[self::PAGE_SIZE-1]['reward_pk']).'">下一页奖励</a></p>';
        }
        list($sql,$args)=self::query('pintogya_activity_instant_sync','sync_pk','sync_state','sync_before',$filters);
        $syncs=DB::fetch_all($sql,$args);
        $html.='<h4>自动发现队列</h4><table class="tb tb2"><tr><th>队列 / UID</th><th>活动</th><th>状态</th><th>最近核对</th><th>重试次数</th><th>下次处理</th><th>最近错误</th></tr>';
        foreach(array_slice($syncs,0,self::PAGE_SIZE) as $row) {
            $html.='<tr><td>#'.(int)$row['sync_pk'].' / UID '.(int)$row['uid'].'</td><td>'.self::e($row['activity_id']).'</td><td>'.
                (self::SYNC_STATES[$row['state_code']] ?? '状态异常').'</td><td>'.self::time($row['checked_at']).'</td><td>'.
                (int)$row['retry_count'].'</td><td>'.self::time($row['next_attempt_at']).'</td><td>'.self::error($row['last_error']).'</td></tr>';
        }
        $html.='</table>';
        if(!$syncs) { $html.='<p>本页没有同步队列记录；未入队不等于已经同步完成。</p>'; }
        if(count($syncs)>self::PAGE_SIZE) {
            $html.='<p><a href="'.self::link($baseUrl,$filters,'sync_before',(int)$syncs[self::PAGE_SIZE-1]['sync_pk']).'">下一页队列</a></p>';
        }
        return $html;
    }

    private static function query($table,$pk,$state,$cursor,$filters) {
        $where=array(); $args=array($table);
        foreach(array('activity_id'=>'%s','uid'=>'%d',$state=>'%s',$cursor=>'%d') as $key=>$format) {
            if($filters[$key] === '' || $filters[$key] === 0) { continue; }
            $column=$key === $state ? 'state_code' : ($key === $cursor ? $pk : $key);
            $where[]=$column.($key === $cursor ? '<' : '=').$format; $args[]=$filters[$key];
        }
        // Only local reward rows contain the private verification snapshot; it is never rendered.
        $columns=$pk === 'reward_pk' ? '*' : 'sync_pk,activity_id,uid,state_code,checked_at,retry_count,next_attempt_at,last_error';
        return array('SELECT '.$columns.' FROM %t'.($where ? ' WHERE '.implode(' AND ',$where) : '').
            ' ORDER BY '.$pk.' DESC LIMIT 21',$args);
    }

    private static function fact($row) {
        try {
            if(!is_string($row['fact_json']) || strlen($row['fact_json'])>4096) { throw new RuntimeException('fact_invalid'); }
            $fact=json_decode($row['fact_json'],true,32,JSON_THROW_ON_ERROR);
            $id=PintogyaCrypto::assertUuid($fact['checkin_id'] ?? null);
            PintogyaCrypto::assertUuid($fact['stage_id'] ?? null);
            if(($fact['completes_stage'] ?? null) !== true || !is_int($fact['stage_version'] ?? null) ||
                $fact['stage_version']<1 || ($fact['occurred_at'] ?? null) !== (int)$row['occurred_at'] ||
                ($row['kind'] === 'stage' && $fact['stage_id'] !== $row['stage_id'])) { throw new RuntimeException('fact_invalid'); }
            return self::e($id).' / v'.$fact['stage_version'];
        } catch(Throwable $error) { return '事实摘要不可核验'; }
    }

    private static function error($code) {
        $labels=array(''=>'—','instant_partial_history'=>'历史尾段截断；完整阶段继续轮询',
            'instant_base_source_unavailable'=>'来源尚无 Finish Story','instant_read_session_expired'=>'只读授权已过期',
            'instant_read_permission_unavailable'=>'缺少只读权限','api_unauthorized'=>'只读授权被拒绝',
            'binding_inactive'=>'连接已停止','instant_binding_changed'=>'绑定身份已变更','instant_binding_inactive'=>'绑定身份未恢复',
            'instant_journey_order_invalid'=>'来源排序无法核验','instant_stage_plan_changed'=>'来源阶段计划已变更',
            'instant_history_page_invalid'=>'历史分页响应不可核验；停止该来源，不重发已入账奖励',
            'instant_history_order_invalid'=>'历史记录顺序不可核验；停止该来源，不猜测首次打卡',
            'instant_history_cursor_invalid'=>'历史游标不可核验；停止该来源，不重置扫描',
            'instant_history_checkpoint_invalid'=>'本地历史断点不可核验；保留权益，先核对断点记录',
            'instant_source_unavailable_or_changed'=>'来源不可读或已变更','instant_rule_changed_or_held'=>'冻结规则待核验',
            'instant_forum_participation_missing'=>'论坛参与未匹配','instant_activity_unpublished'=>'论坛主题未发布',
            'instant_credit_unconfigured'=>'积分字段未配置','instant_reward_key_mismatch'=>'稳定防重键不匹配',
            'instant_posted_entry_mismatch'=>'原生入账凭证不匹配','credit_log_cardinality_invalid'=>'精确原生流水数量异常',
            'instant_entitlement_conflict'=>'权益记录冲突','instant_credit_balance_mismatch'=>'余额核验不一致',
            'instant_source_temporary_failure'=>'来源临时错误','instant_worker_record_unknown'=>'未分类处理错误');
        return is_string($code) && isset($labels[$code]) ? $labels[$code] : '未分类错误（不展示原始返回）';
    }

    private static function state($params,$key,$allowed) {
        $value=$params[$key] ?? '';
        if(!is_string($value) || ($value !== '' && !isset($allowed[$value]))) { throw new InvalidArgumentException('instant_admin_filters_invalid'); }
        return $value;
    }
    private static function number($params,$key,$max) {
        $value=$params[$key] ?? '0'; if($value === '') { $value='0'; }
        if(!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value) || (int)$value>$max) {
            throw new InvalidArgumentException('instant_admin_filters_invalid');
        }
        return (int)$value;
    }
    private static function link($baseUrl,$filters,$cursor,$value) {
        $filters[$cursor]=$value; $url=$baseUrl;
        foreach($filters as $key=>$item) { if($item !== '' && $item !== 0) { $url.='&'.$key.'='.rawurlencode((string)$item); } }
        return self::e($url);
    }
    private static function time($value) { return (int)$value>0 ? self::e(function_exists('dgmdate') ? dgmdate((int)$value,'dt') : date('Y-m-d H:i:s',(int)$value)) : '—'; }
    private static function e($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
}
