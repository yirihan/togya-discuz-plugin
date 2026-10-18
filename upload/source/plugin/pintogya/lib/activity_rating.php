<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/repository.php';
require_once __DIR__.'/activity_native_post.php';
require_once __DIR__.'/activity_reward_progress.php';

final class PintogyaActivityRating {
    public static function usesLegacyRewardPool($tid) {
        self::integer($tid, 1, 4294967295);
        // TOGYA_PLUGIN pintogya_instant_native_rating: an instant rule never routes into the retired shared pool.
        $instant=DB::fetch_first('SELECT r.activity_id FROM '.DB::table('pintogya_activity').' a JOIN '
            .DB::table('pintogya_activity_instant_rule').' r ON r.activity_id=a.activity_id'
            .' WHERE a.tid='.$tid.' LIMIT 1');
        return !$instant;
    }

    public static function record($tid, $pid, $uid, $username, $creditId, $score, $ratetimes, $reason, $now) {
        global $_G;
        self::integer($tid, 1, 4294967295);
        self::integer($pid, 1, 4294967295);
        self::integer($uid, 1, 16777215);
        self::integer($creditId, 1, 8);
        self::integer($score, 1, 2147483647);
        self::integer($ratetimes, 1, 2147483647);
        self::integer($now, 1, 4294967295);
        if((string)($_G['setting']['pintogya_activity_settlement_enabled'] ?? '0') !== '1') {
            throw new RuntimeException('activity_rating_settlement_disabled');
        }
        if(!is_string($username) || $username === '' || !is_string($reason) || strlen($reason) > 160 ||
            !isset($_G['group']['raterange'][$creditId]) ||
            (int)($_G['group']['raterange'][$creditId]['isself'] ?? -1) !== 0) {
            throw new RuntimeException('activity_rating_input_invalid');
        }
        return self::transaction(function() use ($tid, $pid, $uid, $username, $creditId, $score, $ratetimes, $reason, $now, &$_G) {
            $repo = new PintogyaRepository();
            $context = self::lockedContext($repo, $tid, $pid);
            if((int)$context['post']['authorid'] === $uid) { throw new RuntimeException('activity_rating_self_forbidden'); }
            $pool = $context['pool'];
            $activityPk = (int)$context['activity']['activity_pk'];
            $existing = DB::fetch_first('SELECT rating_pk FROM '.DB::table('pintogya_activity_rating')
                .' WHERE activity_pk='.$activityPk.' AND rater_uid='.$uid.' AND credit_id='.$creditId.' LIMIT 1 FOR UPDATE');
            if($existing) { throw new RuntimeException('activity_rating_duplicate'); }
            $plan = PintogyaActivityRewardProgress::planRatingIncrease($repo, $pool, $activityPk,
                $context['activity']['activity_id'], $_G['setting']['extcredits'] ?? array(), $creditId, $score);
            $ratingPk = DB::insert('pintogya_activity_rating', array(
                'activity_pk' => $activityPk, 'tid' => $tid, 'pid' => $pid,
                'rater_uid' => $uid, 'credit_id' => $creditId, 'score' => $score,
                'ratetimes' => $ratetimes, 'ratelog_dateline' => $now,
                'state_code' => 'active', 'created_at' => $now, 'reversed_at' => 0,
            ), true);
            self::integer((int)$ratingPk, 1, 4294967295);
            DB::insert('forum_ratelog', array('pid' => $pid, 'uid' => $uid,
                'username' => $username, 'extcredits' => $creditId, 'dateline' => $now,
                'score' => $score, 'reason' => $reason));
            self::affectedOne('activity_rating_log_insert_failed');
            self::updatePostAndThread($context, $score, $ratetimes);
            PintogyaActivityRewardProgress::recordRatingChange($repo, $plan, $now);
            return array('state' => 'rated', 'rating_pk' => (int)$ratingPk,
                'target_amount' => $plan['target_after']);
        });
    }

    public static function reverseSelected($tid, $pid, $keys, $now) {
        self::integer($tid, 1, 4294967295);
        self::integer($pid, 1, 4294967295);
        self::integer($now, 1, 4294967295);
        if(!is_array($keys) || !$keys || count($keys) > 30) {
            throw new RuntimeException('activity_rating_selection_invalid');
        }
        $selected = array();
        foreach($keys as $key) {
            if(!is_string($key) || !preg_match('/^([1-9][0-9]{0,7}) ([1-8]) ([1-9][0-9]{0,9})$/D', $key, $matches)) {
                throw new RuntimeException('activity_rating_selection_invalid');
            }
            $selected[$key] = array((int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        if(count($selected) !== count($keys)) { throw new RuntimeException('activity_rating_selection_duplicate'); }
        ksort($selected, SORT_STRING);
        return self::transaction(function() use ($tid, $pid, $selected, $now) {
            global $_G;
            $repo = new PintogyaRepository();
            $context = self::lockedContext($repo, $tid, $pid);
            $activityPk = (int)$context['activity']['activity_pk'];
            $pool = $context['pool'];
            $totalScore = 0;
            $totalTimes = 0;
            $rows = array();
            foreach($selected as $key => $parts) {
                list($uid, $creditId, $dateline) = $parts;
                $row = DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_rating')
                    .' WHERE activity_pk='.$activityPk.' AND rater_uid='.$uid
                    .' AND credit_id='.$creditId.' LIMIT 1 FOR UPDATE');
                if(!$row || $row['state_code'] !== 'active' || (int)$row['tid'] !== $tid ||
                    (int)$row['pid'] !== $pid || (int)$row['ratelog_dateline'] !== $dateline ||
                    $creditId !== (int)$pool['credit_id']) {
                    throw new RuntimeException('activity_rating_contribution_mismatch');
                }
                $score = self::integer((int)$row['score'], 1, 2147483647);
                $times = self::integer((int)$row['ratetimes'], 1, 2147483647);
                $native = DB::fetch_all('SELECT pid,uid,extcredits,dateline,score FROM '.DB::table('forum_ratelog')
                    .' WHERE pid='.$pid.' AND uid='.$uid.' AND extcredits='.$creditId
                    .' AND dateline='.$dateline.' LIMIT 2');
                if(count($native) !== 1 || (int)$native[0]['score'] !== $score) {
                    throw new RuntimeException('activity_rating_native_log_mismatch');
                }
                if($totalScore > 2147483647 - $score || $totalTimes > 2147483647 - $times) {
                    throw new RuntimeException('activity_rating_sum_overflow');
                }
                $totalScore += $score;
                $totalTimes += $times;
                $rows[] = array('pk' => (int)$row['rating_pk'], 'uid' => $uid,
                    'credit_id' => $creditId, 'dateline' => $dateline);
            }
            $plan = PintogyaActivityRewardProgress::planRatingReversal($repo, $pool, $activityPk,
                $context['activity']['activity_id'], $_G['setting']['extcredits'] ?? array(),
                (int)$pool['credit_id'], $totalScore);
            foreach($rows as $row) {
                DB::query('DELETE FROM '.DB::table('forum_ratelog').' WHERE pid='.$pid.
                    ' AND uid='.$row['uid'].' AND extcredits='.$row['credit_id'].
                    ' AND dateline='.$row['dateline']);
                self::affectedOne('activity_rating_native_log_delete_failed');
                DB::update('pintogya_activity_rating', array('state_code' => 'reversed', 'reversed_at' => $now),
                    'rating_pk='.$row['pk']." AND state_code='active'");
                self::affectedOne('activity_rating_reversal_write_failed');
            }
            self::updatePostAndThread($context, -$totalScore, -$totalTimes);
            PintogyaActivityRewardProgress::recordRatingChange($repo, $plan, $now);
            return array('state' => 'reversed', 'count' => count($rows), 'target_amount' => $plan['target_after']);
        });
    }

    private static function lockedContext($repo, $tid, $pid) {
        $activity = $repo->activityByTid($tid, true);
        if(!$activity || $activity['state_code'] !== 'active') {
            throw new RuntimeException('activity_rating_activity_unavailable');
        }
        $thread = DB::fetch_first('SELECT tid,fid,authorid,special,displayorder,rate FROM '
            .DB::table('forum_thread').' WHERE tid='.$tid.' LIMIT 1 FOR UPDATE');
        if(!$thread || (int)$thread['special'] !== 127 || (int)$thread['fid'] !== (int)$activity['fid'] ||
            (int)$thread['authorid'] !== (int)$activity['owner_uid'] || (int)$thread['displayorder'] < 0) {
            throw new RuntimeException('activity_rating_thread_mismatch');
        }
        $postTable = table_forum_post::get_tablename('tid:'.$tid);
        if(!is_string($postTable) || !preg_match('/^[A-Za-z0-9_]+$/D', $postTable)) {
            throw new RuntimeException('activity_rating_post_table_invalid');
        }
        $post = DB::fetch_first('SELECT pid,tid,first,authorid,invisible,message,rate,ratetimes FROM '
            .$postTable.' WHERE pid='.$pid.' LIMIT 1 FOR UPDATE');
        if(!$post || (int)$post['tid'] !== $tid || (int)$post['first'] !== 1 ||
            (int)$post['authorid'] !== (int)$activity['owner_uid'] || (int)$post['invisible'] !== 0 ||
            !is_string($post['message']) ||
            $post['message'] !== PintogyaActivityNativePost::messageForDigest((string)$activity['reservation_digest'])) {
            throw new RuntimeException('activity_rating_post_mismatch');
        }
        $pool = $repo->activityRewardPoolByActivity((int)$activity['activity_pk'], true);
        if(!$pool) { throw new RuntimeException('activity_rating_pool_missing'); }
        return array('activity' => $activity, 'thread' => $thread, 'post' => $post,
            'post_table' => $postTable, 'pool' => $pool);
    }

    private static function updatePostAndThread($context, $scoreDelta, $timesDelta) {
        $post = $context['post'];
        $rateBefore = (int)$post['rate'];
        $timesBefore = (int)$post['ratetimes'];
        $rateAfter = $rateBefore + $scoreDelta;
        $timesAfter = $timesBefore + $timesDelta;
        if($timesAfter < 0 || $rateAfter < -2147483648 || $rateAfter > 2147483647 ||
            $timesAfter > 2147483647) { throw new RuntimeException('activity_rating_post_overflow'); }
        DB::query('UPDATE '. $context['post_table'].' SET rate='.$rateAfter.',ratetimes='.$timesAfter.
            ' WHERE pid='.(int)$post['pid'].' AND rate='.$rateBefore.' AND ratetimes='.$timesBefore);
        self::affectedOne('activity_rating_post_write_failed');
        $threadRate = $rateAfter <=> 0;
        if((int)$context['thread']['rate'] !== $threadRate) {
            DB::update('forum_thread', array('rate' => $threadRate),
                'tid='.(int)$context['thread']['tid'].' AND rate='.(int)$context['thread']['rate']);
            self::affectedOne('activity_rating_thread_write_failed');
        }
    }

    private static function transaction($work) {
        if(DB::query('START TRANSACTION') === false) { throw new RuntimeException('activity_rating_begin_failed'); }
        $started = true;
        $committing = false;
        try {
            $result = $work();
            $committing = true;
            if(DB::query('COMMIT') === false) { throw new RuntimeException('activity_rating_commit_failed'); }
            $started = false;
            return $result;
        } catch(Throwable $error) {
            if($committing) { throw new RuntimeException('activity_rating_commit_outcome_unknown', 0, $error); }
            if($started && DB::query('ROLLBACK') === false) {
                throw new RuntimeException('activity_rating_rollback_outcome_unknown', 0, $error);
            }
            throw $error;
        }
    }

    private static function affectedOne($error) {
        if(DB::affected_rows() !== 1) { throw new RuntimeException($error); }
    }

    private static function integer($value, $min, $max) {
        if(!is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException('activity_rating_integer_invalid');
        }
        return $value;
    }
}
