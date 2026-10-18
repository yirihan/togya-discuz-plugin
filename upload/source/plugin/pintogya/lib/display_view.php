<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaDisplayView {
    private $repo;
    private $thread;
    private $session;
    private $cache;
    public function __construct($repo,$thread,$session,$cache) {
        $this->repo=$repo; $this->thread=$thread; $this->session=$session; $this->cache=$cache;
    }
    public function viewerState($uid,$tid,$locale) {
        if(!is_int($uid) || $uid<1 || $uid>16777215) { throw new RuntimeException('activity_login_required'); }
        $projection=$this->thread->localProjection($tid);
        try { $bound=$this->session->binding($uid); }
        catch(RuntimeException $error) {
            if($error->getMessage()!=='binding_inactive') { throw $error; }
            return array('state'=>'not_joined','stages'=>array(),'cacheScope'=>'','forumJoined'=>false,'syncPending'=>false,'expiresAt'=>0);
        }
        $identity=PintogyaMemberDisplaySession::identity($bound);
        $participant=$this->repo->participationForUid($projection['activityPk'],$uid,false);
        $local=$participant && ($participant['state_code'] ?? null)==='active' &&
            (int)($participant['uid'] ?? 0)===$uid && ($participant['togya_user_id'] ?? null)===$identity['subject'] &&
            (int)($participant['binding_generation'] ?? 0)===$identity['generation'] &&
            (int)($participant['stage_version'] ?? 0)===$projection['activity']['stageVersion'];
        try {
            $viewer=$this->thread->viewerState($uid,$tid,$locale);
            $viewer['forumJoined']=$viewer['state']==='joined' && (bool)$local;
            $viewer['forumJoinPending']=$viewer['state']==='joined' && !$local &&
                $this->repo->activityInstantRuleById($projection['activity']['id'])!==null;
            $viewer['syncPending']=false;
            $request=PintogyaDisplayCache::request('member_detail',$projection['activity']['id'],$locale,0,'','','',$identity);
            $entry=$this->cache->peek($request);
            if(!$entry) { throw new RuntimeException('activity_display_pending'); }
            $viewer['expiresAt']=$entry['expiresAt'];
        } catch(RuntimeException $error) {
            if(PintogyaMemberDisplaySession::identity($this->session->binding($uid))!==$identity) { throw new RuntimeException('binding_changed'); }
            // A durable forum participation fact grants no stage data. Never turn a failed read into a fresh QR demand.
            if(!$local) { throw $error; }
            $viewer=array('state'=>'joined','stages'=>array(),'cacheScope'=>'','forumJoined'=>true,
                'syncPending'=>true,'expiresAt'=>0);
        }
        $viewer['accountRef']=$identity['subject'];
        return $viewer;
    }
    public function participants($tid,$after) {
        if(!is_int($after) || $after<0) { throw new InvalidArgumentException('activity_participants_request_invalid'); }
        $projection=$this->thread->localProjection($tid);
        $rows=$this->repo->participationPage($projection['activityPk'],$after,20);
        $more=count($rows)>20; if($more) { array_pop($rows); }
        $items=array();
        foreach($rows as $row) {
            if((int)($row['uid'] ?? 0)<1 || (int)($row['participant_pk'] ?? 0)<=$after || (int)($row['last_verified_at'] ?? 0)<1) {
                throw new RuntimeException('activity_participants_row_invalid');
            }
            $items[]=array('uid'=>(int)$row['uid']);
        }
        $last=$more?end($rows):null;
        return array('items'=>$items,'nextCursor'=>$last?(string)$last['participant_pk']:'0');
    }

    public function confirmsStage($uid,$tid,$locale,$stageId) {
        try {
            $viewer=$this->viewerState($uid,$tid,$locale);
            if($viewer['state']!=='joined' || $viewer['syncPending']) { return false; }
            foreach($viewer['stages'] as $stage) {
                if($stage['id']===$stageId && $stage['readable'] && $stage['feedAvailable']) { return true; }
            }
        } catch(Throwable $error) { /* Missing/revoked parent permission is never a child-only cache miss. */ }
        return false;
    }
}
