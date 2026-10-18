<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_settings.php';
require_once __DIR__.'/activity_reward_math.php';
require_once __DIR__.'/activity_native_post.php';
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/site_profile.php';

final class PintogyaActivityPublish {
    private $repo;
    private $session;
    private $reader;
    private $siteHost;
    private $forumFid;
    private $enabled;
    private $rewardDefaultsJson;
    private $rewardCreditId;
    private $extcredits;
    private $instant = false;
    private $instantDefaults;

    public function __construct($repo, $session, $reader, $siteHost, $forumFid, $enabled, $rewardDefaults = null, $extcredits = array(), $instantMode = false) {
        if(!PintogyaSiteProfile::validHost($siteHost)) { throw new InvalidArgumentException('activity_site_invalid'); }
        if(!is_array($extcredits)) { throw new InvalidArgumentException('activity_reward_credits_invalid'); }
        $this->repo = $repo;
        $this->session = $session;
        $this->reader = $reader;
        $this->siteHost = $siteHost;
        $this->forumFid = (int)$forumFid;
        $this->enabled = $enabled === true;
        $this->extcredits = $extcredits;
        $this->instant = $instantMode === true;
        if($rewardDefaults !== null) {
            if(is_array($rewardDefaults) && ($rewardDefaults['version'] ?? null) === PintogyaActivityInstantRule::VERSION) {
                $input = $rewardDefaults; unset($input['version']);
                $this->instantDefaults = PintogyaActivityInstantRule::fromInput($input, $extcredits);
                $this->instant = true;
                $this->rewardDefaultsJson = PintogyaCrypto::canonicalJson($this->instantDefaults);
                $this->rewardCreditId = $this->instantDefaults['credit_id'];
                return;
            }
            if($this->instant) { throw new InvalidArgumentException('activity_instant_defaults_required'); }
            $json = json_encode($rewardDefaults, JSON_THROW_ON_ERROR);
            $validated = PintogyaActivitySettings::rewardDefaultsFromJson($json, $extcredits);
            if($validated['version'] !== 2) { throw new InvalidArgumentException('activity_reward_deadlines_unavailable'); }
            $this->rewardDefaultsJson = json_encode($validated, JSON_THROW_ON_ERROR);
            $this->rewardCreditId = $validated['credit_id'];
        } else {
            $this->rewardDefaultsJson = null;
            $this->rewardCreditId = 0;
        }
    }

    public function issue($uid, $fid, $activityId, $sessionDigest, $now) {
        $this->assertRequest($uid, $fid, $sessionDigest, $now);
        if($this->rewardDefaultsJson === null) { throw new RuntimeException('activity_reward_rules_unavailable'); }
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $binding = $this->session->binding($uid);
        $activity = $this->ownedActivity($uid, $activityId);
        $this->assertOwner($binding, $activity);
        $instantRule = $this->instant ? PintogyaActivityInstantRule::snapshot($this->instantDefaults, $activity) : null;
        $token = PintogyaCrypto::b64urlEncode(random_bytes(32));
        $digest = $this->actionDigest($token);
        $expires = $now + 300;
        $this->withUidTransaction($uid, function() use ($uid, $fid, $sessionDigest, $now, $expires, $binding, $activity, $digest, $instantRule) {
            $this->assertBinding($binding, $this->repo->activeBindingForUid($uid, true));
            $existing = $this->repo->activityById($activity['id'], true);
            if($existing && !$this->replaceable($existing, $now)) { throw new RuntimeException('activity_already_mapped'); }
            $approval = $this->repo->latestChallenge($uid, 0, 'activity-publish', true);
            if(!$approval || $approval['state_code'] !== 'approved' ||
                !hash_equals((string)$approval['session_digest'], $sessionDigest) ||
                (int)$approval['approved_at'] < $now - 300 || (int)$approval['approved_at'] > $now) {
                throw new RuntimeException('activity_qr_approval_required');
            }
            if($this->instant) {
                $row = $this->repo->activityInstantRuleById($activity['id'], true);
                if($row) {
                    $this->assertInstantRule($row, $activity);
                    if((int)$row['published_at'] !== 0) { throw new RuntimeException('instant_published_rule_immutable'); }
                } else {
                    $this->repo->insertActivityInstantRule(array('activity_id'=>$activity['id'],
                        'rule_hash'=>PintogyaCrypto::digest('pintogya_instant_rule_v3',$instantRule),
                        'rule_json'=>PintogyaCrypto::canonicalJson($instantRule),
                        'source_json'=>PintogyaCrypto::canonicalJson($activity),
                        'state_code'=>'held','created_at'=>$now,'published_at'=>0));
                }
            }
            $this->repo->insertActivityAction(array(
                'action_digest' => $digest, 'uid' => $uid, 'fid' => $fid,
                'session_digest' => $sessionDigest, 'qr_challenge_id' => $approval['challenge_id'],
                'binding_generation' => (int)$binding['generation'],
                'activity_id' => $activity['id'], 'stage_version' => $activity['stageVersion'],
                'source_hash' => self::sourceHash($activity), 'purpose' => 'publish',
                'state_code' => 'prepared', 'created_at' => $now, 'expires_at' => $expires,
                'updated_at' => $now, 'consumed_at' => 0,
            ));
            if(!$this->instant) {
                $this->repo->insertActivityRewardRule(array(
                    'action_digest' => $digest, 'activity_id' => $activity['id'],
                    'credit_id' => $this->rewardCreditId,
                    'rules_json' => $this->rewardDefaultsJson,
                    'rule_hash' => self::rewardRuleHash($this->rewardDefaultsJson), 'created_at' => $now,
                ));
            }
            $this->repo->updateChallenge($approval['challenge_id'], array('state_code' => 'superseded', 'updated_at' => $now), 'approved');
        });
        return array('token' => $token, 'expiresAt' => $expires, 'activity' => $activity);
    }

    public function reserve($uid, $fid, $token, $sessionDigest, $now) {
        $this->assertRequest($uid, $fid, $sessionDigest, $now);
        $digest = $this->actionDigest($token);
        $action = $this->repo->activityActionByDigest($digest, false);
        $this->assertAction($action, $uid, $fid, $sessionDigest, 'prepared', $now);
        $binding = $this->session->binding($uid);
        $activity = $this->ownedActivity($uid, $action['activity_id']);
        $this->assertOwner($binding, $activity);
        $this->assertSource($action, $binding, $activity);
        $this->withUidTransaction($uid, function() use ($uid, $fid, $sessionDigest, $now, $digest, $binding, $activity) {
            $this->assertBinding($binding, $this->repo->activeBindingForUid($uid, true));
            $action = $this->repo->activityActionByDigest($digest, true);
            $this->assertAction($action, $uid, $fid, $sessionDigest, 'prepared', $now);
            $this->assertSource($action, $binding, $activity);
            $existing = $this->repo->activityById($activity['id'], true);
            $this->checkedReward($digest, $activity, true);
            $values = array(
                'fid' => $fid, 'owner_uid' => $uid,
                'binding_generation' => (int)$binding['generation'],
                'stage_version' => $activity['stageVersion'], 'source_hash' => self::sourceHash($activity),
                'reservation_digest' => $digest, 'state_code' => 'pending',
                'pending_expires_at' => $now + 900, 'updated_at' => $now, 'published_at' => 0,
            );
            if($existing) {
                if(!$this->replaceable($existing, $now)) { throw new RuntimeException('activity_already_mapped'); }
                $this->repo->replacePendingActivity($activity['id'], $values, $now);
            } else {
                $values['activity_id'] = $activity['id'];
                $values['created_at'] = $now;
                $this->repo->insertActivity($values);
            }
            $this->repo->consumeActivityAction($digest, $now);
        });
        return $activity;
    }

    public function reservationDigest($token) { return $this->actionDigest($token); }

    public function attachDraft($uid, $fid, $token, $sessionDigest, $tid, $now) {
        $this->assertRequest($uid, $fid, $sessionDigest, $now);
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295) { throw new InvalidArgumentException('activity_tid_invalid'); }
        $digest = $this->actionDigest($token);
        $action = $this->repo->activityActionByDigest($digest, false);
        $this->assertAction($action, $uid, $fid, $sessionDigest, 'consumed', $now);
        $binding = $this->session->binding($uid);
        $activity = $this->ownedActivity($uid, $action['activity_id']);
        $this->assertOwner($binding, $activity);
        $this->assertSource($action, $binding, $activity);
        return $this->withUidTransaction($uid, function() use ($uid, $fid, $sessionDigest, $tid, $now, $digest, $binding, $activity) {
            $this->assertBinding($binding, $this->repo->activeBindingForUid($uid, true));
            $action = $this->repo->activityActionByDigest($digest, true);
            $this->assertAction($action, $uid, $fid, $sessionDigest, 'consumed', $now);
            $this->assertSource($action, $binding, $activity);
            $map = $this->repo->activityById($activity['id'], true);
            $this->checkedReward($digest, $activity, true);
            if(!$map || !hash_equals((string)$map['reservation_digest'], $digest) || (int)$map['owner_uid'] !== $uid || (int)$map['fid'] !== $fid) {
                throw new RuntimeException('activity_reservation_mismatch');
            }
            if($map['state_code'] === 'active' && (int)$map['tid'] === $tid) { return $map; }
            if($map['state_code'] === 'pending' && (int)$map['tid'] === $tid &&
                ($this->instant || (int)$map['pending_expires_at'] >= $now)) { return $map; }
            if($map['state_code'] !== 'pending' || (int)$map['tid'] !== 0 || (int)$map['pending_expires_at'] < $now) {
                throw new RuntimeException('activity_reservation_expired');
            }
            $thread = $this->repo->activityThread($tid, true);
            if(!$thread || (int)$thread['fid'] !== $fid || (int)$thread['authorid'] !== $uid ||
                (int)$thread['special'] !== 127 || (int)$thread['displayorder'] !== -4 ||
                $this->repo->activityFirstPostMessage($tid) !== PintogyaActivityNativePost::messageForDigest($digest)) {
                throw new RuntimeException('activity_thread_mismatch');
            }
            $this->repo->bindActivityTid($activity['id'], $digest, $tid, $now);
            return array_merge($map, array('tid' => $tid, 'updated_at' => $now));
        });
    }

    public function markPublished($uid, $fid, $token, $sessionDigest, $tid, $publication, $now) {
        $this->assertRequest($uid, $fid, $sessionDigest, $now);
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295) { throw new InvalidArgumentException('activity_tid_invalid'); }
        if($publication !== 1 && $publication !== -1) { throw new InvalidArgumentException('activity_publication_invalid'); }
        $digest = $this->actionDigest($token);
        return $this->completePublication($uid, $fid, $digest, $sessionDigest, $tid, $publication, $now);
    }

    public function canResumePublication($uid, $tid, $sessionDigest, $now) {
        try {
            $map = $this->resumeContext($uid, $tid, $sessionDigest, $now);
            return $map['state_code'] === 'pending';
        } catch(Throwable $error) { return false; }
    }

    public function resumePublication($uid, $tid, $sessionDigest, $now) {
        $map = $this->resumeContext($uid, $tid, $sessionDigest, $now);
        $result = $this->completePublication($uid, (int)$map['fid'], (string)$map['reservation_digest'],
            $sessionDigest, $tid, 1, $now);
        return array('state' => 'active', 'tid' => (int)$result['tid']);
    }

    private function resumeContext($uid, $tid, $sessionDigest, $now) {
        if(!$this->instant) { throw new RuntimeException('instant_publication_resume_unavailable'); }
        $this->assertRequest($uid, $this->forumFid, $sessionDigest, $now);
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295) { throw new InvalidArgumentException('activity_tid_invalid'); }
        $map = $this->repo->activityByTid($tid);
        if(!$map || (int)$map['tid'] !== $tid || (int)$map['owner_uid'] !== $uid ||
            (int)$map['fid'] !== $this->forumFid || !in_array($map['state_code'], array('pending','active'), true) ||
            !is_string($map['reservation_digest']) || !preg_match('/^[a-f0-9]{64}$/D', $map['reservation_digest']) ||
            !$this->repo->activityInstantRuleById($map['activity_id'])) {
            throw new RuntimeException('instant_publication_resume_unavailable');
        }
        $action = $this->repo->activityActionByDigest($map['reservation_digest'], false);
        $this->assertAction($action, $uid, $this->forumFid, $sessionDigest, 'consumed', $now);
        if($action['activity_id'] !== $map['activity_id']) { throw new RuntimeException('activity_reservation_mismatch'); }
        $binding = $this->session->binding($uid);
        if((int)$map['binding_generation'] !== (int)$binding['generation'] ||
            (int)$action['binding_generation'] !== (int)$binding['generation'] ||
            (int)$map['stage_version'] !== (int)$action['stage_version'] ||
            !hash_equals((string)$map['source_hash'], (string)$action['source_hash'])) {
            throw new RuntimeException('activity_source_changed');
        }
        $thread = $this->repo->activityThread($tid, false);
        if(!$thread || (int)$thread['fid'] !== $this->forumFid || (int)$thread['authorid'] !== $uid ||
            (int)$thread['special'] !== 127 || (int)$thread['displayorder'] !== 0 ||
            $this->repo->activityFirstPostMessage($tid) !== PintogyaActivityNativePost::messageForDigest($map['reservation_digest'])) {
            throw new RuntimeException('activity_thread_mismatch');
        }
        return $map;
    }

    private function completePublication($uid, $fid, $digest, $sessionDigest, $tid, $publication, $now) {
        $action = $this->repo->activityActionByDigest($digest, false);
        $this->assertAction($action, $uid, $fid, $sessionDigest, 'consumed', $now);
        $binding = $this->session->binding($uid);
        $activity = $this->ownedActivity($uid, $action['activity_id']);
        $this->assertOwner($binding, $activity);
        $this->assertSource($action, $binding, $activity);
        return $this->withUidTransaction($uid, function() use ($uid, $fid, $sessionDigest, $tid, $publication, $now, $digest, $binding, $activity) {
            $this->assertBinding($binding, $this->repo->activeBindingForUid($uid, true));
            $action = $this->repo->activityActionByDigest($digest, true);
            $this->assertAction($action, $uid, $fid, $sessionDigest, 'consumed', $now);
            $this->assertSource($action, $binding, $activity);
            $map = $this->repo->activityById($activity['id'], true);
            list($rule, $defaults) = $this->checkedReward($digest, $activity, true);
            if(!$map || !hash_equals((string)$map['reservation_digest'], $digest) || (int)$map['owner_uid'] !== $uid || (int)$map['fid'] !== $fid) {
                throw new RuntimeException('activity_reservation_mismatch');
            }
            if($map['state_code'] === 'active' && (int)$map['tid'] === $tid) {
                if($this->instant) {
                    $thread = $this->repo->activityThread($tid, true);
                    if(!$thread || (int)$thread['fid'] !== $fid || (int)$thread['authorid'] !== $uid ||
                        (int)$thread['special'] !== 127 || (int)$thread['displayorder'] !== ($publication === 1 ? 0 : -2) ||
                        $this->repo->activityFirstPostMessage($tid) !== PintogyaActivityNativePost::messageForDigest($digest)) {
                        throw new RuntimeException('activity_thread_mismatch');
                    }
                    if((int)$rule['published_at'] !== (int)$map['published_at'] || $rule['state_code'] !== 'active') {
                        throw new RuntimeException('instant_rule_publication_changed');
                    }
                } else {
                    $this->assertRewardPool($map, $rule);
                    PintogyaActivityDeadlines::cutoffs((int)$map['published_at'], $defaults['window_days']);
                }
                return $map;
            }
            if($map['state_code'] !== 'pending' || (int)$map['tid'] !== $tid ||
                (!$this->instant && (int)$map['pending_expires_at'] < $now)) {
                throw new RuntimeException('activity_reservation_expired');
            }
            $thread = $this->repo->activityThread($tid, true);
            $displayorder = $publication === 1 ? 0 : -2;
            if(!$thread || (int)$thread['fid'] !== $fid || (int)$thread['authorid'] !== $uid ||
                (int)$thread['special'] !== 127 || (int)$thread['displayorder'] !== $displayorder ||
                $this->repo->activityFirstPostMessage($tid) !== PintogyaActivityNativePost::messageForDigest($digest)) {
                throw new RuntimeException('activity_thread_mismatch');
            }
            if($this->instant) {
                $this->repo->publishActivityInstantRule($activity['id'], $rule['rule_hash'], $now);
            } else {
                PintogyaActivityDeadlines::cutoffs($now, $defaults['window_days']);
                $this->initializeRewardPool($map, $rule, $defaults, $now);
            }
            $this->repo->activateActivity($activity['id'], $digest, $tid, $now);
            return array_merge($map, array('state_code' => 'active', 'pending_expires_at' => 0, 'published_at' => $now));
        });
    }

    public static function sourceHash($activity) {
        if(!is_array($activity) || !isset($activity['id'], $activity['creatorId'], $activity['stageVersion']) ||
            !is_int($activity['stageVersion']) || $activity['stageVersion'] < 1 ||
            !PintogyaCrypto::validOpaqueId($activity['creatorId'])) { throw new InvalidArgumentException('activity_source_invalid'); }
        return PintogyaCrypto::digest('pintogya/activity-source/v1', array(
            'id' => PintogyaCrypto::assertUuid($activity['id']),
            'creatorId' => $activity['creatorId'], 'stageVersion' => $activity['stageVersion'],
        ));
    }

    private function actionDigest($token) {
        $bytes = PintogyaCrypto::b64urlDecode($token, 32);
        return hash('sha256', "pintogya/activity-action/v1\0".$this->siteHost."\0".$bytes);
    }

    private function ownedActivity($uid, $activityId) {
        return $this->instant ? $this->reader->ownedInstantActivity($uid, $activityId, 'en-US') :
            $this->reader->ownedPublicActivity($uid, $activityId, 'en-US');
    }

    private function checkedReward($digest, $activity, $lock) {
        if($this->instant) {
            $row = $this->repo->activityInstantRuleById($activity['id'], $lock);
            return array($row, $this->assertInstantRule($row, $activity));
        }
        $row = $this->repo->activityRewardRuleByDigest($digest, $lock);
        return array($row, $this->assertRewardRule($row, $digest, $activity['id']));
    }

    private function assertInstantRule($row, $activity) {
        if(!is_array($row) || ($row['activity_id'] ?? null) !== $activity['id'] ||
            !is_string($row['rule_json'] ?? null) || !is_string($row['rule_hash'] ?? null) ||
            !in_array($row['state_code'] ?? null, array('held','active'), true)) {
            throw new RuntimeException('instant_rule_unavailable');
        }
        $rule = PintogyaActivityInstantRule::storedSnapshot($row);
        if($rule['activity_id'] !== $activity['id'] || $rule['stage_version'] !== $activity['stageVersion'] ||
            !is_array($this->extcredits[$rule['credit_id']] ?? null)) { throw new RuntimeException('instant_rule_source_changed'); }
        $amounts = array();
        foreach($rule['stages'] as $id=>$stage) { if($stage['kind'] === 'tutorial') { $amounts[$id] = $stage['amount']; } }
        $source = PintogyaActivityInstantRule::snapshot(array('version'=>3,'credit_id'=>$rule['credit_id'],
            'base'=>$rule['base'],'stage_default'=>0),$activity,$amounts);
        foreach($source['stages'] as $id=>$stage) {
            if(!isset($rule['stages'][$id]) || $rule['stages'][$id]['position'] !== $stage['position'] ||
                $rule['stages'][$id]['kind'] !== $stage['kind']) { throw new RuntimeException('instant_rule_source_changed'); }
        }
        if(count($source['stages']) !== count($rule['stages'])) { throw new RuntimeException('instant_rule_source_changed'); }
        return $rule;
    }

    private function assertRequest($uid, $fid, $sessionDigest, $now) {
        if(!$this->enabled || $fid !== $this->forumFid) { throw new RuntimeException('activity_posts_disabled'); }
        if(!is_int($uid) || $uid < 1 || $uid > 16777215 || !is_int($fid) || $fid < 1 || $fid > 16777215 ||
            !is_string($sessionDigest) || !preg_match('/^[0-9a-f]{64}$/D', $sessionDigest) ||
            !is_int($now) || $now < 1 || $now > 4294966000) { throw new InvalidArgumentException('activity_request_invalid'); }
    }

    private function assertOwner($binding, $activity) {
        if(!is_array($binding) || !isset($binding['togya_user_id'], $binding['generation']) ||
            !is_array($activity) || !isset($activity['creatorId']) ||
            !hash_equals((string)$binding['togya_user_id'], (string)$activity['creatorId'])) {
            throw new RuntimeException('activity_owner_mismatch');
        }
    }

    private function assertBinding($expected, $current) {
        if(!$current || $current['state_code'] !== 'active' ||
            !hash_equals((string)$expected['togya_user_id'], (string)$current['togya_user_id']) ||
            (int)$expected['generation'] !== (int)$current['generation']) {
            throw new RuntimeException('activity_binding_changed');
        }
    }

    private function assertAction($action, $uid, $fid, $sessionDigest, $state, $now) {
        if(!$action || (int)$action['uid'] !== $uid || (int)$action['fid'] !== $fid ||
            !hash_equals((string)$action['session_digest'], $sessionDigest) ||
            $action['purpose'] !== 'publish' || $action['state_code'] !== $state ||
            ($state === 'prepared' && (int)$action['expires_at'] < $now)) {
            throw new RuntimeException('activity_action_invalid');
        }
    }

    private function assertSource($action, $binding, $activity) {
        if((int)$action['binding_generation'] !== (int)$binding['generation'] ||
            (int)$action['stage_version'] !== $activity['stageVersion'] ||
            !hash_equals((string)$action['source_hash'], self::sourceHash($activity))) {
            throw new RuntimeException('activity_source_changed');
        }
    }

    private static function rewardRuleHash($json) {
        return hash('sha256', "pintogya/activity-reward-rule/v1\0".$json);
    }

    private function assertRewardPool($map, $rule) {
        $pool = $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], true);
        if(!$pool || (int)$pool['activity_pk'] !== (int)$map['activity_pk'] ||
            (int)$pool['rule_pk'] !== (int)$rule['rule_pk'] ||
            (int)$pool['credit_id'] !== (int)$rule['credit_id'] ||
            !hash_equals((string)$rule['rule_hash'], (string)$pool['rule_hash'])) {
            throw new RuntimeException('activity_reward_pool_mismatch');
        }
    }

    private function initializeRewardPool($map, $rule, $defaults, $now) {
        if((int)($map['activity_pk'] ?? 0) < 1 || (int)($rule['rule_pk'] ?? 0) < 1 ||
            $this->repo->activityRewardPoolByActivity((int)$map['activity_pk'], true)) {
            throw new RuntimeException('activity_reward_pool_conflict');
        }
        $initial = PintogyaActivityRewardMath::calculate($defaults['rules'], 0, 0, 0);
        $this->repo->insertActivityRewardPool(array(
            'activity_pk' => (int)$map['activity_pk'], 'rule_pk' => (int)$rule['rule_pk'],
            'rule_hash' => $rule['rule_hash'], 'credit_id' => (int)$rule['credit_id'],
            'joined_count' => 0, 'completed_count' => 0, 'score_total' => 0,
            'target_amount' => $initial['per_member'], 'paid_total' => 0,
            'state_code' => 'open', 'updated_at' => $now,
        ));
    }

    private function assertRewardRule($row, $digest, $activityId) {
        if(!is_array($row) || (string)($row['action_digest'] ?? '') !== $digest ||
            (string)($row['activity_id'] ?? '') !== $activityId || !is_string($row['rules_json'] ?? null) ||
            !is_string($row['rule_hash'] ?? null) ||
            !hash_equals(self::rewardRuleHash($row['rules_json']), $row['rule_hash'])) {
            throw new RuntimeException('activity_reward_snapshot_invalid');
        }
        try { $defaults = PintogyaActivitySettings::rewardDefaultsFromJson($row['rules_json'], $this->extcredits); }
        catch(InvalidArgumentException $error) { throw new RuntimeException('activity_reward_snapshot_invalid', 0, $error); }
        if($defaults['version'] !== 2 || (string)$row['credit_id'] !== (string)$defaults['credit_id']) {
            throw new RuntimeException('activity_reward_snapshot_invalid');
        }
        return $defaults;
    }

    private function replaceable($map, $now) {
        return (int)$map['tid'] === 0 && ($map['state_code'] === 'invalid' ||
            ($map['state_code'] === 'pending' && (int)$map['pending_expires_at'] <= $now));
    }

    private function withUidTransaction($uid, $callback) {
        $lock = $this->repo->acquireUidLock($uid);
        $commitStarted = false;
        $unknown = false;
        try {
            $this->repo->begin();
            try {
                $result = $callback();
                $commitStarted = true;
                $this->repo->commit();
                return $result;
            } catch(Throwable $error) {
                if($this->instant && $commitStarted) {
                    // Do not reuse this connection after an indeterminate COMMIT.
                    $unknown = true;
                    throw new RuntimeException('instant_publication_commit_outcome_unknown', 0, $error);
                }
                try { $this->repo->rollback(); }
                catch(Throwable $rollbackError) {
                    if($this->instant) {
                        $unknown = true;
                        throw new RuntimeException('instant_publication_rollback_outcome_unknown', 0, $rollbackError);
                    }
                    throw $rollbackError;
                }
                throw $error;
            }
        } finally {
            if(!$unknown) { $this->repo->releaseUidLock($lock); }
        }
    }
}
