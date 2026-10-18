<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityAdminLedger {
    private const PAGE_SIZE = 20;

    public static function render($baseUrl, $params) {
        if(!is_string($baseUrl) || !is_array($params)) {
            throw new InvalidArgumentException('activity_ledger_input_invalid');
        }
        $html = '<h3>活动账本（只读）</h3><p>账面差额不是付款指令；只有审核、来源和结算条件全部通过后才可发放。此页不修改审核、积分或流水。</p>';
        if(!self::exists('pintogya_activity')) {
            return $html.'<p>活动映射表尚未安装。</p>';
        }
        $activityPk = self::id($params, 'activity_pk');
        if($activityPk === 0) {
            $before = self::id($params, 'before');
            $sql = 'SELECT activity_pk,activity_id,tid,fid,state_code,stage_version,published_at FROM %t';
            $args = array('pintogya_activity');
            if($before > 0) { $sql .= ' WHERE activity_pk<%d'; $args[] = $before; }
            $rows = DB::fetch_all($sql.' ORDER BY activity_pk DESC LIMIT 21', $args);
            $more = count($rows) > self::PAGE_SIZE;
            $rows = array_slice($rows, 0, self::PAGE_SIZE);
            $html .= '<table class="tb tb2"><tr><th>活动</th><th>TID</th><th>版块</th><th>状态</th><th>阶段版本</th><th>发布时间</th></tr>';
            foreach($rows as $row) {
                $id = (int)$row['activity_pk'];
                $html .= '<tr><td><a href="'.self::link($baseUrl, array('activity_pk' => $id)).'">'.$id.'</a></td>'
                    .'<td>'.(int)$row['tid'].'</td><td>'.(int)$row['fid'].'</td><td>'.self::e($row['state_code']).'</td>'
                    .'<td>'.(int)$row['stage_version'].'</td><td>'.self::time($row['published_at']).'</td></tr>';
            }
            $html .= '</table>';
            if(!$rows) { $html .= '<p>没有活动记录。</p>'; }
            if($more) { $html .= '<p><a href="'.self::link($baseUrl, array('before' => (int)end($rows)['activity_pk'])).'">下一页</a></p>'; }
            return $html;
        }
        $activity = DB::fetch_first('SELECT activity_pk,activity_id,tid,fid,state_code,stage_version FROM %t WHERE activity_pk=%d',
            array('pintogya_activity', $activityPk));
        if(!$activity) { return $html.'<p>活动不存在。</p>'; }
        $html .= '<p><a href="'.self::link($baseUrl).'">返回活动列表</a></p>';
        $html .= '<h4>活动 '.(int)$activity['activity_pk'].' / TID '.(int)$activity['tid'].'</h4>'
            .'<p>来源 ID：'.self::e($activity['activity_id']).'；版块：'.(int)$activity['fid']
            .'；状态：'.self::e($activity['state_code']).'；阶段版本：'.(int)$activity['stage_version'].'</p>';
        if(!self::exists('pintogya_activity_reward_pool') ||
            !self::exists('pintogya_activity_reward_member') ||
            !self::exists('pintogya_activity_reward_payment')) {
            return $html.'<p>奖励账本表未全部安装，不能显示权益或流水。</p>';
        }
        $pool = DB::fetch_first('SELECT rule_pk,rule_hash,credit_id,joined_count,completed_count,score_total,target_amount,paid_total,state_code FROM %t WHERE activity_pk=%d',
            array('pintogya_activity_reward_pool', $activityPk));
        if(!$pool) { return $html.'<p>团账本缺失，需先核对发布状态；此页不会创建或修复。</p>'; }
        $html .= '<h4>团账本</h4><p>规则 #'.(int)$pool['rule_pk'].'（'.self::e(substr((string)$pool['rule_hash'], 0, 12))
            .'…）；积分字段 #'.(int)$pool['credit_id'].'；报名 '.(int)$pool['joined_count'].'；确认完成 '
            .(int)$pool['completed_count'].'；评分加成 '.(int)$pool['score_total'].'；每位完成人当前目标 '
            .(int)$pool['target_amount'].'；全团已付 '.(int)$pool['paid_total'].'；状态 '
            .self::e($pool['state_code']).'</p>';
        $rewardPk = self::id($params, 'reward_pk');
        if($rewardPk > 0) {
            $member = DB::fetch_first('SELECT reward_pk,uid,target_amount,paid_amount,state_code FROM %t WHERE reward_pk=%d AND activity_pk=%d',
                array('pintogya_activity_reward_member', $rewardPk, $activityPk));
            if(!$member) { return $html.'<p>该活动没有这条会员权益。</p>'; }
            $html .= self::payments($baseUrl, $activityPk, $member, $params);
        } else {
            $html .= self::members($baseUrl, $activityPk, $params);
        }
        $html .= self::reviews($baseUrl, $activityPk, $params);
        return $html;
    }

    private static function members($baseUrl, $activityPk, $params) {
        $after = self::id($params, 'member_after');
        $rows = DB::fetch_all('SELECT reward_pk,uid,rule_hash,decision_digest,receipt_state,state_code,target_amount,paid_amount,approved_at FROM %t WHERE activity_pk=%d AND reward_pk>%d ORDER BY reward_pk LIMIT 21',
            array('pintogya_activity_reward_member', $activityPk, $after));
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $html = '<h4>会员权益</h4><table class="tb tb2"><tr><th>权益</th><th>UID</th><th>应得</th><th>已付</th><th>账面差额</th><th>资格</th><th>回执</th><th>规则版本</th><th>批准时间</th></tr>';
        foreach($rows as $row) {
            $due = max(0, (int)$row['target_amount'] - (int)$row['paid_amount']);
            $html .= '<tr><td><a href="'.self::link($baseUrl, array('activity_pk' => $activityPk,
                'reward_pk' => (int)$row['reward_pk'])).'">'.(int)$row['reward_pk'].'</a></td><td>'.(int)$row['uid']
                .'</td><td>'.(int)$row['target_amount'].'</td><td>'.(int)$row['paid_amount'].'</td><td>'.$due
                .'</td><td>'.self::e($row['state_code']).'</td><td>'.self::e($row['receipt_state'])
                .'</td><td>'.self::e(substr((string)$row['rule_hash'], 0, 12)).'…</td><td>'
                .self::time($row['approved_at']).'</td></tr>';
        }
        $html .= '</table>';
        if(!$rows) { $html .= '<p>没有会员权益。</p>'; }
        if($more) { $html .= '<p><a href="'.self::link($baseUrl, array('activity_pk' => $activityPk,
            'member_after' => (int)end($rows)['reward_pk'])).'">下一页</a></p>'; }
        return $html;
    }

    private static function payments($baseUrl, $activityPk, $member, $params) {
        $before = self::id($params, 'payment_before');
        $sql = 'SELECT payment_pk,operation,amount,paid_total_after,credit_log_id,state_code,created_at,posted_at FROM %t WHERE reward_pk=%d';
        $args = array('pintogya_activity_reward_payment', (int)$member['reward_pk']);
        if($before > 0) { $sql .= ' AND paid_total_after<%d'; $args[] = $before; }
        $rows = DB::fetch_all($sql.' ORDER BY paid_total_after DESC LIMIT 21', $args);
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $html = '<h4>权益 #'.(int)$member['reward_pk'].' 的支付流水</h4><p>UID '.(int)$member['uid']
            .'；应得 '.(int)$member['target_amount'].'；已付 '.(int)$member['paid_amount']
            .'；账面差额 '.max(0, (int)$member['target_amount'] - (int)$member['paid_amount'])
            .'；资格 '.self::e($member['state_code']).'</p>'
            .'<table class="tb tb2"><tr><th>流水</th><th>操作</th><th>本次金额</th><th>累计已付</th><th>原生积分 logid</th><th>状态</th><th>入账时间</th></tr>';
        foreach($rows as $row) {
            $html .= '<tr><td>'.(int)$row['payment_pk'].'</td><td>'.self::e($row['operation'])
                .'</td><td>'.(int)$row['amount'].'</td><td>'.(int)$row['paid_total_after']
                .'</td><td>'.($row['credit_log_id'] === null ? '—' : (int)$row['credit_log_id'])
                .'</td><td>'.self::e($row['state_code']).'</td><td>'.self::time($row['posted_at']).'</td></tr>';
        }
        $html .= '</table>';
        if(!$rows) { $html .= '<p>没有支付流水。</p>'; }
        if($more) { $html .= '<p><a href="'.self::link($baseUrl, array('activity_pk' => $activityPk,
            'reward_pk' => (int)$member['reward_pk'], 'payment_before' => (int)end($rows)['paid_total_after'])).'">下一页</a></p>'; }
        return $html;
    }

    private static function reviews($baseUrl, $activityPk, $params) {
        if(!self::exists('pintogya_activity_review_case')) {
            return '<h4>审核</h4><p>审核表尚未安装。</p>';
        }
        $state = $params['review_state'] ?? 'pending';
        if(!is_string($state) || !in_array($state, array('pending', 'approved', 'rejected', 'invalid'), true)) {
            throw new InvalidArgumentException('activity_ledger_review_state_invalid');
        }
        $after = self::id($params, 'review_after');
        $rows = DB::fetch_all('SELECT review_pk,uid,kind,source_ref,stage_version,submitted_at,auto_due_at,state_code,decision_source,decided_at FROM %t WHERE activity_pk=%d AND state_code=%s AND review_pk>%d ORDER BY review_pk LIMIT 21',
            array('pintogya_activity_review_case', $activityPk, $state, $after));
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $html = '<h4>审核记录（只读）</h4><p>';
        foreach(array('pending' => '待审', 'approved' => '已通过', 'rejected' => '已拒绝', 'invalid' => '已失效') as $code => $label) {
            $html .= '<a href="'.self::link($baseUrl, array('activity_pk' => $activityPk,
                'review_state' => $code)).'">'.$label.'</a> ';
        }
        $html .= '</p><table class="tb tb2"><tr><th>审核</th><th>UID</th><th>类型</th><th>来源</th><th>版本</th><th>状态</th><th>决定来源</th><th>提交</th><th>到期</th><th>决定</th></tr>';
        foreach($rows as $row) {
            $html .= '<tr><td>'.(int)$row['review_pk'].'</td><td>'.(int)$row['uid'].'</td><td>'.self::e($row['kind'])
                .'</td><td>'.self::e($row['source_ref']).'</td><td>'.(int)$row['stage_version']
                .'</td><td>'.self::e($row['state_code']).'</td><td>'.self::e($row['decision_source'])
                .'</td><td>'.self::time($row['submitted_at']).'</td><td>'.self::time($row['auto_due_at'])
                .'</td><td>'.self::time($row['decided_at']).'</td></tr>';
        }
        $html .= '</table>';
        if(!$rows) { $html .= '<p>此状态暂无审核记录。</p>'; }
        if($more) { $html .= '<p><a href="'.self::link($baseUrl, array('activity_pk' => $activityPk,
            'review_state' => $state, 'review_after' => (int)end($rows)['review_pk'])).'">下一页</a></p>'; }
        return $html;
    }

    private static function exists($logical) {
        $row = DB::fetch_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            array(DB::table($logical)));
        return $row && strtoupper((string)$row['ENGINE']) === 'INNODB';
    }

    private static function id($params, $key) {
        $raw = $params[$key] ?? '0';
        if(!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $raw) ||
            (string)(int)$raw !== $raw) { throw new InvalidArgumentException('activity_ledger_cursor_invalid'); }
        return (int)$raw;
    }

    private static function link($baseUrl, $args = array()) {
        $url = $baseUrl.'&view=ledger';
        foreach($args as $key => $value) { $url .= '&'.$key.'='.rawurlencode((string)$value); }
        return self::e($url);
    }

    private static function time($timestamp) {
        return (int)$timestamp > 0 ? date('Y-m-d H:i', (int)$timestamp) : '—';
    }

    private static function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
