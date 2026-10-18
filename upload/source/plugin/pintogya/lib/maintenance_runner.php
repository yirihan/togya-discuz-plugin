<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/site_profile.php';

final class PintogyaMaintenanceRunner {
    const MAX_OUTPUT_BYTES = 65536;
    const TASKS = array('pintogya.public_cover', 'pintogya.membership_reconcile', 'pintogya.review_timeout',
        'pintogya.reward_promotion', 'pintogya.reward_topup', 'pintogya.instant_rewards', 'pintogya.session_keepalive','pintogya.display_cache');

    public static function run($task, $limit, $maxSeconds = null, $command = null) {
        if($maxSeconds === null) {
            $args = defined('TOGYA_PLUGIN_MAINTENANCE_CLI_ARGS_JSON') ?
                json_decode(TOGYA_PLUGIN_MAINTENANCE_CLI_ARGS_JSON, true) : array();
            $configured = is_array($args) ? ($args['pintogya-max-seconds'] ?? '30') : '30';
            if(!is_string($configured) || !preg_match('/^(?:[1-9]|[12][0-9]|30)$/D', $configured)) {
                throw new InvalidArgumentException('pintogya_supervision_arguments_invalid');
            }
            $maxSeconds = (int)$configured;
        }
        if(PHP_SAPI !== 'cli' || !in_array($task, self::TASKS, true) ||
            !is_int($limit) || $limit < 1 || $limit > 20 ||
            !is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 30) {
            throw new InvalidArgumentException('pintogya_supervision_arguments_invalid');
        }
        if(!function_exists('proc_open') || !function_exists('proc_terminate')) {
            throw new RuntimeException('pintogya_supervision_unavailable');
        }
        if($command !== null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('pintogya_supervision_command_forbidden');
        }
        if($command === null) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            global $_G;
            if(!is_string($host) || $host !== PintogyaSiteProfile::hostFromSettings($_G['setting'] ?? array())) {
                throw new RuntimeException('pintogya_supervision_host_invalid');
            }
            $root = realpath(DISCUZ_ROOT);
            $script = $root.'/source/plugin/pintogya/maintenance.php';
            if(!$root || !is_file($script)) throw new RuntimeException('pintogya_supervision_entry_missing');
            $command = array(PHP_BINARY, $script, 'worker', '--site='.$root,
                '--host='.$host, '--task='.$task, '--limit='.$limit);
        }
        if(!is_array($command) || !$command || array_keys($command) !== range(0, count($command)-1)) {
            throw new InvalidArgumentException('pintogya_supervision_command_invalid');
        }
        foreach($command as $arg) {
            if(!is_string($arg) || $arg === '' || strpos($arg, "\0") !== false) {
                throw new InvalidArgumentException('pintogya_supervision_command_invalid');
            }
        }
        $started = hrtime(true) / 1000000000;
        // TOGYA_PLUGIN pintogya_worker_wall_clock: SQL waits count toward the cap.
        // Reserve cleanup time inside the requested wall-clock cap.
        $deadline = $started + $maxSeconds;
        $stopAt = $deadline - min(0.2, $maxSeconds / 4);
        $pipes = array();
        $process = proc_open($command, array(0 => array('pipe','r'),
            1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
        if(!is_resource($process)) throw new RuntimeException('pintogya_supervision_start_failed');
        $stdout = ''; $stderrBytes = 0; $exitCode = null; $failure = null;
        try {
            fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            while(true) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderrBytes += strlen(stream_get_contents($pipes[2]));
                if(strlen($stdout) > self::MAX_OUTPUT_BYTES || $stderrBytes > self::MAX_OUTPUT_BYTES) {
                    $failure = 'pintogya_worker_output_limit'; break;
                }
                $status = proc_get_status($process);
                if(!$status['running']) { $exitCode = $status['exitcode']; break; }
                if(hrtime(true) / 1000000000 >= $stopAt) {
                    $failure = 'pintogya_worker_time_limit'; break;
                }
                usleep(10000);
            }
            if($failure !== null) {
                proc_terminate($process, 15);
                while(hrtime(true) / 1000000000 < $deadline) {
                    if(!proc_get_status($process)['running']) break;
                    usleep(5000);
                }
                if(proc_get_status($process)['running']) proc_terminate($process, 9);
            } else {
                $stdout .= stream_get_contents($pipes[1]);
                $stderrBytes += strlen(stream_get_contents($pipes[2]));
            }
        } finally {
            foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
            $closeCode = proc_close($process);
        }
        if($failure !== null) return self::unknown($failure);
        if(strlen($stdout) > self::MAX_OUTPUT_BYTES || $stderrBytes > self::MAX_OUTPUT_BYTES ||
            ($exitCode !== 0 && $closeCode !== 0)) {
            return self::unknown('pintogya_worker_exit_unknown');
        }
        try { $payload = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { return self::unknown('pintogya_worker_response_invalid'); }
        if(!is_array($payload) || ($payload['ret'] ?? null) !== 0 ||
            !isset($payload['result']) || !is_array($payload['result'])) {
            return self::unknown('pintogya_worker_response_invalid');
        }
        return self::publicResult($payload['result']);
    }

    public static function publicResult($result) {
        if(!is_array($result)) throw new RuntimeException('pintogya_worker_result_invalid');
        // These workers return counters/cursors, never source bodies or credentials.
        $safe = array_intersect_key($result, array_flip(array('state', 'skipped', 'scanned',
            'posted', 'pools', 'closed', 'approved', 'held', 'deactivated', 'cursor', 'established', 'renewed')));
        foreach(array('cached','removed','membershipDeferred','authDeferred') as $key) {
            if(isset($result[$key]) && is_int($result[$key]) && $result[$key]>=0 && $result[$key]<=512) { $safe[$key]=$result[$key]; }
        }
        if(isset($result['membership'])) {
            $profile = $result['membership'];
            if(!is_array($profile) || array_keys($profile) !== array('schema','isolated','deferred','service','unknown') || $profile['schema'] !== 1) {
                throw new RuntimeException('pintogya_worker_membership_profile_invalid');
            }
            foreach(array('isolated','deferred','service','unknown') as $key) {
                if(!is_int($profile[$key]) || $profile[$key] < 0 || $profile[$key] > 5) { throw new RuntimeException('pintogya_worker_membership_profile_invalid'); }
            }
            if(array_sum(array_slice($profile,1)) !== ($result['held'] ?? null)) { throw new RuntimeException('pintogya_worker_membership_profile_invalid'); }
            $safe['membership']=$profile;
        }
        // TOGYA_PLUGIN pintogya_instant_discovery_diagnostics: aggregate progress only, never provider bodies.
        if(isset($result['discovery']) && is_array($result['discovery'])) {
            $discovery = $result['discovery'];
            $safe['discovery'] = array('state'=>in_array($discovery['state'] ?? null,
                array('observed','disabled','awaiting_schema','time_bound','locked'),true)
                ? $discovery['state'] : 'outcome_unknown');
            foreach(array('scanned','confirmed','not_joined','held') as $key) {
                if(isset($discovery[$key]) && is_int($discovery[$key]) &&
                    $discovery[$key] >= 0 && $discovery[$key] <= 2) {
                    $safe['discovery'][$key]=$discovery[$key];
                }
            }
        }
        if(array_key_exists('errors', $result)) {
            $safe['errors'] = array();
            foreach((array)$result['errors'] as $error) {
                $row = array('code' => 'pintogya_worker_record_failed');
                $codes = array('instant_read_session_expired','instant_read_permission_unavailable',
                    'binding_inactive','instant_binding_changed','instant_partial_history','instant_journey_order_invalid',
                    'instant_history_page_invalid','instant_history_order_invalid',
                    'instant_history_cursor_invalid','instant_history_checkpoint_invalid',
                    'instant_stage_plan_changed','instant_source_unavailable_or_changed','api_unauthorized',
                    'instant_source_temporary_failure','instant_source_rate_limited','instant_worker_record_unknown',
                    'instant_rule_changed_or_held','instant_forum_participation_missing',
                    'instant_activity_unpublished','instant_credit_unconfigured','instant_reward_key_mismatch',
                    'instant_posted_entry_mismatch','credit_log_cardinality_invalid','instant_binding_inactive',
                    'instant_entitlement_conflict','instant_credit_balance_mismatch',
                    'session_renewal_rejected','session_renewal_daily_retry','session_renewal_temporary_failure','auth_refresh_budget_wait',
                    'instant_discovery_binding_unavailable','instant_discovery_source_invalid',
                    'instant_discovery_confirmation_missing','instant_discovery_temporary_failure','instant_discovery_rate_limited',
                    'instant_discovery_record_unknown','instant_discovery_schema_missing',
                    'membership_binding_unavailable','membership_activity_unavailable','membership_service_unavailable','membership_outcome_unknown');
                if(is_array($error) && in_array($error['code'] ?? null,$codes,true)) { $row['code']=$error['code']; }
                foreach(array('activity_pk', 'reward_pk', 'review_pk', 'participant_pk', 'sync_pk') as $key) {
                    if(is_array($error) && isset($error[$key]) && is_int($error[$key]) && $error[$key] > 0) {
                        $row[$key] = $error[$key];
                    }
                }
                $safe['errors'][] = $row;
            }
        }
        return $safe;
    }

    public static function unknown($code) {
        return array('state' => 'outcome_unknown', 'errors' => array(array('code' => $code)));
    }
}
