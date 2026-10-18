<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaTaskFacts {
    const MAX_ACTIVITIES = 1000;
    const MAX_TASK_FACT_REQUESTS = 24;
    const MAX_STAGES_PER_ACTIVITY = 100;
    const MAX_FEED_ITEMS = 60;
    const MAX_ANSWERS_PER_QUESTION = 200;
    private $binding;
    private $session;
    private $contexts;

    public function __construct($binding, $session, $contexts) {
        $this->binding = $binding;
        $this->session = $session;
        $this->contexts = $contexts;
    }

    public function youtubeConnected($uid) {
        try {
            $body = $this->session->connections($uid);
            self::requireKeys($body, array('connections'));
            if(!is_array($body['connections']) || count($body['connections']) > 8) { return false; }
            $matches = 0;
            foreach($body['connections'] as $connection) {
                self::requireKeys($connection, array('configured','connected','provider','status'));
                if($connection['provider'] === 'youtube' && $connection['configured'] === true && $connection['connected'] === true && $connection['status'] === 'active') { $matches++; }
            }
            return $matches === 1;
        } catch(Throwable $error) {
            return false;
        }
    }

    public function prepareYoutubeImport($uid, $taskId, $taskInstance, $locale) {
        $binding = $this->session->binding($uid);
        $bootstrap = $this->bootstrap($uid, $locale, $binding['togya_user_id']);
        $baseline = array();
        foreach($bootstrap['activities'] as $activity) { $baseline[] = $this->activityId($activity); }
        $baseline = array_values(array_unique($baseline));
        sort($baseline, SORT_STRING);
        return $this->contexts->put($uid, $taskId, $taskInstance, 'youtube_import_public', intval($binding['generation']), array('baseline' => $baseline), 'prepared');
    }

    public function youtubeImportPublic($uid, $taskId, $taskInstance, $locale) {
        try {
            $context = $this->activeContext($uid, $taskId, $taskInstance);
            $binding = $this->session->binding($uid);
            $this->assertGeneration($context, $binding);
            $bootstrap = $this->bootstrap($uid, $locale, $binding['togya_user_id']);
            if($context['matched_activity_id'] !== '') {
                $activity = $this->findActivity($bootstrap['activities'], $context['matched_activity_id']);
                return $activity !== null && $this->isQualifyingYoutubeActivity($activity, $binding['togya_user_id']);
            }
            $candidates = array();
            $baseline = array_fill_keys($context['baseline'], true);
            foreach($bootstrap['activities'] as $activity) {
                $id = $this->activityId($activity);
                if(!isset($baseline[$id]) && $this->isQualifyingYoutubeActivity($activity, $binding['togya_user_id'])) { $candidates[$id] = $activity; }
            }
            if(!$candidates) { return false; }
            ksort($candidates, SORT_STRING);
            $activityId = (string)array_key_first($candidates);
            $this->contexts->setMatchedActivity($uid, $taskId, $taskInstance, $activityId);
            return true;
        } catch(Throwable $error) {
            return false;
        }
    }

    public function youtubeImportThreeParticipants($uid, $taskThreeId, $taskThreeInstance, $locale) {
        try {
            $context = $this->activeContext($uid, $taskThreeId, $taskThreeInstance);
            if($context['matched_activity_id'] === '') { return false; }
            $binding = $this->session->binding($uid);
            $this->assertGeneration($context, $binding);
            $bootstrap = $this->bootstrap($uid, $locale, $binding['togya_user_id']);
            $activity = $this->findActivity($bootstrap['activities'], $context['matched_activity_id']);
            return $activity !== null && $this->isQualifyingYoutubeActivity($activity, $binding['togya_user_id'])
                && isset($activity['participantCount']) && is_int($activity['participantCount']) && $activity['participantCount'] >= 3;
        } catch(Throwable $error) {
            return false;
        }
    }

    public function answeredOtherQuestion($uid, $locale) {
        try {
            $binding = $this->session->binding($uid);
            $bootstrap = $this->bootstrap($uid, $locale, $binding['togya_user_id']);
            $seenStages = array();
            $requestCount = 0;
            foreach($bootstrap['activities'] as $activity) {
                self::requireKeys($activity, array('id','stageVersion','stageDetails'));
                $activityId = $this->activityId($activity);
                if(!is_int($activity['stageVersion']) || $activity['stageVersion'] < 1
                    || !is_array($activity['stageDetails']) || count($activity['stageDetails']) > self::MAX_STAGES_PER_ACTIVITY) {
                    throw new RuntimeException('activity_stage_shape_invalid');
                }
                foreach($activity['stageDetails'] as $stage) {
                    self::requireKeys($stage, array('id','version','canViewFeed'));
                    if($stage['id'] === null || $stage['canViewFeed'] === false) { continue; }
                    if($stage['canViewFeed'] !== true || !PintogyaCrypto::validOpaqueId($stage['id'])
                        || !is_int($stage['version']) || $stage['version'] !== $activity['stageVersion']) {
                        throw new RuntimeException('activity_stage_shape_invalid');
                    }
                    $stageKey = $activityId."\0".$stage['id']."\0".$stage['version'];
                    if(isset($seenStages[$stageKey])) { continue; }
                    $seenStages[$stageKey] = true;
                    if($requestCount >= self::MAX_TASK_FACT_REQUESTS) { return false; }
                    $requestCount++;
                    $feed = $this->session->stageFeed($uid, $activityId, $stage['id'], $stage['version']);
                    if($this->feedContainsOtherQuestionAnswer($feed, $activityId, $stage['id'], $stage['version'], $binding['togya_user_id'])) {
                        return true;
                    }
                }
            }
            return false;
        } catch(Throwable $error) {
            return false;
        }
    }

    public function createdActivityThreeCompletions($uid, $locale) {
        try {
            $binding = $this->session->binding($uid);
            $bootstrap = $this->bootstrap($uid, $locale, $binding['togya_user_id']);
            $requestCount = 0;
            foreach($bootstrap['activities'] as $activity) {
                if(!$this->isSelfActivity($activity, $binding['togya_user_id'])) { continue; }
                self::requireKeys($activity, array('progress'));
                if((!is_int($activity['progress']) && !is_float($activity['progress'])) || $activity['progress'] < 0 || $activity['progress'] > 1) {
                    throw new RuntimeException('activity_owner_progress_invalid');
                }
                if($requestCount >= self::MAX_TASK_FACT_REQUESTS) { return false; }
                $requestCount++;
                $activityId = $this->activityId($activity);
                $summary = $this->session->activityFinishStory($uid, $activityId);
                self::requireKeys($summary, array('activityId','completedCount','stories'));
                if(!PintogyaCrypto::validOpaqueId($summary['activityId'])
                    || !hash_equals($activityId, (string)$summary['activityId'])
                    || !is_int($summary['completedCount']) || $summary['completedCount'] < 0
                    || !is_array($summary['stories']) || count($summary['stories']) > 1) {
                    throw new RuntimeException('activity_completion_shape_invalid');
                }
                $participantCompletions = $summary['completedCount'] - ($activity['progress'] >= 1 ? 1 : 0);
                if($participantCompletions < 0) { throw new RuntimeException('activity_completion_count_invalid'); }
                if($participantCompletions >= 3) { return true; }
            }
            return false;
        } catch(Throwable $error) {
            return false;
        }
    }

    private function feedContainsOtherQuestionAnswer($feed, $activityId, $stageId, $stageVersion, $expectedUserId) {
        self::requireKeys($feed, array('activityId','stageId','stageVersion','items'));
        if(!PintogyaCrypto::validOpaqueId($feed['activityId']) || !PintogyaCrypto::validOpaqueId($feed['stageId'])
            || !hash_equals((string)$activityId, (string)$feed['activityId']) || !hash_equals((string)$stageId, (string)$feed['stageId'])
            || !is_int($feed['stageVersion']) || $feed['stageVersion'] !== intval($stageVersion)
            || !is_array($feed['items']) || count($feed['items']) > self::MAX_FEED_ITEMS) {
            throw new RuntimeException('stage_feed_shape_invalid');
        }
        foreach($feed['items'] as $item) {
            self::requireKeys($item, array('id','kind','author','answers'));
            if($item['kind'] !== 'question') { continue; }
            self::requireKeys($item['author'], array('id'));
            if(!PintogyaCrypto::validOpaqueId($item['id']) || !PintogyaCrypto::validOpaqueId($item['author']['id'])
                || !is_array($item['answers']) || count($item['answers']) > self::MAX_ANSWERS_PER_QUESTION) {
                throw new RuntimeException('stage_question_shape_invalid');
            }
            foreach($item['answers'] as $answer) {
                self::requireKeys($answer, array('id','questionId','author'));
                self::requireKeys($answer['author'], array('id'));
                if(!PintogyaCrypto::validOpaqueId($answer['id']) || !PintogyaCrypto::validOpaqueId($answer['questionId'])
                    || !PintogyaCrypto::validOpaqueId($answer['author']['id'])
                    || !hash_equals((string)$item['id'], (string)$answer['questionId'])) {
                    throw new RuntimeException('stage_answer_shape_invalid');
                }
                if(!hash_equals((string)$expectedUserId, (string)$item['author']['id'])
                    && hash_equals((string)$expectedUserId, (string)$answer['author']['id'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function bootstrap($uid, $locale, $expectedUserId) {
        $body = $this->session->bootstrap($uid, $locale);
        self::requireKeys($body, array('activities','user'));
        self::requireKeys($body['user'], array('id'));
        if(!hash_equals((string)$expectedUserId, (string)$body['user']['id']) || !is_array($body['activities']) || count($body['activities']) > self::MAX_ACTIVITIES) { throw new RuntimeException('bootstrap_invalid'); }
        $seen = array();
        $unique = array();
        foreach($body['activities'] as $activity) {
            $id = $this->activityId($activity);
            $fingerprint = PintogyaCrypto::digest('pintogya/bootstrap-activity/v1', $activity);
            if(isset($seen[$id])) {
                if(!hash_equals($seen[$id], $fingerprint)) { throw new RuntimeException('activity_duplicate_conflict'); }
                continue;
            }
            $seen[$id] = $fingerprint;
            $unique[] = $activity;
        }
        $body['activities'] = $unique;
        return $body;
    }

    private function isQualifyingYoutubeActivity($activity, $expectedUserId) {
        return $this->isSelfActivity($activity, $expectedUserId)
            && isset($activity['tutorialProvider'], $activity['isPublic'], $activity['status'])
            && $activity['tutorialProvider'] === 'youtube' && $activity['isPublic'] === true && $activity['status'] === 'active';
    }

    private function isSelfActivity($activity, $expectedUserId) {
        self::requireKeys($activity, array('creator','id'));
        self::requireKeys($activity['creator'], array('id','isSelf'));
        return PintogyaCrypto::validOpaqueId($activity['id']) && PintogyaCrypto::validOpaqueId($activity['creator']['id'])
            && $activity['creator']['isSelf'] === true && hash_equals((string)$expectedUserId, (string)$activity['creator']['id']);
    }

    private function activityId($activity) {
        self::requireKeys($activity, array('id'));
        if(!PintogyaCrypto::validOpaqueId($activity['id'])) { throw new RuntimeException('activity_id_invalid'); }
        return $activity['id'];
    }

    private function findActivity($activities, $activityId) {
        foreach($activities as $activity) {
            if(hash_equals((string)$activityId, (string)$this->activityId($activity))) { return $activity; }
        }
        return null;
    }

    private function activeContext($uid, $taskId, $taskInstance) {
        $context = $this->contexts->get($uid, $taskId, $taskInstance);
        if(!$context || !in_array($context['state_code'], array('active','completed'), true) || !hash_equals('youtube_import_public', (string)$context['task_key'])) {
            throw new RuntimeException('task_context_inactive');
        }
        return $context;
    }

    private function assertGeneration($context, $binding) {
        if(intval($context['binding_generation']) !== intval($binding['generation'])) { throw new RuntimeException('binding_generation_changed'); }
    }

    private static function requireKeys($value, $keys) {
        if(!is_array($value)) { throw new RuntimeException('api_shape_invalid'); }
        foreach($keys as $key) {
            if(!array_key_exists($key, $value)) { throw new RuntimeException('api_field_missing'); }
        }
    }
}
