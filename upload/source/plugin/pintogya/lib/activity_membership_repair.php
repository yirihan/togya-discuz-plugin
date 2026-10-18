<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_membership_failure.php';

final class PintogyaActivityMembershipRepair {
    private $audit;
    private $repo;
    private $join;

    public function __construct($audit, $repo, $join) {
        $this->audit = $audit;
        $this->repo = $repo;
        $this->join = $join;
    }

    public function runPage($tid, $afterPk, $limit, $locale) {
        if(!is_int($tid) || $tid < 1 || $tid > 4294967295 || !is_int($afterPk) || $afterPk < 0 ||
            !is_int($limit) || $limit < 1 || $limit > 5 || !is_string($locale) ||
            !preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $locale)) {
            throw new InvalidArgumentException('activity_membership_repair_input_invalid');
        }
        $page = $this->audit->scanPage($tid, $afterPk, $limit, $locale);
        if(!is_array($page) || !is_int($page['activityPk'] ?? null) || $page['activityPk'] < 1 ||
            !is_array($page['items'] ?? null) || count($page['items']) > $limit ||
            !is_int($page['nextCursor'] ?? null) || !is_bool($page['hasMore'] ?? null)) {
            throw new RuntimeException('activity_membership_repair_page_invalid');
        }
        $result = array('scanned' => 0, 'deactivated' => 0, 'unresolved' => 0,
            'nextCursor' => $page['nextCursor'], 'hasMore' => $page['hasMore'], 'errors' => array(),
            'faults' => PintogyaMembershipFailure::counters());
        $cursor = $afterPk;
        foreach($page['items'] as $item) {
            $pk = $item['participantPk'] ?? null;
            $state = $item['state'] ?? null;
            if(!is_int($pk) || $pk <= $cursor ||
                !in_array($state, array('joined', 'not_joined', 'identity_changed', 'unknown'), true)) {
                throw new RuntimeException('activity_membership_repair_page_invalid');
            }
            $cursor = $pk;
            $result['scanned']++;
            if($state === 'identity_changed' || $state === 'unknown') {
                $result['unresolved']++;
                $fault = $state === 'identity_changed' ? array('scope'=>'isolated','code'=>'membership_binding_unavailable') :
                    ($item['failure'] ?? array('scope'=>'unknown','code'=>'membership_outcome_unknown'));
                if(!PintogyaMembershipFailure::valid($fault)) { throw new RuntimeException('activity_membership_repair_fault_invalid'); }
                $result['faults'][$fault['scope']]++;
                $result['errors'][] = array('participant_pk'=>$pk,'code'=>$fault['code']);
                continue;
            }
            if($state !== 'not_joined') { continue; }
            try {
                $row = $this->repo->participationByPk($pk);
                if(!is_array($row) || (int)($row['activity_pk'] ?? 0) !== $page['activityPk'] ||
                    (int)($row['participant_pk'] ?? 0) !== $pk || (int)($row['uid'] ?? 0) < 1) {
                    throw new RuntimeException('activity_membership_repair_row_changed');
                }
                if($row['state_code'] !== 'active') { continue; }
                $viewer = $this->join->viewerState((int)$row['uid'], $tid, $locale,
                    $this->repo->currentUnixTime());
                if(!is_array($viewer) || !in_array($viewer['state'] ?? null,
                    array('joined', 'not_joined'), true)) {
                    throw new RuntimeException('activity_membership_repair_viewer_invalid');
                }
                if($viewer['state'] === 'not_joined') {
                    $current = $this->repo->participationByPk($pk);
                    if(!is_array($current) || (int)$current['activity_pk'] !== $page['activityPk'] ||
                        (int)$current['uid'] !== (int)$row['uid'] || $current['state_code'] !== 'inactive') {
                        throw new RuntimeException('activity_membership_repair_not_committed');
                    }
                    $result['deactivated']++;
                }
            } catch(Throwable $error) {
                $fault = PintogyaMembershipFailure::classify($error);
                $result['faults'][$fault['scope']]++;
                $result['errors'][] = array('participant_pk' => $pk, 'code' => $fault['code']);
                $result['unresolved']++;
                if(in_array($fault['scope'],array('service','unknown'),true)) {
                    $result['nextCursor'] = $afterPk; $result['hasMore'] = true; break;
                }
            }
        }
        if(!$result['faults']['service'] && !$result['faults']['unknown'] && $cursor !== $page['nextCursor']) {
            throw new RuntimeException('activity_membership_repair_page_invalid');
        }
        if($result['faults']['service'] || $result['faults']['unknown']) { $result['nextCursor']=$afterPk; $result['hasMore']=true; }
        return $result;
    }
}
