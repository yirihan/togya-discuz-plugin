<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/crypto.php';

final class PintogyaCheckinInteractions {
    private $thread;private $public;private $store;private $member;
    public function __construct($thread,$public,$store,$member=null) {
        $this->thread=$thread;$this->public=$public;$this->store=$store;$this->member=$member;
    }

    private function sources($actorUid,$activity,$stageId,$cursor,$locale) {
        try { return $this->public->checkinSources($activity['id'],$activity['stageVersion'],$locale,$stageId,$cursor); }
        catch(RuntimeException $error) {
            if($error->getMessage()!=='activity_public_feed_unavailable'||!$this->member||$actorUid<1)throw $error;
            return $this->member->checkinSources($actorUid,$activity['id'],$locale,$activity['stageVersion'],$stageId,$cursor);
        }
    }

    private function source($actorUid,$tid,$stageId,$checkinId,$cursor,$locale) {
        if(!is_int($tid)||$tid<1||!is_string($cursor)||($cursor!==''&&!preg_match('/^[A-Za-z0-9_-]{1,2048}$/D',$cursor))) {
            throw new InvalidArgumentException('checkin_interaction_input_invalid');
        }
        $stageId=PintogyaCrypto::assertUuid($stageId);$checkinId=PintogyaCrypto::assertUuid($checkinId);
        $projection=$this->thread->publicProjection($tid,$locale);
        $activity=$projection['activity'];
        $sources=$this->sources($actorUid,$activity,$stageId,$cursor,$locale);
        if(!isset($sources[$checkinId])) { throw new RuntimeException('checkin_interaction_unavailable'); }
        return array('activity_pk'=>(int)$projection['activityPk'],'tid'=>$tid,'stage_id'=>$stageId,
            'stage_version'=>$activity['stageVersion'],'togya_user_id'=>$sources[$checkinId],
            'checkin_id'=>$checkinId,'source_cursor'=>$cursor);
    }

    public function feed($actorUid,$tid,$stageId,$cursor,$locale,$publicOnly=false) {
        if(!is_string($cursor))throw new InvalidArgumentException('checkin_interaction_input_invalid');
        $offset=0;$sourceCursor='';$legacy=$cursor!==''&&!str_starts_with($cursor,'dx1_');
        if($legacy) { $sourceCursor=$cursor; }
        elseif($cursor!=='') {
            if(!preg_match('/^dx1_(0|[1-9][0-9]{0,3})$/D',$cursor,$m)||(int)$m[1]>1000)throw new InvalidArgumentException('checkin_interaction_input_invalid');
            $offset=(int)$m[1];
        }
        $projection=$this->thread->publicProjection($tid,$locale);$activity=$projection['activity'];
        $rows=array();$seen=array();$pending=false;$next=null;
        for($page=0;$page<($legacy?1:50);$page++) {
            if(isset($seen[$sourceCursor]))throw new RuntimeException('activity_feed_response_invalid');
            $seen[$sourceCursor]=true;
            try {
                $data=$publicOnly?$this->public->feed($activity['id'],$activity['stageVersion'],$locale,$stageId,$sourceCursor):
                    $this->thread->stageFeed($actorUid,$tid,$locale,$stageId,$sourceCursor,true);
            } catch(RuntimeException $error) {
                if(!$rows||!in_array($error->getMessage(),array('activity_display_pending','activity_display_forbidden'),true))throw $error;
                $pending=true;break;
            }
            $items=$this->decorate($actorUid,$tid,$stageId,$sourceCursor,$locale,$data['items'],$publicOnly?'id':'checkinId');
            foreach($items as $item) {
                $item['sourceCursor']=$sourceCursor;
                $rows[$item[$publicOnly?'id':'checkinId']]=$item;
            }
            $next=$data['nextCursor'];if($next===null)break;
            $sourceCursor=$next;$pending=true;
        }
        $items=array_values($rows);
        usort($items,static function($a,$b) use($publicOnly) {
            foreach(array('score','comments') as $field) {
                $order=($b['interaction'][$field]??0)<=>($a['interaction'][$field]??0);if($order)return $order;
            }
            $id=$publicOnly?'id':'checkinId';return strcmp($b[$id],$a[$id]);
        });
        $slice=array_slice($items,$offset,20);$pending=$next!==null&&$pending;
        if(!$slice&&$pending)throw new RuntimeException('activity_display_pending');
        return array('stageId'=>$stageId,'items'=>$slice,'nextCursor'=>$legacy?$next:
            (($offset+count($slice)<count($items)||$pending)?'dx1_'.($offset+count($slice)):null));
    }

    public function prepare($actorUid,$tid,$stageId,$checkinId,$cursor,$locale,$action,$now) {
        if(!is_int($actorUid)||$actorUid<1||!is_int($now)||$now<1||!in_array($action,array('comment','rate'),true)) {
            throw new InvalidArgumentException('checkin_interaction_input_invalid');
        }
        if(!$this->store->ready()) { throw new RuntimeException('checkin_interaction_schema_missing'); }
        $scope=$this->source($actorUid,$tid,$stageId,$checkinId,$cursor,$locale);
        $owner=$this->store->owner($scope,false);
        if(!$owner || !self::capabilities($actorUid,(int)$owner['uid'])[$action]) { throw new RuntimeException('checkin_interaction_unavailable'); }
        $scope['owner_uid']=(int)$owner['uid'];$scope['binding_generation']=(int)$owner['generation'];
        $row=$this->store->ensure($scope,$now);
        if(!$row || $row['state_code']!=='active' || !self::sameOwner($row,$scope) || !$this->store->postValid($row)) {
            throw new RuntimeException('checkin_interaction_unavailable');
        }
        return array('pid'=>(int)$row['pid'],'href'=>self::actionUrl($tid,(int)$row['pid'],$action),
            'postUrl'=>self::postUrl($tid,(int)$row['pid']));
    }

    public function decorate($actorUid,$tid,$stageId,$cursor,$locale,$items,$idKey='id') {
        if(!$this->store->ready() || !is_array($items) || count($items)>20) { return $items; }
        $projection=$this->thread->publicProjection($tid,$locale);$activity=$projection['activity'];
        $sources=$this->sources($actorUid,$activity,$stageId,$cursor,$locale);
        $pids=array();
        foreach($items as &$item) {
            $id=$item[$idKey]??'';if(!isset($sources[$id])) { continue; }
            $scope=array('activity_pk'=>(int)$projection['activityPk'],'tid'=>$tid,'stage_id'=>$stageId,
                'stage_version'=>$activity['stageVersion'],'togya_user_id'=>$sources[$id]);
            $owner=$this->store->owner($scope,false);if(!$owner) { continue; }
            $scope['owner_uid']=(int)$owner['uid'];$row=$this->store->byScope($scope);
            if($row && ($row['state_code']!=='active'||!self::sameOwner($row,$scope)||!$this->store->postValid($row))) { continue; }
            $caps=self::capabilities($actorUid,(int)$owner['uid']);
            $item['interaction']=array('canComment'=>$caps['comment'],'canRate'=>$caps['rate'],
                'pid'=>$row?(int)$row['pid']:null,'cursor'=>$cursor,'comments'=>0,'ratings'=>0,'score'=>0);
            if($row) { $pids[]=(int)$row['pid']; }
        }
        unset($item);$counts=$this->store->counts($pids);
        foreach($items as &$item) {
            $pid=$item['interaction']['pid']??0;
            if($pid && isset($counts[$pid])) {
                $item['interaction']['comments']=$counts[$pid]['comments'];$item['interaction']['ratings']=$counts[$pid]['ratings'];
                $item['interaction']['score']=$counts[$pid]['score']??0;
            }
        }
        unset($item);return $items;
    }

    public static function sameOwner($row,$scope) {
        foreach(array('activity_pk','tid','stage_version','owner_uid') as $key) if((int)$row[$key]!==$scope[$key])return false;
        return $row['stage_id']===$scope['stage_id'] && $row['togya_user_id']===$scope['togya_user_id'];
    }

    public static function capabilities($actorUid,$ownerUid) {
        global $_G;
        $comment=$actorUid>0 && in_array((int)($_G['group']['allowcommentpost']??0),array(2,3),true)
            && ($actorUid!==$ownerUid || !empty($_G['setting']['commentpostself'])) && !empty($_G['setting']['commentnumber']);
        $rate=$actorUid>0 && $actorUid!==$ownerUid && !empty($_G['group']['raterange']);
        return array('comment'=>$comment,'rate'=>$rate);
    }

    public static function actionUrl($tid,$pid,$action) {
        if($tid<1||$pid<1||!in_array($action,array('comment','rate'),true))throw new InvalidArgumentException('checkin_interaction_input_invalid');
        return 'forum.php?mod=misc&action='.$action.'&tid='.$tid.'&pid='.$pid.'&special=0';
    }
    public static function postUrl($tid,$pid) { return 'forum.php?mod=redirect&goto=findpost&ptid='.$tid.'&pid='.$pid; }

    public static function nativeAllowed($tid,$post) {
        try {
            if(!is_array($post)||(int)($post['first']??1)!==0) { return true; }
            $store=new PintogyaCheckinPostStore();
            if(!$store->ready()) { return !str_starts_with((string)($post['message']??''),PintogyaCheckinNativePost::PREFIX); }
            $row=$store->byPid((int)($post['pid']??0));if(!$row) { return true; }
            if(!function_exists('pintogya_services'))require_once __DIR__.'/pintogya.php';
            global $_G;
            if((int)($_G['uid']??0)<1 || (int)$row['tid']!==$tid || $row['state_code']!=='active'||!$store->postValid($row,$post))return false;
            PintogyaActivityReadAccess::assertCurrent($tid);
            $services=pintogya_display_services(pintogya_services());
            $self=new self($services['activity_thread'],$services['activity_public_read'],$store,$services['activity_read']);
            $scope=$self->source((int)$_G['uid'],$tid,$row['stage_id'],$row['checkin_id'],$row['source_cursor'],pintogya_api_locale());
            $owner=$store->owner($scope,false);$scope['owner_uid']=(int)($owner['uid']??0);
            return $owner && self::sameOwner($row,$scope);
        } catch(Throwable $error) { return false; }
    }
}

require_once __DIR__.'/checkin_native_post.php';

final class PintogyaCheckinPostStore {
    private $ready=null;
    public function ready() {
        if($this->ready===null) {
            $this->ready=strtoupper((string)DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array(DB::table('pintogya_checkin_post'))))==='INNODB';
            if($this->ready) {
                $columns=DB::fetch_all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',array(DB::table('pintogya_checkin_post')));
                $this->ready=array_column($columns,'COLUMN_NAME')===array('interaction_pk','activity_pk','tid','stage_id','stage_version','owner_uid',
                    'togya_user_id','binding_generation','checkin_id','source_cursor','pid','state_code','created_at','updated_at');
            }
        }
        return $this->ready;
    }
    public function owner($scope,$lock=false) {
        $rows=DB::fetch_all('SELECT p.uid,b.generation,m.username,m.groupid,m.adminid,m.freeze,m.groupexpiry FROM %t p'
            .' INNER JOIN '.DB::table('pintogya_binding').' b ON b.uid=p.uid AND b.togya_user_id=p.togya_user_id AND b.generation=p.binding_generation'
            .' INNER JOIN '.DB::table('common_member').' m ON m.uid=p.uid'
            ." WHERE p.activity_pk=%d AND p.togya_user_id=%s AND p.stage_version=%d AND p.state_code='active' AND b.state_code='active' LIMIT 2"
            .($lock?' FOR UPDATE':''),array('pintogya_participation',$scope['activity_pk'],$scope['togya_user_id'],$scope['stage_version']));
        if(count($rows)!==1)return null;$row=$rows[0];
        if(in_array((int)$row['groupid'],array(4,5,6,7),true)||!empty($row['freeze'])||
            (!empty($row['groupexpiry'])&&(int)$row['groupexpiry']<=time()))return null;
        return $row;
    }
    public function byScope($s) {
        return DB::fetch_first('SELECT * FROM %t WHERE activity_pk=%d AND stage_id=%s AND stage_version=%d AND (owner_uid=%d OR togya_user_id=%s) LIMIT 1',
            array('pintogya_checkin_post',$s['activity_pk'],$s['stage_id'],$s['stage_version'],$s['owner_uid'],$s['togya_user_id']));
    }
    public function byPid($pid) { return DB::fetch_first('SELECT * FROM %t WHERE pid=%d',array('pintogya_checkin_post',$pid)); }
    public function postValid($row,$post=null) {
        if($post===null)$post=table_forum_post::t()->fetch_post('tid:'.$row['tid'],(int)$row['pid']);
        return is_array($post)&&(int)$post['pid']===(int)$row['pid']&&(int)$post['tid']===(int)$row['tid']&&
            (int)$post['authorid']===(int)$row['owner_uid']&&(int)$post['first']===0&&(int)$post['invisible']===0&&
            (((int)($post['status']??0)&1)===0)&&$post['message']===PintogyaCheckinNativePost::message((int)$row['tid']);
    }
    public function ensure($scope,$now) {
        $lock='pintogya:checkin:'.substr(hash('sha256',(string)DB::result_first('SELECT DATABASE()').':'.$scope['tid']),0,24);
        if((int)DB::result_first('SELECT GET_LOCK(%s,2)',array($lock))!==1)throw new RuntimeException('checkin_interaction_busy');
        $transaction=false;
        try {
            $before=$this->owner($scope,false);
            if(!$before)throw new RuntimeException('checkin_interaction_unavailable');
            if(!$this->byScope($scope))PintogyaCheckinNativePost::authorizeOwner($scope['tid'],$before);
            DB::query('START TRANSACTION');$transaction=true;
            $owner=$this->owner($scope,true);
            if(!$owner||(int)$owner['uid']!==$scope['owner_uid']||(int)$owner['generation']!==$scope['binding_generation'])throw new RuntimeException('checkin_interaction_unavailable');
            foreach(array('groupid','freeze','groupexpiry') as $key)if((string)$owner[$key]!== (string)$before[$key])throw new RuntimeException('checkin_interaction_unavailable');
            $row=$this->byScope($scope);
            if($row) {
                if(!PintogyaCheckinInteractions::sameOwner($row,$scope))throw new RuntimeException('checkin_interaction_unavailable');
                if($row['state_code']==='active'&&!$this->postValid($row)) {
                    DB::update('pintogya_checkin_post',array('state_code'=>'retired','updated_at'=>$now),array('interaction_pk'=>$row['interaction_pk']));
                    $row['state_code']='retired';
                } elseif($row['state_code']==='active') {
                    DB::update('pintogya_checkin_post',array('checkin_id'=>$scope['checkin_id'],'source_cursor'=>$scope['source_cursor'],'updated_at'=>$now),array('interaction_pk'=>$row['interaction_pk']));
                }
            } else {
                $pid=PintogyaCheckinNativePost::create($scope['tid'],$owner,$now);
                $row=$scope+array('pid'=>$pid,'state_code'=>'active','created_at'=>$now,'updated_at'=>$now);
                DB::insert('pintogya_checkin_post',$row,false,false,true);
                if(DB::affected_rows()!==1||!$this->postValid($row))throw new RuntimeException('checkin_interaction_insert_failed');
            }
            DB::query('COMMIT');$transaction=false;
            if($row['state_code']==='active')PintogyaCheckinNativePost::invalidate((int)$row['tid'],(int)$row['pid']);
            return $row;
        } catch(Throwable $error) {
            if($transaction)DB::query('ROLLBACK');throw $error;
        } finally { DB::result_first('SELECT RELEASE_LOCK(%s)',array($lock)); }
    }
    public function counts($pids) {
        if(!$pids)return array();$pids=array_values(array_unique(array_map('intval',$pids)));$out=array();
        foreach($pids as $pid)$out[$pid]=array('comments'=>0,'ratings'=>0,'score'=>0);
        foreach(DB::fetch_all('SELECT pid,COUNT(*) AS total FROM %t WHERE pid IN (%n) AND authorid>=0 GROUP BY pid',array('forum_postcomment',$pids)) as $r)$out[(int)$r['pid']]['comments']=(int)$r['total'];
        foreach(DB::fetch_all('SELECT pid,COUNT(*) AS total,COALESCE(SUM(score),0) AS score FROM %t WHERE pid IN (%n) GROUP BY pid',array('forum_ratelog',$pids)) as $r) {
            $out[(int)$r['pid']]['ratings']=(int)$r['total'];$out[(int)$r['pid']]['score']=(int)$r['score'];
        }
        return $out;
    }
}
