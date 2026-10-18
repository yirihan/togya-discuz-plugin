<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_settings.php';
require_once __DIR__.'/activity_reward_math.php';
require_once __DIR__.'/activity_cover.php';
require_once __DIR__.'/activity_instant_rule.php';
require_once __DIR__.'/activity_instant_status.php';

final class PintogyaActivityThread {
    private $publish;
    private $repo;
    private $reader;

    public function __construct($publish, $repo, $reader) {
        $this->publish = $publish;
        $this->repo = $repo;
        $this->reader = $reader;
    }

    public function prepare($uid, $fid, $token, $sessionDigest, $now) {
        $activity = $this->publish->reserve($uid, $fid, $token, $sessionDigest, $now);
        return array('subject' => 'Togya activity', 'message' => 'Togya activity',
            'activityId' => $activity['id'], 'reservationDigest' => $this->publish->reservationDigest($token));
    }

    public function attachDraft($uid, $fid, $token, $sessionDigest, $tid, $now) {
        return $this->publish->attachDraft($uid, $fid, $token, $sessionDigest, $tid, $now);
    }

    public function markPublished($uid, $fid, $token, $sessionDigest, $tid, $publication, $now) {
        return $this->publish->markPublished($uid, $fid, $token, $sessionDigest, $tid, $publication, $now);
    }

    public function canResumePublication($uid, $tid, $sessionDigest, $now) {
        return $this->publish->canResumePublication($uid, $tid, $sessionDigest, $now);
    }

    public function resumePublication($uid, $tid, $sessionDigest, $now) {
        return $this->publish->resumePublication($uid, $tid, $sessionDigest, $now);
    }

    public function publicProjection($tid, $locale) {
        $map = $this->localMapping($tid);
        $activity = $this->reader->publicActivity($map['activity_id'], $locale, true);
        if((int)$map['stage_version'] !== $activity['stageVersion'] ||
            !hash_equals((string)$map['source_hash'], PintogyaActivityPublish::sourceHash($activity))) {
            throw new RuntimeException('activity_source_changed');
        }
        $displayTitle = self::plainText($activity['title'], 240);
        if($displayTitle === '') { throw new RuntimeException('activity_subject_invalid'); }
        return array('tid'=>$tid,'fid'=>(int)$map['fid'],'activityPk'=>(int)$map['activity_pk'],
            'ownerUid'=>(int)$map['owner_uid'],'publishedAt'=>(int)$map['published_at'],
            'rewardModel' => $this->repo->activityInstantRuleById($activity['id']) !== null ? 'instant' : 'legacy',
            'displayTitle' => $displayTitle, 'activity' => $activity);
    }

    public function localProjection($tid) {
        $map = $this->localMapping($tid);
        return array('tid'=>$tid,'fid'=>(int)$map['fid'],'activityPk'=>(int)$map['activity_pk'],
            'ownerUid'=>(int)$map['owner_uid'],'publishedAt'=>(int)$map['published_at'],
            'activity'=>array('id'=>$map['activity_id'],'stageVersion'=>(int)$map['stage_version']));
    }

    private function localMapping($tid) {
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295) { throw new InvalidArgumentException('activity_tid_invalid'); }
        $map = $this->repo->activityByTid($tid);
        if(!$map || $map['state_code'] !== 'active' || (int)$map['tid'] !== $tid) {
            throw new RuntimeException('activity_mapping_inactive');
        }
        $thread = $this->repo->activityThread($tid, false);
        if(!$thread || (int)$thread['fid'] !== (int)$map['fid'] ||
            (int)$thread['authorid'] !== (int)$map['owner_uid'] ||
            (int)$thread['special'] !== 127 || (int)$thread['displayorder'] < 0 ||
            $this->repo->activityFirstPostMessage($tid) !==
                PintogyaActivityNativePost::messageForDigest((string)$map['reservation_digest'])) {
            throw new RuntimeException('activity_thread_unavailable');
        }
        return $map;
    }

    public function publicCard($tid, $locale, $extcredits, $payoutEnabled = false, $projection = null) {
        global $_G;
        if(!is_array($extcredits)) { throw new InvalidArgumentException('activity_card_credits_invalid'); }
        if($projection === null) { $projection = $this->publicProjection($tid, $locale); }
        if(!is_array($projection) || (int)($projection['tid'] ?? 0) !== $tid) {
            throw new InvalidArgumentException('activity_card_projection_invalid');
        }
        $forumParticipantCount = $this->repo->participationCount($projection['activityPk']);
        $instant = $this->repo->activityInstantRuleById($projection['activity']['id']);
        if($instant) {
            $rule = PintogyaActivityInstantRule::storedSnapshot($instant);
            if($instant['state_code'] !== 'active' || (int)$instant['published_at'] < 1 ||
                (int)$instant['published_at'] !== ($projection['publishedAt'] ?? null) ||
                $rule['stage_version'] !== $projection['activity']['stageVersion']) {
                throw new RuntimeException('instant_rule_publication_changed');
            }
            $creditTitle = strip_tags((string)($extcredits[$rule['credit_id']]['title'] ?? ''));
            if($creditTitle === '' || strlen($creditTitle) > 120) { throw new RuntimeException('activity_card_credit_title_invalid'); }
            $stages = array_values($rule['stages']);
            usort($stages,static function($a,$b) { return $a['position'] <=> $b['position']; });
            return array('displayTitle'=>$projection['displayTitle'],
                'thumbnailUrl'=>PintogyaActivityCover::displayUrl((string)$projection['activity']['thumbnailUrl']),
                'forumParticipantCount'=>$forumParticipantCount,
                'qualifiedParticipantCount'=>null,'confirmedCompletionCount'=>null,
                'rewardAmount'=>$rule['total'],'rewardCredit'=>$creditTitle,'rewardState'=>'open',
                'payoutEnabled'=>(string)($_G['setting'][PintogyaActivityInstantRule::ENABLED_KEY] ?? '0') === '1',
                'rewardModel'=>'instant','baseReward'=>$rule['base'],'stageRewards'=>$stages,
                'finishStoryAvailable'=>$rule['finish_stage_id'] !== null);
        }
        $pool = $this->repo->activityRewardPoolByActivity($projection['activityPk']);
        if(!$pool || !in_array($pool['state_code'] ?? null, array('open', 'closing', 'closed'), true) ||
            self::decimal($pool['activity_pk'] ?? null, PHP_INT_MAX) !== $projection['activityPk']) {
            throw new RuntimeException('activity_card_pool_unavailable');
        }
        $rulePk = self::decimal($pool['rule_pk'] ?? null, PHP_INT_MAX);
        $rule = $this->repo->activityRewardRuleByPk($rulePk);
        if(!$rule || self::decimal($rule['rule_pk'] ?? null, PHP_INT_MAX) !== $rulePk ||
            ($rule['activity_id'] ?? null) !== $projection['activity']['id'] ||
            !is_string($rule['rules_json'] ?? null) ||
            !is_string($rule['rule_hash'] ?? null) ||
            !hash_equals(hash('sha256', "pintogya/activity-reward-rule/v1\0".$rule['rules_json']), $rule['rule_hash']) ||
            !hash_equals($rule['rule_hash'], (string)($pool['rule_hash'] ?? ''))) {
            throw new RuntimeException('activity_card_rule_mismatch');
        }
        try { $defaults = PintogyaActivitySettings::rewardDefaultsFromJson($rule['rules_json'], $extcredits); }
        catch(InvalidArgumentException $error) { throw new RuntimeException('activity_card_rule_mismatch', 0, $error); }
        $creditId = self::decimal($pool['credit_id'] ?? null, 8);
        if($creditId !== $defaults['credit_id'] ||
            self::decimal($rule['credit_id'] ?? null, 8) !== $creditId) {
            throw new RuntimeException('activity_card_credit_mismatch');
        }
        $joined = self::decimal($pool['joined_count'] ?? null, $defaults['rules']['max_participants']);
        $completed = self::decimal($pool['completed_count'] ?? null, $joined);
        $score = self::decimal($pool['score_total'] ?? null, $defaults['rules']['max_score']);
        $current = PintogyaActivityRewardMath::calculate($defaults['rules'], $joined, $completed, $score);
        $reward = self::decimal($pool['target_amount'] ?? null, $defaults['rules']['max_member_reward']);
        $paid = self::decimal($pool['paid_total'] ?? null, $defaults['rules']['max_total_budget']);
        if($reward < $current['per_member'] || $paid > $defaults['rules']['max_total_budget']) {
            throw new RuntimeException('activity_card_reward_mismatch');
        }
        $creditTitle = strip_tags((string)($extcredits[$creditId]['title'] ?? ''));
        if($creditTitle === '' || strlen($creditTitle) > 120) {
            throw new RuntimeException('activity_card_credit_title_invalid');
        }
        return array('displayTitle' => $projection['displayTitle'],
            'thumbnailUrl' => PintogyaActivityCover::displayUrl((string)$projection['activity']['thumbnailUrl']),
            'forumParticipantCount' => $forumParticipantCount,
            'qualifiedParticipantCount' => $joined, 'confirmedCompletionCount' => $completed,
            'rewardAmount' => $reward, 'rewardCredit' => $creditTitle,
            'rewardState' => $pool['state_code'], 'payoutEnabled' => $payoutEnabled === true);
    }

    public function viewerState($uid, $tid, $locale) {
        $projection = $this->publicProjection($tid, $locale);
        $readOnly = $this->repo->activityInstantRuleById($projection['activity']['id']) !== null;
        return $this->reader->viewerState($uid, $projection['activity']['id'], $locale,
            $projection['activity']['stageVersion'], $readOnly);
    }

    public function stageFeed($uid, $tid, $locale, $stageId, $cursor = '', $allowCache = false) {
        $projection = $this->publicProjection($tid, $locale);
        $readOnly = $this->repo->activityInstantRuleById($projection['activity']['id']) !== null;
        return $this->reader->stageFeed($uid, $projection['activity']['id'], $locale,
            $projection['activity']['stageVersion'], $stageId, $cursor, $allowCache, $readOnly);
    }

    public function stageImage($uid, $tid, $locale, $stageId, $cursor, $checkinId, $imageIndex = 0) {
        $projection = $this->publicProjection($tid, $locale);
        $readOnly = $this->repo->activityInstantRuleById($projection['activity']['id']) !== null;
        return $this->reader->stageImage($uid, $projection['activity']['id'], $locale,
            $projection['activity']['stageVersion'], $stageId, $cursor, $checkinId, $readOnly, $imageIndex);
    }

    public function instantRewardStatus($uid, $tid, $locale) {
        // Displaying existing native ledger entries requires no Togya request or display-cache lease.
        $projection = $this->localProjection($tid);
        $row = $this->repo->activityInstantRuleById($projection['activity']['id']);
        if(PintogyaActivityInstantRule::storedSnapshot($row)['stage_version']!==$projection['activity']['stageVersion']) {
            throw new RuntimeException('instant_rule_publication_changed');
        }
        return (new PintogyaActivityInstantStatus())->forMember($uid, $projection, $row);
    }

    private static function plainText($value, $maxBytes) {
        if(!is_string($value) || strlen($value) > $maxBytes * 4) { throw new RuntimeException('activity_text_invalid'); }
        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(array('[', ']'), array('(', ')'), $text);
        $text = preg_replace('/[\p{C}\s]+/u', ' ', $text);
        if($text === null) { throw new RuntimeException('activity_text_invalid'); }
        $text = trim($text);
        if(strlen($text) > $maxBytes) { throw new RuntimeException('activity_text_invalid'); }
        return $text;
    }

    private static function decimal($value, $maximum) {
        if(!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value))) {
            throw new RuntimeException('activity_card_number_invalid');
        }
        $number = (int)$value;
        if($number < 0 || $number > $maximum || (string)$number !== (string)$value) {
            throw new RuntimeException('activity_card_number_invalid');
        }
        return $number;
    }
}
