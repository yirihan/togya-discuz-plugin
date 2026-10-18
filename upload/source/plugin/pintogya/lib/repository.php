<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaRepository {
    private $instantRuleAvailable;
    public function begin() { DB::query('START TRANSACTION'); }
    public function commit() { DB::query('COMMIT'); }
    public function rollback() { DB::query('ROLLBACK'); }
    public function currentUnixTime() {
        $row = DB::fetch_first('SELECT UNIX_TIMESTAMP() AS `current_time`');
        if(!$row || !ctype_digit((string)($row['current_time'] ?? ''))) {
            throw new RuntimeException('activity_database_clock_unavailable');
        }
        return (int)$row['current_time'];
    }
    public function memberAdminId($uid, $lock = false) {
        $sql = 'SELECT adminid FROM '.DB::table('common_member').' WHERE uid='.(int)$uid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        $row = DB::fetch_first($sql);
        return $row ? (int)$row['adminid'] : 0;
    }
    public function isForumModerator($fid, $uid, $lock = false) {
        $sql = 'SELECT uid FROM '.DB::table('forum_moderator').' WHERE fid='.(int)$fid.
            ' AND uid='.(int)$uid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return (bool)DB::fetch_first($sql);
    }
    public function esc($value) { return function_exists('daddslashes') ? daddslashes((string)$value) : addslashes((string)$value); }

    public function acquireUidLock($uid) {
        $name = 'pintogya:uid:'.intval($uid);
        $row = DB::fetch_first("SELECT GET_LOCK('".$this->esc($name)."', 5) AS acquired");
        if(!$row || intval($row['acquired']) !== 1) { throw new RuntimeException('uid_lock_unavailable'); }
        return $name;
    }

    public function releaseUidLock($name) {
        if($name !== '') { DB::fetch_first("SELECT RELEASE_LOCK('".$this->esc($name)."') AS released"); }
    }

    public function assertCurrentTaskInstance($uid, $taskId, $taskInstance) {
        if(intval($taskId) === 0) {
            if(!in_array($taskInstance, array('account', 'activity-publish'), true)) {
                if(!is_string($taskInstance) || (!preg_match('/^activity-join-[0-9a-f]{32}$/D', $taskInstance) &&
                    !preg_match('/^account-unlink-g[1-9][0-9]{0,9}$/D', $taskInstance))) {
                    throw new RuntimeException('task_instance_mismatch');
                }
            }
            return true;
        }
        if(!preg_match('/^[1-9][0-9]{0,10}$/D', (string)$taskInstance)) { throw new RuntimeException('task_instance_mismatch'); }
        $row = DB::fetch_first('SELECT COUNT(*) AS total FROM '.DB::table('common_mytask').' WHERE uid='.intval($uid).' AND taskid='.intval($taskId).' AND dateline='.intval($taskInstance).' AND status=0');
        if(!$row || intval($row['total']) !== 1) { throw new RuntimeException('task_instance_mismatch'); }
        return true;
    }

    public function latestChallenge($uid, $taskId, $taskInstance, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_challenge').' WHERE uid='.intval($uid).' AND task_id='.intval($taskId)
            ." AND task_instance='".$this->esc($taskInstance)."' ORDER BY challenge_pk DESC LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function challengeById($challengeId, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_challenge')." WHERE challenge_id='".$this->esc($challengeId)."'";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function supersedePendingChallenges($uid, $taskId, $taskInstance, $now) {
        DB::update('pintogya_challenge', array(
            'state_code' => 'superseded', 'verifier_ciphertext' => '', 'updated_at' => intval($now),
        ), 'uid='.intval($uid).' AND task_id='.intval($taskId)." AND task_instance='".$this->esc($taskInstance)."' AND state_code='pending'");
    }

    public function supersedeAllChallengesForUid($uid, $now) {
        DB::update('pintogya_challenge', array(
            'state_code' => 'superseded', 'verifier_ciphertext' => '', 'updated_at' => intval($now),
        ), 'uid='.intval($uid)." AND state_code IN ('pending','approved')");
    }

    public function insertChallenge($row) {
        DB::insert('pintogya_challenge', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('challenge_insert_failed'); }
    }

    public function updateChallenge($challengeId, $values, $expectedState = '') {
        $where = "challenge_id='".$this->esc($challengeId)."'";
        if($expectedState !== '') { $where .= " AND state_code='".$this->esc($expectedState)."'"; }
        DB::update('pintogya_challenge', $values, $where);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('challenge_state_changed'); }
    }

    public function activeBindingForUid($uid, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_binding').' WHERE uid='.intval($uid)." AND state_code='active' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function bindingForUid($uid, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_binding').' WHERE uid='.intval($uid).' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function bindingForTogyaUser($togyaUserId, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_binding')." WHERE togya_user_id='".$this->esc($togyaUserId)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function createBinding($values) {
        DB::insert('pintogya_binding', $values, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('binding_insert_failed'); }
    }

    public function updateBinding($uid, $values, $expectedGeneration = null) {
        $where = 'uid='.intval($uid);
        if($expectedGeneration !== null) { $where .= ' AND generation='.intval($expectedGeneration); }
        DB::update('pintogya_binding', $values, $where);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('binding_state_changed'); }
    }

    public function revokeBinding($uid, $now) {
        DB::update('pintogya_binding', array(
            'state_code' => 'revoked', 'access_ciphertext' => '',
            'access_expires_at' => 0, 'revoked_at' => intval($now), 'updated_at' => intval($now),
        ), 'uid='.intval($uid)." AND state_code='active'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('binding_inactive'); }
    }

    public function finishBindingRevoke($uid, $generation, $now) {
        DB::update('pintogya_binding', array('refresh_ciphertext' => '', 'updated_at' => intval($now)),
            'uid='.intval($uid).' AND generation='.intval($generation)." AND state_code='revoked' AND refresh_ciphertext<>''");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('binding_revoke_state_changed'); }
    }

    public function deactivateActivityForUidIfInstalled($uid, $now) {
        $tables = array('pintogya_activity_action', 'pintogya_participation');
        $found = array();
        foreach($tables as $logical) {
            $table = DB::table($logical);
            $row = DB::fetch_first('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', array($table));
            $found[] = (bool)$row;
        }
        if($found[0] !== $found[1]) { throw new RuntimeException('activity_schema_incomplete'); }
        if(!$found[0]) { return; }
        DB::update('pintogya_activity_action', array('state_code' => 'invalid', 'updated_at' => intval($now)),
            'uid='.intval($uid)." AND state_code='prepared'");
        DB::update('pintogya_participation', array('state_code' => 'inactive', 'updated_at' => intval($now)),
            'uid='.intval($uid)." AND state_code='active'");
    }

    public function context($uid, $taskId, $taskInstance, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_task_context').' WHERE uid='.intval($uid).' AND task_id='.intval($taskId)
            ." AND task_instance='".$this->esc($taskInstance)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function storeContext($row) {
        DB::insert('pintogya_task_context', $row, false, true);
    }

    public function cleanupPreparedContexts($uid, $before) {
        DB::query('DELETE FROM '.DB::table('pintogya_task_context').' WHERE uid='.intval($uid)." AND state_code='prepared' AND updated_at<".intval($before).' LIMIT 20');
    }

    public function updateContext($uid, $taskId, $taskInstance, $values, $expectedState = '') {
        $where = 'uid='.intval($uid).' AND task_id='.intval($taskId)." AND task_instance='".$this->esc($taskInstance)."'";
        if($expectedState !== '') { $where .= " AND state_code='".$this->esc($expectedState)."'"; }
        DB::update('pintogya_task_context', $values, $where);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('task_context_state_changed'); }
    }

    public function completeAndRekeyContext($uid, $taskId, $taskInstance, $completedInstance) {
        if(!preg_match('/^[1-9][0-9]{0,10}$/D', (string)$completedInstance)) { throw new InvalidArgumentException('completed_instance_invalid'); }
        DB::update('pintogya_task_context', array(
            'task_instance' => (string)$completedInstance,
            'state_code' => 'completed',
            'updated_at' => time(),
        ), 'uid='.intval($uid).' AND task_id='.intval($taskId)." AND task_instance='".$this->esc($taskInstance)."' AND state_code='active'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('task_context_complete_failed'); }
    }

    public function activityById($activityId, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity')." WHERE activity_id='".$this->esc($activityId)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityByTid($tid, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity').' WHERE tid='.(int)$tid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityByPk($activityPk, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity').' WHERE activity_pk='.(int)$activityPk.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function expiredBoundActivities($now, $afterExpires = 0, $afterPk = 0, $limit = 20) {
        if(!is_int($now) || $now < 1 || !is_int($afterExpires) || $afterExpires < 0 ||
            !is_int($afterPk) || $afterPk < 0 || !is_int($limit) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('activity_reconcile_cursor_invalid');
        }
        $sql = 'SELECT activity_pk,activity_id,fid,tid,owner_uid,reservation_digest,state_code,pending_expires_at,updated_at FROM '
            .DB::table('pintogya_activity')." WHERE state_code='pending' AND tid IS NOT NULL AND pending_expires_at<=".$now;
        if($afterExpires > 0) {
            $sql .= ' AND (pending_expires_at>'.$afterExpires.' OR (pending_expires_at='.$afterExpires
                .' AND activity_pk>'.$afterPk.'))';
        }
        return DB::fetch_all($sql.' ORDER BY pending_expires_at,activity_pk LIMIT '.$limit);
    }

    public function hiddenDraftsInTidWindow($afterTid, $width = 1000) {
        if(!is_int($afterTid) || $afterTid < 0 || $afterTid > 4294967295 ||
            !is_int($width) || $width < 1 || $width > 1000) {
            throw new InvalidArgumentException('activity_draft_cursor_invalid');
        }
        $end = min(4294967295, $afterTid + $width);
        if($end === $afterTid) { return array(); }
        return DB::fetch_all('SELECT tid,fid,authorid,special,displayorder FROM '.DB::table('forum_thread')
            .' WHERE tid>'.$afterTid.' AND tid<='.$end.' AND special=127 AND displayorder=-4 ORDER BY tid');
    }

    public function activityByReservationDigest($digest) {
        if(!is_string($digest) || !preg_match('/^[0-9a-f]{64}$/D', $digest)) {
            throw new InvalidArgumentException('activity_reservation_digest_invalid');
        }
        return DB::fetch_first('SELECT activity_pk,activity_id,fid,tid,owner_uid,reservation_digest,state_code,pending_expires_at'
            .' FROM '.DB::table('pintogya_activity')." WHERE reservation_digest='".$this->esc($digest)."' LIMIT 1");
    }

    public function activityActionByDigest($digest, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_action')." WHERE action_digest='".$this->esc($digest)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityActionByChallenge($challengeId, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_action')." WHERE qr_challenge_id='".$this->esc($challengeId)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityRewardRuleByDigest($digest, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_reward_rule')." WHERE action_digest='".$this->esc($digest)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityInstantRuleById($activityId, $lock = false) {
        $activityId = PintogyaCrypto::assertUuid($activityId);
        if($this->instantRuleAvailable === null) {
            $engine = DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                array(DB::table('pintogya_activity_instant_rule')));
            $this->instantRuleAvailable = strtoupper((string)$engine) === 'INNODB';
        }
        if(!$this->instantRuleAvailable) { return null; }
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_instant_rule').' WHERE activity_id=%s LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        // TOGYA_PLUGIN: pintogya_instant_rule_absence -- DX returns an empty array for no row.
        $row = DB::fetch_first($sql, array($activityId));
        return $row ? $row : null;
    }

    public function insertActivityInstantRule($row) {
        if($this->instantRuleAvailable !== true) { throw new RuntimeException('instant_rule_schema_unavailable'); }
        DB::insert('pintogya_activity_instant_rule', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('instant_rule_insert_failed'); }
    }

    public function publishActivityInstantRule($activityId, $ruleHash, $now) {
        DB::update('pintogya_activity_instant_rule', array('published_at'=>$now,'state_code'=>'active'),
            "activity_id='".$this->esc($activityId)."' AND rule_hash='".$this->esc($ruleHash)."' AND published_at=0");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('instant_rule_publication_changed'); }
    }

    public function activityRewardRuleByPk($rulePk) {
        return DB::fetch_first('SELECT * FROM '.DB::table('pintogya_activity_reward_rule')
            .' WHERE rule_pk='.(int)$rulePk.' LIMIT 1');
    }

    public function insertActivityRewardRule($row) {
        DB::insert('pintogya_activity_reward_rule', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_reward_rule_insert_failed'); }
    }

    public function activityRewardPoolByActivity($activityPk, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_reward_pool').' WHERE activity_pk='.(int)$activityPk.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function insertActivityRewardPool($row) {
        DB::insert('pintogya_activity_reward_pool', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_reward_pool_insert_failed'); }
    }

    public function advanceActivityRewardPoolJoin($activityPk, $joinedBefore, $targetBefore, $joinedAfter, $targetAfter, $now) {
        DB::update('pintogya_activity_reward_pool', array('joined_count' => $joinedAfter,
            'target_amount' => $targetAfter, 'updated_at' => $now),
            'activity_pk='.(int)$activityPk.' AND joined_count='.(int)$joinedBefore.
            ' AND target_amount='.(int)$targetBefore." AND state_code='open'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_join_pool_write_failed'); }
    }

    public function reduceActivityRewardPoolJoin($activityPk, $joinedBefore, $targetBefore, $joinedAfter, $now) {
        DB::update('pintogya_activity_reward_pool', array('joined_count' => $joinedAfter, 'updated_at' => $now),
            'activity_pk='.(int)$activityPk.' AND joined_count='.(int)$joinedBefore.
            ' AND target_amount='.(int)$targetBefore." AND state_code='open'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_join_pool_write_failed'); }
    }

    public function advanceActivityRewardPoolCompletion($activityPk, $joinedCount, $completedBefore,
        $targetBefore, $completedAfter, $targetAfter, $now) {
        DB::update('pintogya_activity_reward_pool', array('completed_count' => $completedAfter,
            'target_amount' => $targetAfter, 'updated_at' => $now),
            'activity_pk='.(int)$activityPk.' AND joined_count='.(int)$joinedCount.
            ' AND completed_count='.(int)$completedBefore.' AND target_amount='.(int)$targetBefore.
            " AND state_code IN ('open','closing')");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_completion_pool_write_failed'); }
    }

    public function updateActivityRewardPoolScore($activityPk, $joinedCount, $completedCount,
        $scoreBefore, $targetBefore, $scoreAfter, $targetAfter, $allowClosing, $now) {
        $states = $allowClosing ? "('open','closing')" : "('open')";
        DB::update('pintogya_activity_reward_pool', array('score_total' => $scoreAfter,
            'target_amount' => $targetAfter, 'updated_at' => $now),
            'activity_pk='.(int)$activityPk.' AND joined_count='.(int)$joinedCount.
            ' AND completed_count='.(int)$completedCount.' AND score_total='.(int)$scoreBefore.
            ' AND target_amount='.(int)$targetBefore.' AND state_code IN '.$states);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_rating_pool_write_failed'); }
    }

    public function activityRewardMemberForUid($activityPk, $uid, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_reward_member')
            .' WHERE activity_pk='.(int)$activityPk.' AND uid='.(int)$uid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function insertActivityRewardMember($row) {
        // Preserve DEFAULT NULL: native DX quotes a PHP null as an empty string.
        if(array_key_exists('receipt_digest', $row) && $row['receipt_digest'] === null) {
            unset($row['receipt_digest']);
        }
        DB::insert('pintogya_activity_reward_member', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_completion_member_insert_failed'); }
    }

    public function insertActivityAction($row) {
        DB::insert('pintogya_activity_action', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_action_insert_failed'); }
    }

    public function consumeActivityAction($digest, $now) {
        DB::update('pintogya_activity_action', array('state_code' => 'consumed', 'consumed_at' => (int)$now, 'updated_at' => (int)$now),
            "action_digest='".$this->esc($digest)."' AND state_code='prepared'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_action_consumed'); }
    }

    public function insertActivity($row) {
        DB::insert('pintogya_activity', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_insert_failed'); }
    }

    public function participationForUid($activityPk, $uid, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_participation').' WHERE activity_pk='.(int)$activityPk
            .' AND uid='.(int)$uid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function reviewCasesForSource($activityPk, $uid, $kind, $sourceRef, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_review_case')
            .' WHERE activity_pk='.(int)$activityPk.' AND uid='.(int)$uid
            ." AND kind='".$this->esc($kind)."' AND source_ref='".$this->esc($sourceRef)."'"
            .' ORDER BY review_pk LIMIT 21';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_all($sql);
    }

    public function reviewCaseByPk($reviewPk, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_review_case').' WHERE review_pk='.(int)$reviewPk.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function reviewDecisionForReview($reviewPk, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_activity_review_decision')
            .' WHERE review_pk='.(int)$reviewPk.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function reviewCasesForMember($activityPk, $uid, $limit) {
        if(!is_int($activityPk) || $activityPk < 1 || !is_int($uid) || $uid < 1 ||
            !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('activity_review_input_invalid');
        }
        return DB::fetch_all('SELECT kind,source_ref,stage_version,binding_generation,state_code,auto_due_at,decision_source FROM '.
            DB::table('pintogya_activity_review_case').' WHERE activity_pk='.$activityPk.' AND uid='.$uid.
            ' ORDER BY review_pk DESC LIMIT '.$limit);
    }

    public function pendingReviewCasesForActivity($activityPk, $afterPk, $limit) {
        if(!is_int($activityPk) || $activityPk < 1 || !is_int($afterPk) || $afterPk < 0 ||
            !is_int($limit) || $limit < 1 || $limit > 21) {
            throw new InvalidArgumentException('activity_review_cursor_invalid');
        }
        return DB::fetch_all('SELECT review_pk,uid,kind,source_ref,stage_id,stage_version,submitted_at,auto_due_at FROM '.
            DB::table('pintogya_activity_review_case').' WHERE activity_pk='.$activityPk.
            " AND state_code='pending' AND decision_source='none' AND review_pk>".$afterPk.
            ' ORDER BY review_pk LIMIT '.$limit);
    }

    public function approvedTimeoutCompletionCases($afterPk, $limit) {
        if(!is_int($afterPk) || $afterPk < 0 || !is_int($limit) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('activity_completion_cursor_invalid');
        }
        return DB::fetch_all('SELECT review_pk FROM '.DB::table('pintogya_activity_review_case')
            ." WHERE kind='completion' AND state_code='approved' AND decision_source='timeout'"
            .' AND review_pk>'.$afterPk.' ORDER BY review_pk LIMIT '.$limit);
    }

    public function dueReviewCases($now, $afterDue, $afterPk, $limit) {
        if(!is_int($now) || $now < 1 || !is_int($afterDue) || $afterDue < 0 ||
            !is_int($afterPk) || $afterPk < 0 || !is_int($limit) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('activity_review_cursor_invalid');
        }
        return DB::fetch_all('SELECT review_pk,auto_due_at FROM '.DB::table('pintogya_activity_review_case')
            ." WHERE state_code='pending' AND auto_due_at>0 AND auto_due_at<=".$now
            .' AND (auto_due_at>'.$afterDue.' OR (auto_due_at='.$afterDue.' AND review_pk>'.$afterPk.'))'
            .' ORDER BY auto_due_at,review_pk LIMIT '.$limit);
    }

    public function decideReviewCase($reviewPk, $decision) {
        if(!in_array($decision['decision'] ?? null, array('approved', 'rejected'), true) ||
            !in_array($decision['decision_source'] ?? null, array('moderator', 'timeout'), true) ||
            ($decision['decision_source'] === 'timeout' &&
                ($decision['decision'] !== 'approved' || $decision['decided_by_uid'] !== null)) ||
            ($decision['decision_source'] === 'moderator' && (int)$decision['decided_by_uid'] < 1)) {
            throw new InvalidArgumentException('activity_review_decision_invalid');
        }
        $values = array('state_code' => $decision['decision'],
            'decision_source' => $decision['decision_source'],
            'decided_at' => $decision['decided_at'],
            'decision_digest' => $decision['decision_digest'], 'updated_at' => $decision['decided_at']);
        // DX quotes PHP null as an empty string; preserve SQL NULL for system decisions.
        if($decision['decided_by_uid'] !== null) {
            $values['decided_by_uid'] = $decision['decided_by_uid'];
        }
        DB::update('pintogya_activity_review_case', $values,
            'review_pk='.(int)$reviewPk." AND state_code='pending' AND decision_source='none' AND decided_at=0 AND decided_by_uid IS NULL");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_review_state_changed'); }
    }

    public function insertReviewDecision($review, $decision) {
        $row = array(
            'review_pk' => (int)$review['review_pk'], 'activity_pk' => (int)$review['activity_pk'],
            'participant_pk' => (int)$review['participant_pk'], 'uid' => (int)$review['uid'],
            'kind' => $review['kind'], 'source_digest' => $review['source_digest'],
            'decision' => $decision['decision'], 'decision_source' => $decision['decision_source'],
            'decided_at' => $decision['decided_at'],
            'decision_digest' => $decision['decision_digest'],
        );
        if($decision['decided_by_uid'] !== null) {
            $row['decided_by_uid'] = $decision['decided_by_uid'];
        }
        DB::insert('pintogya_activity_review_decision', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_review_decision_insert_failed'); }
    }

    public function insertReviewCase($row) {
        // DX quotes PHP null as ''; these columns must use SQL DEFAULT NULL.
        foreach(array('stage_id', 'decided_by_uid', 'decision_digest') as $nullable) {
            if(array_key_exists($nullable, $row) && $row[$nullable] === null) {
                unset($row[$nullable]);
            }
        }
        $reviewPk = (int)DB::insert('pintogya_activity_review_case', $row, true, false, true);
        if($reviewPk < 1 || DB::affected_rows() !== 1) {
            throw new RuntimeException('activity_review_insert_failed');
        }
        return $reviewPk;
    }

    public function invalidatePendingReviewCase($reviewPk, $now) {
        DB::update('pintogya_activity_review_case', array('state_code' => 'invalid', 'updated_at' => (int)$now),
            'review_pk='.(int)$reviewPk." AND state_code='pending' AND decision_source='none'");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_review_state_changed'); }
    }

    public function participationForTogyaUser($activityPk, $togyaUserId, $lock = false) {
        $sql = 'SELECT * FROM '.DB::table('pintogya_participation').' WHERE activity_pk='.(int)$activityPk
            ." AND togya_user_id='".$this->esc($togyaUserId)."' LIMIT 1";
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function participationCount($activityPk) {
        if(!is_int($activityPk) || $activityPk < 1) {
            throw new InvalidArgumentException('participation_count_invalid');
        }
        return (int)DB::result_first('SELECT COUNT(*) FROM '
            .DB::table('pintogya_participation').' p INNER JOIN '.DB::table('common_member').' m ON m.uid=p.uid'
            .' WHERE p.activity_pk='.$activityPk." AND p.state_code='active'");
    }

    public function participationPage($activityPk, $afterPk, $limit) {
        if(!is_int($activityPk) || $activityPk < 1 || !is_int($afterPk) || $afterPk < 0 ||
            !is_int($limit) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('participation_page_invalid');
        }
        return DB::fetch_all('SELECT p.participant_pk,p.uid,p.first_joined_at,p.last_verified_at,m.username FROM '
            .DB::table('pintogya_participation').' p INNER JOIN '.DB::table('common_member').' m ON m.uid=p.uid'
            .' WHERE p.activity_pk='.$activityPk." AND p.state_code='active' AND p.participant_pk>".$afterPk
            .' ORDER BY p.participant_pk LIMIT '.($limit + 1));
    }

    public function participationAuditPage($activityPk, $afterPk, $limit) {
        if(!is_int($activityPk) || $activityPk < 1 || !is_int($afterPk) || $afterPk < 0 ||
            !is_int($limit) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('participation_audit_page_invalid');
        }
        return DB::fetch_all('SELECT participant_pk,uid,togya_user_id,binding_generation,stage_version,state_code'
            .' FROM '.DB::table('pintogya_participation').' WHERE activity_pk='.$activityPk
            ." AND state_code='active' AND participant_pk>".$afterPk
            .' ORDER BY participant_pk LIMIT '.($limit + 1));
    }

    public function participationByPk($participantPk) {
        if(!is_int($participantPk) || $participantPk < 1) {
            throw new InvalidArgumentException('participation_pk_invalid');
        }
        return DB::fetch_first('SELECT participant_pk,activity_pk,uid,state_code FROM '
            .DB::table('pintogya_participation').' WHERE participant_pk='.$participantPk.' LIMIT 1');
    }

    public function insertParticipation($row) {
        DB::insert('pintogya_participation', $row, false, false, true);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('participation_insert_failed'); }
    }

    public function updateParticipation($activityPk, $uid, $togyaUserId, $values) {
        $where = 'activity_pk='.(int)$activityPk.' AND uid='.(int)$uid
            ." AND togya_user_id='".$this->esc($togyaUserId)."'";
        DB::update('pintogya_participation', $values, $where);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('participation_update_failed'); }
    }

    public function replacePendingActivity($activityId, $values, $now) {
        $where = "activity_id='".$this->esc($activityId)."' AND tid IS NULL AND "
            ."(state_code='invalid' OR (state_code='pending' AND pending_expires_at<=".(int)$now.'))';
        DB::update('pintogya_activity', $values, $where);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_reservation_changed'); }
    }

    public function activityThread($tid, $lock = false) {
        $sql = 'SELECT tid,fid,authorid,special,displayorder FROM '.DB::table('forum_thread').' WHERE tid='.(int)$tid.' LIMIT 1';
        if($lock) { $sql .= ' FOR UPDATE'; }
        return DB::fetch_first($sql);
    }

    public function activityFirstPostMessage($tid) {
        $post = table_forum_post::t()->fetch_threadpost_by_tid_invisible($tid);
        return is_array($post) && (int)($post['first'] ?? 0) === 1 && is_string($post['message'] ?? null) ?
            $post['message'] : null;
    }

    public function bindActivityTid($activityId, $digest, $tid, $now) {
        DB::update('pintogya_activity', array('tid' => (int)$tid, 'updated_at' => (int)$now),
            "activity_id='".$this->esc($activityId)."' AND reservation_digest='".$this->esc($digest)."' AND state_code='pending' AND tid IS NULL");
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_draft_binding_failed'); }
    }

    public function activateActivity($activityId, $digest, $tid, $now) {
        DB::update('pintogya_activity', array('state_code' => 'active', 'pending_expires_at' => 0,
            'published_at' => (int)$now, 'updated_at' => (int)$now),
            "activity_id='".$this->esc($activityId)."' AND reservation_digest='".$this->esc($digest)."' AND state_code='pending' AND tid=".(int)$tid);
        if(DB::affected_rows() !== 1) { throw new RuntimeException('activity_activation_failed'); }
    }
}
