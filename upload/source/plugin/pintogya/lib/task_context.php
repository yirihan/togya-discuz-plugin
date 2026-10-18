<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaTaskContext {
    private $repo;

    public function __construct($repo) { $this->repo = $repo; }

    public function put($uid, $taskId, $taskInstance, $taskKey, $generation, $data, $state = 'prepared') {
        self::assertIdentity($uid, $taskId, $taskInstance, $taskKey, $generation);
        if(!in_array($state, array('prepared','active'), true) || !is_array($data)) { throw new InvalidArgumentException('task_context_input'); }
        $now = time();
        $this->repo->cleanupPreparedContexts($uid, $now - 3600);
        $row = array(
            'uid' => intval($uid),
            'task_id' => intval($taskId),
            'task_instance' => $taskInstance,
            'task_key' => $taskKey,
            'binding_generation' => intval($generation),
            'state_code' => $state,
            'baseline_json' => isset($data['baseline']) ? PintogyaCrypto::canonicalJson(array_values($data['baseline'])) : '[]',
            'matched_activity_id' => isset($data['matchedActivityId']) ? (string)$data['matchedActivityId'] : '',
            'activity_id' => isset($data['activityId']) ? (string)$data['activityId'] : '',
            'stage_id' => isset($data['stageId']) ? (string)$data['stageId'] : '',
            'stage_version' => isset($data['stageVersion']) ? intval($data['stageVersion']) : 0,
            'question_id' => isset($data['questionId']) ? (string)$data['questionId'] : '',
            'assigned_at' => isset($data['assignedAt']) ? intval($data['assignedAt']) : 0,
            'created_at' => $now,
            'updated_at' => $now,
        );
        foreach(array('matched_activity_id','activity_id','stage_id','question_id') as $column) {
            if($row[$column] !== '' && !PintogyaCrypto::validOpaqueId($row[$column])) { throw new InvalidArgumentException('task_context_id'); }
        }
        $this->repo->storeContext($row);
        return $this->get($uid, $taskId, $taskInstance);
    }

    public function get($uid, $taskId, $taskInstance) {
        $row = $this->repo->context($uid, $taskId, $taskInstance, false);
        if(!$row) { return null; }
        try { $baseline = json_decode((string)$row['baseline_json'], true, 64, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('task_context_corrupt'); }
        if(!is_array($baseline)) { throw new RuntimeException('task_context_corrupt'); }
        $row['baseline'] = $baseline;
        return $row;
    }

    public function activate($uid, $taskId, $taskInstance) {
        $this->repo->updateContext($uid, $taskId, $taskInstance, array('state_code' => 'active', 'updated_at' => time()), 'prepared');
    }

    public function setMatchedActivity($uid, $taskId, $taskInstance, $activityId) {
        if(!PintogyaCrypto::validOpaqueId($activityId)) { throw new InvalidArgumentException('activity_id_invalid'); }
        $this->repo->updateContext($uid, $taskId, $taskInstance, array('matched_activity_id' => $activityId, 'updated_at' => time()), 'active');
    }

    public function completeAndRekey($uid, $taskId, $taskInstance, $completedInstance) {
        self::assertInstance($completedInstance);
        $this->repo->completeAndRekeyContext($uid, $taskId, $taskInstance, $completedInstance);
    }

    private static function assertIdentity($uid, $taskId, $taskInstance, $taskKey, $generation) {
        if(intval($uid) < 1 || intval($taskId) < 1 || intval($generation) < 1 || !is_string($taskInstance)
            || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $taskInstance) || $taskKey !== 'youtube_import_public') {
            throw new InvalidArgumentException('task_context_identity');
        }
    }

    private static function assertInstance($taskInstance) {
        if(!is_string($taskInstance) || !preg_match('/^[1-9][0-9]{0,10}$/D', $taskInstance)) { throw new InvalidArgumentException('task_context_instance'); }
    }
}
