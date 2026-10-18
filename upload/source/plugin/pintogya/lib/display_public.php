<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/display_cache.php';
require_once __DIR__.'/refresh_budget.php';
require_once __DIR__.'/activity_read.php';
require_once __DIR__.'/activity_public_read.php';

// No transport is attached to the foreground display adapter.
final class PintogyaPublicDisplayApi {
    private $cache;
    private $feedRequest;
    public function __construct($cache) { $this->cache=$cache; }
    public function getPublicActivity($id,$locale) {
        $request=PintogyaDisplayCache::request('public_detail',$id,$locale);
        $this->locale=$locale;
        return $this->cache->read($request);
    }
    public function getPublicStageFeed($id,$stage,$version,$cursor='') {
        $this->feedRequest=PintogyaDisplayCache::request('public_feed',$id,$this->locale,$version,$stage,$cursor);
        $data=$this->cache->read($this->feedRequest); $this->cache->offerNext($this->feedRequest);
        return $data;
    }
    private $locale='en-US';
    public function getPublicActivityComments($id,$cursor='') {
        $detail=$this->getPublicActivity($id,$this->locale);
        $request=PintogyaDisplayCache::request('public_comments',$id,$this->locale,$detail['stageVersion'],'',$cursor);
        $data=$this->cache->read($request); $this->cache->offerNext($request); return $data;
    }
    public function getPublicCheckinImage($key) {
        $r=$this->feedRequest;
        if(!$r) { throw new RuntimeException('display_cache_image_context_missing'); }
        return $this->cache->image(PintogyaDisplayCache::request('public_image',$r['activityId'],$r['locale'],
            $r['version'],$r['stageId'],$r['cursor'],$key));
    }
    public function displayImageUrls($keys) {
        if(!$this->feedRequest) { return array_fill(0,count($keys),null); }
        $urls=array();
        foreach($keys as $key) {
            $r=$this->feedRequest;$r['kind']='public_image';$r['media']=$key;
            $urls[]=$this->cache->imageUrl($r);
        }
        return $urls;
    }
}

// In-memory authoritative inputs for the existing validators; images alone delegate to the live API.
final class PintogyaPublicDisplaySnapshot {
    private $detail;
    private $feed;
    private $comments;
    private $api;
    public function __construct($detail,$feed=null,$comments=null,$api=null) {
        $this->detail=$detail; $this->feed=$feed; $this->comments=$comments; $this->api=$api;
    }
    public function getPublicActivity($id,$locale) { return $this->detail; }
    public function getPublicStageFeed($id,$stage,$version,$cursor='') { return $this->feed; }
    public function getPublicActivityComments($id,$cursor='') { return $this->comments; }
    public function getPublicCheckinImage($key) {
        if(!$this->api) { throw new RuntimeException('display_cache_image_transport_missing'); }
        return $this->api->getPublicCheckinImage($key);
    }
}

final class PintogyaPublicDisplayFill {
    private $cache;
    private $api;
    private $member;
    public function __construct($cache,$api,$member=null) { $this->cache=$cache; $this->api=$api; $this->member=$member; }

    public function run($limit=5,$seconds=20) {
        if(PHP_SAPI!=='cli' || !is_int($limit) || $limit<1 || $limit>20 || !is_int($seconds) || $seconds<1 || $seconds>30) {
            throw new InvalidArgumentException('display_cache_batch_invalid');
        }
        $result=array('scanned'=>0,'cached'=>0,'held'=>0,'authDeferred'=>0,'removed'=>$this->cache->collect());
        if(method_exists($this->cache,'materializeImages')) { $result['localImages']=$this->cache->materializeImages(64); }
        $start=hrtime(true);
        while($result['scanned']<$limit && (hrtime(true)-$start)/1000000000<$seconds) {
            $lease=$this->cache->claim(); if(!$lease) { break; }
            $result['scanned']++;
            try { if($this->fill($lease)) { $result['cached']++; } else { $result['held']++; } }
            catch(Throwable $error) {
                if($error instanceof PintogyaRefreshDeferred) {
                    $retryAt=max($this->cache->now()+1,min($this->cache->now()+900,$error->retryAt));
                    $this->cache->defer($lease,$retryAt);
                    $result['held']++; $result['authDeferred']++; continue;
                }
                // Denials gate only their resource; stored bytes keep their original retention.
                if(in_array($error->getCode(),array(403,404,410),true) ||
                    in_array($error->getMessage(),array('api_forbidden','api_not_found','api_image_unavailable',
                    'api_unauthorized','binding_inactive','binding_changed','activity_feed_not_available',
                    'activity_stage_image_unavailable','activity_source_changed',
                    'activity_not_public','activity_public_feed_unavailable','official_example_untrusted'),true)) {
                    $r=$lease['request'];
                    if($r['kind']!=='public_detail' && in_array($error->getMessage(),array('activity_not_public','activity_source_changed'),true)) {
                        $this->cache->deny(PintogyaDisplayCache::request('public_detail',$r['activityId'],$r['locale']));
                    }
                    $this->cache->rejected($lease);
                } else {
                    $retry=preg_match('/^api_http_429_([0-9]{1,3})$/D',$error->getMessage(),$match)===1 ? max(1,min(900,(int)$match[1])) : null;
                    $this->cache->failed($lease,$retry);
                }
                $result['held']++;
            }
        }
        if($this->member) { $result['membershipDeferred']=$this->member->membershipDeferred(); }
        return $result;
    }

    public function fill($lease) {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); }
        $r=PintogyaDisplayCache::validate($lease['request']);
        if(!str_starts_with($r['kind'],'public_')) {
            if(!$this->member) { throw new RuntimeException('display_cache_member_worker_pending'); }
            return $this->member->fill($lease);
        }
        $expiry=PintogyaDisplayCache::EXPIRES_NEVER;
        if($r['kind']==='public_detail') {
            $data=$this->api->getPublicActivity($r['activityId'],$r['locale']);
            $source=new PintogyaPublicDisplaySnapshot($data);
            (new PintogyaActivityRead(null,$source))->publicActivity($r['activityId'],$r['locale'],false);
            $data=self::detail($data);
        } else {
            $detailRequest=PintogyaDisplayCache::request('public_detail',$r['activityId'],$r['locale']);
            $parent=$this->cache->peek($detailRequest);
            if(!$parent) { $this->cache->enqueue($detailRequest); throw new RuntimeException('activity_display_pending'); }
            $detail=$parent['data'];
            if(($detail['stageVersion'] ?? null)!==$r['version']) { throw new RuntimeException('activity_public_feed_unavailable'); }
            if($r['kind']==='public_feed') {
                $data=$this->api->getPublicStageFeed($r['activityId'],$r['stageId'],$r['version'],$r['cursor']);
                (new PintogyaActivityPublicRead(new PintogyaPublicDisplaySnapshot($detail,$data)))
                    ->feed($r['activityId'],$r['version'],$r['locale'],$r['stageId'],$r['cursor']);
                $data=self::feed($data);
            } elseif($r['kind']==='public_comments') {
                $data=$this->api->getPublicActivityComments($r['activityId'],$r['cursor']);
                (new PintogyaActivityPublicRead(new PintogyaPublicDisplaySnapshot($detail,null,$data)))
                    ->comments($r['activityId'],$r['version'],$r['locale'],$r['cursor']);
                $data=self::comments($data);
            } else {
                $feedRequest=PintogyaDisplayCache::request('public_feed',$r['activityId'],$r['locale'],$r['version'],$r['stageId'],$r['cursor']);
                $page=$this->cache->peek($feedRequest);
                if(!$page) { $this->cache->enqueue($feedRequest); throw new RuntimeException('activity_display_pending'); }
                $target=null;
                foreach($page['data']['items'] as $row) {
                    $keys=array_values(array_unique(array_filter(array_merge(array($row['imageKey'] ?? ''),$row['imageKeys'] ?? array()))));
                    $index=array_search($r['media'],$keys,true);
                    if($index!==false) { $target=array($row['id'],$index); break; }
                }
                if(!$target) { throw new RuntimeException('activity_public_feed_unavailable'); }
                $reader=new PintogyaActivityPublicRead(new PintogyaPublicDisplaySnapshot($detail,$page['data'],null,$this->api));
                $image=$reader->image($r['activityId'],$r['version'],$r['locale'],$r['stageId'],$r['cursor'],$target[0],$target[1]);
                return $this->cache->complete($lease,array(),$expiry,$image);
            }
        }
        $saved=$this->cache->complete($lease,$data,$expiry);
        if($saved) { $this->children($r,$data,$expiry,!empty($lease['demandedAt'])); }
        return $saved;
    }

    private function children($r,$data,$expiry,$demand = false) {
        if($r['kind']==='public_detail') {
            $first=$data['stageDetails'][0] ?? null;
            if(!is_array($first) || ($first['position'] ?? null)!==0 || ($first['version'] ?? null)!==$data['stageVersion'] ||
                ($first['canViewFeed'] ?? null)!==true || ($first['canReadInstructions'] ?? null)!==true) { return; }
            $this->cache->enqueue(PintogyaDisplayCache::request('public_feed',$r['activityId'],$r['locale'],$data['stageVersion'],$first['id']),$demand);
            $this->cache->enqueue(PintogyaDisplayCache::request('public_comments',$r['activityId'],$r['locale'],$data['stageVersion']),$demand);
        } elseif($r['kind']==='public_feed') {
            foreach($data['items'] as $row) {
                foreach(array_unique(array_filter(array_merge(array($row['imageKey'] ?? ''),$row['imageKeys'] ?? array()))) as $key) {
                    $imageRequest=PintogyaDisplayCache::request('public_image',$r['activityId'],$r['locale'],
                        $r['version'],$r['stageId'],$r['cursor'],$key);
                    if($r['cursor']!=='') { $this->cache->offer($imageRequest,$expiry); }
                    $this->cache->enqueue($imageRequest,$demand);
                }
            }
        }
        if(in_array($r['kind'],array('public_feed','public_comments'),true) && ($data['nextCursor'] ?? null)!==null && $data['nextCursor']!==$r['cursor']) {
            $this->cache->offerNext($r);
            $next=$r; $next['cursor']=$data['nextCursor'];
            $this->cache->enqueue($next,$demand);
        }
    }

    private static function detail($data) {
        $keep=array('id','title','summary','stageVersion','participantCount','thumbnailUrl','sourceAvailability',
            'tutorialProvider','tutorialProviderVideoId','isPublic','status','isExample','exampleSlug','imageKey','totalStages');
        $copy=array_intersect_key($data,array_flip($keep));
        $copy['creator']=array('id'=>$data['creator']['id']); $copy['stageDetails']=array();
        if(!is_array($data['stageDetails'] ?? null) || !array_is_list($data['stageDetails']) ||
            count($data['stageDetails'])<1 || count($data['stageDetails'])>100 ||
            ($data['totalStages'] ?? null)!==count($data['stageDetails'])) { throw new RuntimeException('activity_public_stages_invalid'); }
        foreach($data['stageDetails'] as $stage) {
            $row=array_intersect_key($stage,array_flip(array('id','position','version','stageKind','title',
                'objective','completionCriteria','checkinPrompt','canViewFeed','canReadInstructions')));
            if(($row['canReadInstructions'] ?? null)!==true) {
                foreach(array('objective','completionCriteria','checkinPrompt') as $field) { $row[$field]=''; }
            }
            $copy['stageDetails'][]=$row;
        }
        return $copy;
    }
    private static function feed($data) {
        $copy=array_intersect_key($data,array_flip(array('activityId','stageId','stageVersion','view','nextCursor'))); $copy['items']=array();
        foreach($data['items'] as $row) {
            $item=array_intersect_key($row,array_flip(array('id','activityId','stageId','stageVersion','isSameActivity',
                'canInteract','kind','body','createdAt','imageKey','imageKeys')));
            $item['author']=array('id'=>$row['author']['id'],'displayName'=>self::masked($row['author']['displayName']));
            $copy['items'][]=$item;
        }
        return $copy;
    }
    private static function comments($data) {
        $copy=array('comments'=>array(),'nextCursor'=>$data['nextCursor'] ?? null);
        foreach($data['comments'] as $row) {
            $item=array_intersect_key($row,array_flip(array('id','activityId','parentId','kind','visibility','revision','body','createdAt')));
            $item['author']=array('displayName'=>self::masked($row['author']['displayName'])); $copy['comments'][]=$item;
        }
        return $copy;
    }
    private static function masked($name) { return preg_match('/^([\p{L}\p{N}])./us',trim($name),$match)===1 ? $match[1].'***' : '***'; }
}
