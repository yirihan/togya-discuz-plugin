<?php
define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE');if(!$source||!is_file($source.'/lib/display_member.php'))exit(2);
require $source.'/lib/api_client.php';require $source.'/lib/display_member.php';
$base=realpath(sys_get_temp_dir()).'/togya-cache-'.bin2hex(random_bytes(8));
mkdir($base,0700);mkdir($base.'/forum',0700);define('DISCUZ_ROOT',$base.'/forum/');
$make=static function($name)use($base) {
    $state=(object)array('now'=>1700000000,'native'=>array());
    $cache=new PintogyaDisplayCache(array('root'=>$base.'/'.$name,'clock'=>static function()use($state){return $state->now;},
        'load'=>static function($key)use($state){return $state->native[$key]??null;},
        'save'=>static function($key,$value)use($state){$state->native[$key]=$value;},
        'remove'=>static function($key)use($state){unset($state->native[$key]);}));
    return array($cache,$state);
};
$cases=array();$assertions=0;
$check=static function($condition,$label)use(&$assertions){$assertions++;if(!$condition)throw new RuntimeException($label);};
$rejects=static function($call,$symbol)use($check) {
    $caught=false;try{$call();}catch(RuntimeException $error){if($error->getMessage()!==$symbol)throw $error;$caught=true;}
    $check($caught,'expected_closed_error');
};
$record=static function($name)use(&$cases){$cases[]=array('case'=>$name,'passed'=>true);};
$activity='10000000-0000-4000-8000-000000000001';
$subject='20000000-0000-4000-8000-000000000001';
$result=array();
try {
    list($cache,$state)=$make('public');$api=new PintogyaPublicDisplayApi($cache);
    $request=PintogyaDisplayCache::request('public_detail',$activity);
    $rejects(static function()use($api,$activity){$api->getPublicActivity($activity,'en-US');},'activity_display_pending');
    $check(count(glob($base.'/public/queue/*.json'))===1,'cold_miss_did_not_queue_once');$record('cold_miss_only_queues');
    $lease=$cache->claim();$check(is_array($lease)&&$cache->claim()===null,'lease_not_exclusive');
    $forged=$lease;$forged['lease']=str_repeat('0',32);
    $check($cache->complete($forged,array('title'=>'forged'))===false,'forged_lease_accepted');$record('exclusive_lease_and_forgery');
    $detail=array('id'=>$activity,'title'=>'Synthetic cached activity','summary'=>'Synthetic summary',
        'creator'=>array('id'=>$subject),'stageVersion'=>1,'participantCount'=>2,'isPublic'=>true,'status'=>'active');
    $check($cache->complete($lease,$detail)===true,'fill_failed');
    $reader=new PintogyaActivityRead(null,$api,true);
    $check($reader->publicActivity($activity,'en-US')['title']===$detail['title'],'local_projection_failed');$record('validated_public_cache_hit');
    $state->native=array();$check($api->getPublicActivity($activity,'en-US')===$detail,'disk_not_authoritative');$record('native_cache_clear_keeps_disk_snapshot');
    $state->now+=2678401;$check($cache->read($request)===$detail,'retained_snapshot_expired');$record('content_retained_beyond_legacy_window');
    $cache->deny($request);
    $rejects(static function()use($api,$activity){$api->getPublicActivity($activity,'en-US');},'activity_display_forbidden');
    $check(count(glob($base.'/public/snapshots/*.json'))===1,'denial_erased_retained_evidence');$record('denial_blocks_retained_bytes');

    list($memberCache,$memberState)=$make('member');
    $session=new class($subject) {
        public $generation=1;public $calls=0;public $flip=false;public $unexpectedCalls=0;private $subject;
        public function __construct($subject){$this->subject=$subject;}
        public function binding($uid){$this->calls++;return array('uid'=>$uid,'togya_user_id'=>$this->subject,
            'generation'=>$this->generation+($this->flip&&$this->calls>1?1:0));}
        public function __call($name,$arguments){$this->unexpectedCalls++;throw new RuntimeException('unexpected_upstream_call');}
    };
    $identity=array('uid'=>2,'subject'=>$subject,'generation'=>1);
    $memberRequest=PintogyaDisplayCache::request('member_detail',$activity,'en-US',0,'','','',$identity);
    $memberCache->enqueue($memberRequest);$memberLease=$memberCache->claim();
    $personal=array('id'=>$activity,'note'=>'Synthetic member-only content');
    $check($memberCache->complete($memberLease,$personal),'member_fill_failed');
    $member=new PintogyaMemberDisplaySession($session,$memberCache);
    $check($member->activityDetail(2,$activity,'en-US')===$personal,'member_local_read_failed');$record('member_hit_without_upstream');
    $rejects(static function()use($member,$activity){$member->activityDetail(3,$activity,'en-US');},'activity_display_pending');$record('different_uid_cannot_read_cached_member_data');
    $session->generation=2;
    $rejects(static function()use($member,$activity){$member->activityDetail(2,$activity,'en-US');},'activity_display_pending');$record('new_generation_cannot_read_old_snapshot');
    $session->generation=1;$session->calls=0;$session->flip=true;
    $rejects(static function()use($member,$activity){$member->activityDetail(2,$activity,'en-US');},'binding_changed');$record('mid_read_identity_change_rejected');

    list($retryCache,$retryState)=$make('retry');$retryCache->enqueue($request);$retryLease=$retryCache->claim();
    $check($retryCache->failed($retryLease,120),'failure_not_recorded');
    $jobPath=$base.'/retry/queue/'.PintogyaDisplayCache::id($request).'.json';$before=json_decode(file_get_contents($jobPath),true);
    $rejects(static function()use($retryCache,$request){$retryCache->read($request);},'activity_display_pending');
    $after=json_decode(file_get_contents($jobPath),true);
    $check($before['attempts']===$after['attempts']&&$before['notBefore']===$after['notBefore']&&$retryCache->claim()===null,'visit_reset_backoff');$record('visits_preserve_backoff');
    $retryState->now+=121;$newLease=$retryCache->claim();$check(is_array($newLease),'retry_not_released');
    $retryState->now+=61;$check($retryCache->complete($newLease,$detail)===false,'expired_lease_accepted');$record('expired_lease_cannot_publish');
    $check($session->unexpectedCalls===0&&array_sum(PintogyaApiClient::sourceReads())===0,'unexpected_network_read');
    $result=array('scope'=>'Actual disk-cache/display adapters with synthetic clock, native-cache callbacks and binding provider; no HTTP, DB, renderer, live provider or process-concurrency proof',
        'cases'=>$cases,'assertions'=>$assertions,'upstream_calls'=>0,'passed'=>true);
}catch(Throwable $error) {
    $result=array('scope'=>'Disk-cache component checks','passed'=>false,'completed_cases'=>$cases,'assertions'=>$assertions,
        'class'=>get_class($error),'symbol'=>preg_match('/\A[a-z_]+\z/D',$error->getMessage())?$error->getMessage():null,'line'=>$error->getLine());
}finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file) {
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }rmdir($base);
}
echo json_encode($result).PHP_EOL;exit($result['passed']?0:1);
