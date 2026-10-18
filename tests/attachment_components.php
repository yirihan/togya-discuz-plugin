<?php
define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE');if(!$source||!is_file($source.'/lib/display_cache.php'))exit(2);
require $source.'/lib/display_cache.php';require $source.'/lib/display_attachments.php';
$base=sys_get_temp_dir().'/togya-attachments-'.bin2hex(random_bytes(8));mkdir($base,0700);$checks=0;
$check=static function($value,$symbol)use(&$checks){$checks++;if(!$value)throw new RuntimeException($symbol);};
try {
    $r=PintogyaDisplayCache::request('public_image','10000000-0000-4000-8000-000000000001','en-US',1,
        '20000000-0000-4000-8000-000000000001','','checkins/30000000-0000-4000-8000-000000000001/'.str_repeat('a',32).'.png');
    $body='synthetic storage bytes';$data=array('contentType'=>'image/png','size'=>strlen($body),'sha256'=>hash('sha256',$body));
    $storage=new PintogyaDisplayAttachments($base.'/forum/pintogya');
    $check($storage->store($r,$data,$body,time()),'fresh_forum_storage_failed');
    $url=$storage->url($r,$data);$check(is_string($url)&&str_starts_with($url,PintogyaDisplayAttachments::PREFIX),'public_url_missing');
    $mode=fileperms($base.'/forum')&0777;$stat=stat($base.'/forum/pintogya/'.basename($url));
    $check($storage->store($r,$data,$body,time()+10)===false,'duplicate_storage_rewrote_file');
    $check(stat($base.'/forum/pintogya/'.basename($url))===$stat,'duplicate_changed_file_metadata');
    $check((fileperms($base.'/forum')&0777)===$mode,'existing_parent_mode_changed');
    mkdir($base.'/existing',0750);$existingMode=fileperms($base.'/existing')&0777;
    $check((new PintogyaDisplayAttachments($base.'/existing/pintogya'))->store($r,$data,$body,time())&&
        (fileperms($base.'/existing')&0777)===$existingMode,'restricted_existing_parent_changed');
    $bad=new PintogyaDisplayAttachments($base.'/not-created/pintogya');$caught=false;
    try{$bad->store($r,$data,$body.'bad',time());}catch(RuntimeException $error){$caught=$error->getMessage()==='display_cache_image_invalid';}
    $check($caught&&!file_exists($base.'/not-created'),'invalid_bytes_created_directory');
    symlink($base.'/existing',$base.'/linked');$caught=false;
    try{(new PintogyaDisplayAttachments($base.'/linked'))->store($r,$data,$body,time());}catch(RuntimeException $error){$caught=$error->getMessage()==='display_attachment_directory_invalid';}
    $check($caught,'symlink_storage_root_accepted');
    echo json_encode(array('passed'=>true,'checks'=>$checks,'scope'=>'Synthetic file-storage checks; not image decoding, HTTP or installation proof')).PHP_EOL;
}finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($base);
}
