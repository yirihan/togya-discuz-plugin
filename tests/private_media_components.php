<?php
define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE');if(!$source||!is_file($source.'/lib/display_member.php'))exit(2);
require $source.'/lib/display_member.php';require $source.'/lib/display_attachments.php';
$base=realpath(sys_get_temp_dir()).'/togya-private-media-'.bin2hex(random_bytes(6));mkdir($base,0700);mkdir($base.'/forum',0700);define('DISCUZ_ROOT',$base.'/forum/');
$state=(object)array('now'=>1700000000,'native'=>array());$cases=array();$assertions=0;
$check=static function($ok,$symbol)use(&$assertions){$assertions++;if(!$ok)throw new RuntimeException($symbol);};
$reject=static function($call,$symbol)use($check){$caught=false;try{$call();}catch(RuntimeException $error){if($error->getMessage()!==$symbol)throw $error;$caught=true;}$check($caught,'expected_refusal');};
$record=static function($name)use(&$cases){$cases[]=array('case'=>$name,'passed'=>true);};
try {
    $cache=new PintogyaDisplayCache(array('root'=>$base.'/cache','clock'=>static function()use($state){return $state->now;},
        'load'=>static function($key)use($state){return $state->native[$key]??null;},'save'=>static function($key,$data)use($state){$state->native[$key]=$data;},
        'remove'=>static function($key)use($state){unset($state->native[$key]);}));
    $owner='30000000-0000-4000-8000-000000000001';$activity='10000000-0000-4000-8000-000000000001';$stage='20000000-0000-4000-8000-000000000001';
    $media='checkins/'.$owner.'/'.str_repeat('a',32).'.png';$identity=array('uid'=>2,'subject'=>$owner,'generation'=>1);
    $private=PintogyaDisplayCache::request('member_image',$activity,'en-US',1,$stage,'',$media,$identity);
    $public=PintogyaDisplayCache::request('public_image',$activity,'en-US',1,$stage,'',$media);
    $body=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB7kAAAAASUVORK5CYII=');
    $cache->enqueue($private);$check($cache->complete($cache->claim(),array(),0,array('contentType'=>'image/png','body'=>$body)),'private_fill_failed');
    $meta=$cache->peek($private)['data'];$storage=new PintogyaDisplayAttachments($base.'/cache/attachments');
    $check($cache->imageUrl($private)===null&&$storage->url($private,$meta)===null&&!is_dir($base.'/cache/attachments'),'private_url_or_file_emitted');
    $reject(static function()use($storage,$private,$meta,$body,$state){$storage->store($private,$meta,$body,$state->now);},'private_media_public_store_forbidden');
    $check($cache->image($private)['body']===$body&&(new PintogyaMemberDisplaySession(null,$cache))->displayImageUrls(array($media))===array(null),'private_blob_missing_or_static_url_returned');$record('private_bytes_stay_outside_public_storage');
    $cache->enqueue($public);$check($cache->complete($cache->claim(),array(),0,array('contentType'=>'image/png','body'=>$body)),'public_fill_failed');
    $publicUrl=$cache->imageUrl($public);$publicPath=$base.'/cache/attachments/'.basename($publicUrl);
    $check(is_string($publicUrl)&&hash_file('sha256',$publicPath)===hash('sha256',$body),'public_media_regressed');$record('public_media_stays_materialized');
    $legacyName=hash('sha256',PintogyaDisplayCache::id($private)."\0".$meta['sha256']).'.png';$legacy=$base.'/cache/attachments/'.$legacyName;
    file_put_contents($legacy,$body);
    $result=$cache->materializeImages();$audit=$base.'/cache/retired-member-images/'.PintogyaDisplayCache::id($private).'.json';
    $check($result['retiredPrivate']===1&&!file_exists($legacy)&&is_file($audit),'legacy_mirror_not_retired');
    $evidence=json_decode(file_get_contents($audit),true);$check($evidence['sha256']===$meta['sha256']&&$cache->image($private)['body']===$body&&is_file($publicPath),'retirement_lost_private_or_public_bytes');
    $check($cache->materializeImages()['retiredPrivate']===0,'retirement_not_idempotent');$record('legacy_retirement_records_evidence_and_preserves_private_copy');
    file_put_contents($legacy,$body);
    $reject(static function()use($storage,$private,$meta,$body){$storage->retireMemberImage($private,$meta,$body,static function(){throw new RuntimeException('synthetic_audit_failure');});},'synthetic_audit_failure');
    $check(is_file($legacy),'retired_before_evidence');$cache->materializeImages();$record('audit_failure_prevents_removal');
    file_put_contents($legacy,'synthetic changed file');
    $reject(static function()use($cache){$cache->materializeImages();},'private_media_retirement_unverified');
    $check(file_get_contents($legacy)==='synthetic changed file','unverified_mirror_removed');unlink($legacy);$record('changed_public_file_is_not_deleted');
    $second=$private;$second['media']='checkins/'.$owner.'/'.str_repeat('b',32).'.png';
    $cache->enqueue($second);$check($cache->complete($cache->claim(),array(),0,array('contentType'=>'image/png','body'=>$body)),'second_private_fill_failed');
    $secondId=PintogyaDisplayCache::id($second);$secondName=hash('sha256',$secondId."\0".$meta['sha256']).'.png';$secondPath=$base.'/cache/attachments/'.$secondName;file_put_contents($secondPath,$body);
    unlink($base.'/cache/snapshots/'.$secondId.'.json');$jobPath=$base.'/cache/queue/'.$secondId.'.json';$job=json_decode(file_get_contents($jobPath),true);
    $job['expiresAt']=$state->now-1;$job['state']='held';$job['leaseUntil']=0;file_put_contents($jobPath,json_encode($job));
    $cache->collect();$check(is_file($jobPath),'orphan_identity_removed_before_retirement');
    $check($cache->materializeImages()['retiredPrivate']===1&&!file_exists($secondPath)&&hash_file('sha256',$base.'/cache/images/'.$secondId.'.bin')===$meta['sha256'],'missing_snapshot_retirement_failed');
    $record('expired_queue_with_retained_image_remains_recoverable');
    echo json_encode(array('passed'=>true,'cases'=>$cases,'assertions'=>$assertions,'scope'=>'Actual private/public disk-cache boundary and verified legacy retirement with synthetic data; not native HTTP authorization or browser proof')).PHP_EOL;
}finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($base);
}
