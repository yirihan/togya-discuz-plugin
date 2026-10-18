<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/display_public.php';

final class PintogyaMemberDisplaySession {
    private $session;
    private $cache;
    private $imageContext;
    private $feedRequest;
    private $locale='en-US';
    public function __construct($session,$cache) { $this->session=$session; $this->cache=$cache; }
    public function binding($uid) { return $this->session->binding($uid); }
    public static function identity($binding) {
        return array('uid'=>(int)$binding['uid'],'subject'=>$binding['togya_user_id'],'generation'=>(int)$binding['generation']);
    }
    private function read($uid,$kind,$activity,$locale,$version=0,$stage='',$cursor='',$media='') {
        $identity=self::identity($this->binding($uid));
        $request=PintogyaDisplayCache::request($kind,$activity,$locale,$version,$stage,$cursor,$media,$identity);
        $data=str_ends_with($kind,'_image') ? $this->cache->image($request) : $this->cache->read($request);
        if(self::identity($this->binding($uid))!==$identity) { throw new RuntimeException('binding_changed'); }
        if($kind==='member_feed') { $this->cache->offerNext($request); $this->feedRequest=$request; }
        return $data;
    }
    public function activityDetail($uid,$activity,$locale) {
        $this->locale=$locale;
        return $this->read($uid,'member_detail',$activity,$locale);
    }
    public function activityDetailReadOnly($uid,$activity,$locale) { return $this->activityDetail($uid,$activity,$locale); }
    public function stageFeed($uid,$activity,$stage,$version,$cursor='') {
        return $this->read($uid,'member_feed',$activity,$this->locale,$version,$stage,$cursor);
    }
    public function stageFeedReadOnly($uid,$activity,$stage,$version,$cursor='') { return $this->stageFeed($uid,$activity,$stage,$version,$cursor); }
    public function displayImageUrls($keys) {
        // Private pixels are returned by the existing authenticated POST image controller, never a static URL.
        return array_fill(0,count($keys),null);
    }
    public function displayImageContext($uid,$activity,$locale,$version,$stage,$cursor) {
        $this->imageContext=array($uid,$activity,$locale,$version,$stage,$cursor);
    }
    public function checkinImage($uid,$key) {
        $c=$this->imageContext;
        if(!$c || $uid!==$c[0]) { throw new RuntimeException('display_cache_image_context_missing'); }
        return $this->read($uid,'member_image',$c[1],$c[2],$c[3],$c[4],$c[5],$key);
    }
    public function checkinImageReadOnly($uid,$key) { return $this->checkinImage($uid,$key); }
}

final class PintogyaMemberDisplaySnapshot {
    private $binding;
    private $detail;
    private $feed;
    private $session;
    public function __construct($binding,$detail,$feed=null,$session=null) {
        $this->binding=$binding; $this->detail=$detail; $this->feed=$feed; $this->session=$session;
    }
    public function binding($uid) {
        if((int)$this->binding['uid']!==$uid) { throw new RuntimeException('binding_changed'); }
        return $this->binding;
    }
    public function activityDetail($uid,$activity,$locale) { return $this->detail; }
    public function activityDetailReadOnly($uid,$activity,$locale) { return $this->detail; }
    public function stageFeed($uid,$activity,$stage,$version,$cursor='') { return $this->feed; }
    public function stageFeedReadOnly($uid,$activity,$stage,$version,$cursor='') { return $this->feed; }
    public function checkinImageReadOnly($uid,$key) { return $this->session->checkinImageReadOnly($uid,$key); }
}

final class PintogyaMemberDisplayFill {
    private $cache;
    private $session;
    private $observed;
    private $deferred=0;
    public function __construct($cache,$session,$observed=null) { $this->cache=$cache; $this->session=$session; $this->observed=$observed; }
    public function membershipDeferred() { return $this->deferred; }
    public function fill($lease) {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); }
        $r=PintogyaDisplayCache::validate($lease['request']);
        if(!str_starts_with($r['kind'],'member_')) { throw new RuntimeException('display_cache_scope_invalid'); }
        $binding=$this->session->binding($r['uid']);
        $this->assertIdentity($r,$binding);
        $publicRequest=PintogyaDisplayCache::request('public_detail',$r['activityId'],$r['locale']);
        $public=$this->cache->peek($publicRequest);
        if(!$public) { $this->cache->enqueue($publicRequest,!empty($lease['demandedAt'])); throw new RuntimeException('activity_display_pending'); }
        $version=$public['data']['stageVersion']; $expiry=PintogyaDisplayCache::EXPIRES_NEVER;
        if($r['kind']!=='member_detail' && $r['version']!==$version) { throw new RuntimeException('activity_source_changed'); }
        if($r['kind']==='member_detail') {
            $detail=$this->session->activityDetailReadOnly($r['uid'],$r['activityId'],$r['locale']);
            if(($detail['stageVersion'] ?? null)!==$version) {
                $this->cache->invalidate($publicRequest);
                throw new RuntimeException('activity_source_changed');
            }
            $reader=new PintogyaActivityRead(new PintogyaMemberDisplaySnapshot($binding,$detail),null);
            $reader->viewerState($r['uid'],$r['activityId'],$r['locale'],$version,true);
            if($detail['isJoined'] && $this->observed) {
                try { call_user_func($this->observed,$r); }
                catch(Throwable $error) {
                    if(in_array($error->getCode(),array(401,403,404,410),true) ||
                        in_array($error->getMessage(),array('activity_feed_not_available','activity_not_public','activity_source_changed',
                        'binding_inactive','binding_changed','api_unauthorized'),true)) { throw $error; }
                    $this->deferred++;
                }
            }
            $data=self::detail($detail);
        } else {
            $parentRequest=PintogyaDisplayCache::request('member_detail',$r['activityId'],$r['locale'],0,'','','',PintogyaMemberDisplaySession::identity($binding));
            $parent=$this->cache->peek($parentRequest);
            if(!$parent) { $this->cache->enqueue($parentRequest,!empty($lease['demandedAt'])); throw new RuntimeException('activity_display_pending'); }
            $detail=$parent['data'];
            if(($detail['stageVersion'] ?? null)!==$version) { throw new RuntimeException('activity_source_changed'); }
            $proof=new PintogyaActivityRead(new PintogyaMemberDisplaySnapshot($binding,$detail),null);
            $viewer=$proof->viewerState($r['uid'],$r['activityId'],$r['locale'],$version,true);
            $allowed=false;
            foreach($viewer['stages'] as $stage) if($stage['id']===$r['stageId'] && $stage['feedAvailable'] && $viewer['state']==='joined') { $allowed=true; }
            if(!$allowed) { throw new RuntimeException('activity_feed_not_available'); }
            if($r['kind']==='member_feed') {
                $feed=$this->session->stageFeedReadOnly($r['uid'],$r['activityId'],$r['stageId'],$version,$r['cursor']);
                (new PintogyaActivityRead(new PintogyaMemberDisplaySnapshot($binding,$detail,$feed),null))
                    ->stageFeed($r['uid'],$r['activityId'],$r['locale'],$version,$r['stageId'],$r['cursor'],false,true);
                $data=self::feed($feed);
            } else {
                $feedRequest=PintogyaDisplayCache::request('member_feed',$r['activityId'],$r['locale'],$version,$r['stageId'],$r['cursor'],'',PintogyaMemberDisplaySession::identity($binding));
                $page=$this->cache->peek($feedRequest);
                if(!$page) { $this->cache->enqueue($feedRequest,!empty($lease['demandedAt'])); throw new RuntimeException('activity_display_pending'); }
                $target=null;$imageIndex=0;
                foreach($page['data']['items'] as $row) {
                    $index=array_search($r['media'],PintogyaActivityRead::checkinImageKeys($row),true);
                    if($index!==false) { $target=$row['id'];$imageIndex=$index;break; }
                }
                if(!$target) { throw new RuntimeException('activity_stage_image_unavailable'); }
                $reader=new PintogyaActivityRead(new PintogyaMemberDisplaySnapshot($binding,$detail,$page['data'],$this->session),null);
                $image=$reader->stageImage($r['uid'],$r['activityId'],$r['locale'],$version,$r['stageId'],$r['cursor'],$target,true,$imageIndex);
                $this->assertIdentity($r,$this->session->binding($r['uid']));
                return $this->cache->complete($lease,array(),$expiry,$image);
            }
        }
        $this->assertIdentity($r,$this->session->binding($r['uid']));
        $saved=$this->cache->complete($lease,$data,$expiry);
        if($saved) { $this->children($r,$data,$expiry,$binding,!empty($lease['demandedAt'])); }
        return $saved;
    }
    private function assertIdentity($request,$binding) {
        if(PintogyaMemberDisplaySession::identity($binding)!==array('uid'=>$request['uid'],'subject'=>$request['subject'],'generation'=>$request['generation'])) {
            throw new RuntimeException('binding_changed');
        }
    }
    private function children($r,$data,$expiry,$binding,$demand = false) {
        $identity=PintogyaMemberDisplaySession::identity($binding);
        if($r['kind']==='member_detail' && $data['isJoined']) {
            foreach($data['stageDetails'] as $stage) if($stage['canViewFeed'] && is_string($stage['id'])) {
                $this->cache->enqueue(PintogyaDisplayCache::request('member_feed',$r['activityId'],$r['locale'],$data['stageVersion'],$stage['id'],'','',$identity),$demand);
            }
        } elseif($r['kind']==='member_feed') {
            foreach($data['items'] as $row) foreach(PintogyaActivityRead::checkinImageKeys($row) as $key) {
                $image=PintogyaDisplayCache::request('member_image',$r['activityId'],$r['locale'],$r['version'],$r['stageId'],$r['cursor'],$key,$identity);
                if($r['cursor']!=='') { $this->cache->offer($image,$expiry); }
                $this->cache->enqueue($image,$demand);
            }
            $this->cache->offerNext($r);
            if(is_string($data['nextCursor'] ?? null) && $data['nextCursor']!=='' && $data['nextCursor']!==$r['cursor']) {
                $next=$r; $next['cursor']=$data['nextCursor']; $this->cache->enqueue($next,$demand);
            }
        }
    }
    private static function detail($data) {
        $copy=array_intersect_key($data,array_flip(array('id','isPublic','isJoined','status','stageVersion','totalStages','progress')));
        $copy['stageDetails']=array();
        foreach($data['stageDetails'] as $stage) {
            $row=array_intersect_key($stage,array_flip(array('id','position','title','version','stageKind','canReadInstructions','canViewFeed','isCompleted',
                'objective','completionCriteria','checkinPrompt')));
            if(!$row['canReadInstructions']) foreach(array('objective','completionCriteria','checkinPrompt') as $key) { $row[$key]=''; }
            $copy['stageDetails'][]=$row;
        }
        return $copy;
    }
    private static function feed($data) {
        $copy=array_intersect_key($data,array_flip(array('activityId','stageId','stageVersion','view','nextCursor'))); $copy['items']=array();
        foreach($data['items'] as $row) {
            if(($row['isSameActivity'] ?? null)!==true || !in_array($row['kind'] ?? null,array('progress','completion'),true)) { continue; }
            $item=array_intersect_key($row,array_flip(array('id','activityId','stageId','stageVersion','isSameActivity','kind','body','createdAt','imageKey','imageKeys')));
            $name=trim($row['author']['displayName']);
            $item['author']=array('id'=>$row['author']['id'],'displayName'=>preg_match('/^([\p{L}\p{N}])./us',$name,$m)===1?$m[1].'***':'***');
            $copy['items'][]=$item;
        }
        return $copy;
    }
}
