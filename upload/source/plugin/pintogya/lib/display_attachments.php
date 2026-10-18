<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

// Only anonymous public media may be materialized as ordinary DX attachments.
final class PintogyaDisplayAttachments {
    const PREFIX = '/data/attachment/forum/pintogya/';
    private $root;
    public function __construct($root) { $this->root=$root; }
    private function name($request,$data) {
        $extensions=array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
        if(!isset($extensions[$data['contentType']??'']) || !is_string($data['sha256']??null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$data['sha256']) || !is_int($data['size']??null) ||
            $data['size']<1 || $data['size']>PintogyaDisplayCache::MAX_IMAGE_BYTES) { return null; }
        return hash('sha256',PintogyaDisplayCache::id($request)."\0".$data['sha256']).'.'.$extensions[$data['contentType']];
    }
    private function regular($path) {
        clearstatcache(true,$path);$s=@lstat($path);
        return $s && ($s['mode']&0170000)===0100000 && $s['nlink']===1 && $s['uid']===posix_geteuid();
    }
    public function url($request,$data) {
        if(($request['kind']??null)!=='public_image') { return null; }
        $s=@lstat($this->root);
        if(!$s || ($s['mode']&0170000)!==0040000 || $s['uid']!==posix_geteuid() || is_link($this->root)) { return null; }
        $name=$this->name($request,$data);
        if(!$name || !$this->regular($this->root.'/'.$name) || filesize($this->root.'/'.$name)!==$data['size']) { return null; }
        return self::PREFIX.$name;
    }
    public function store($request,$data,$body,$now) {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); }
        if(($request['kind']??null)!=='public_image') { throw new RuntimeException('private_media_public_store_forbidden'); }
        $name=$this->name($request,$data);
        if(!$name || strlen($body)!==$data['size'] || !hash_equals($data['sha256'],hash('sha256',$body))) {
            throw new RuntimeException('display_cache_image_invalid');
        }
        // A fresh DX installation may not have created its forum attachment directory yet.
        $parent=dirname($this->root);
        if(!is_dir($parent) && !is_link($parent) && !@mkdir($parent,0755)) {
            throw new RuntimeException('display_attachment_directory_unavailable');
        }
        if(!file_exists($this->root) && !is_link($this->root) && !@mkdir($this->root,0755)) {
            throw new RuntimeException('display_attachment_directory_unavailable');
        }
        $s=@lstat($this->root);
        if(!$s || ($s['mode']&0170000)!==0040000 || $s['uid']!==posix_geteuid() || is_link($this->root)) {
            throw new RuntimeException('display_attachment_directory_invalid');
        }
        $path=$this->root.'/'.$name;
        if($this->url($request,$data)!==null) { return false; }
        if(is_link($path) || (file_exists($path)&&!$this->regular($path))) { throw new RuntimeException('display_attachment_file_invalid'); }
        $files=glob($this->root.'/*')?:array();$bytes=0;
        if(count($files)>PintogyaDisplayCache::MAX_JOBS) { throw new RuntimeException('display_cache_disk_full'); }
        foreach($files as $file) if($this->regular($file)) { $bytes+=filesize($file); }
        if($bytes+$data['size']>PintogyaDisplayCache::MAX_DISK_BYTES) { throw new RuntimeException('display_cache_disk_full'); }
        $temp=$this->root.'/.write-'.bin2hex(random_bytes(12));$old=umask(0022);
        try { $f=fopen($temp,'xb'); } finally { umask($old); }
        if(!$f) { throw new RuntimeException('display_attachment_write_failed'); }
        try {
            if(fwrite($f,$body)!==strlen($body) || !fflush($f) || !chmod($temp,0644)) { throw new RuntimeException('display_attachment_write_failed'); }
            fclose($f);$f=null;
            if(!rename($temp,$path) || !touch($path,$now)) { throw new RuntimeException('display_attachment_write_failed'); }
        } finally { if(is_resource($f))fclose($f);if(is_file($temp))unlink($temp); }
        return true;
    }
    public function retireMemberImage($request,$data,$body,$audit) {
        if(PHP_SAPI!=='cli'||!is_callable($audit)||($request['kind']??null)!=='member_image') {
            throw new RuntimeException('private_media_retirement_invalid');
        }
        PintogyaDisplayCache::validate($request);
        $name=$this->name($request,$data);if(!$name)throw new RuntimeException('display_cache_image_invalid');
        $path=$this->root.'/'.$name;
        if(!file_exists($path)&&!is_link($path))return false;
        $directory=@lstat($this->root);
        if(!$directory||is_link($this->root)||($directory['mode']&0170000)!==0040000||$directory['uid']!==posix_geteuid()||
            !$this->regular($path)||!is_string($body)||strlen($body)!==$data['size']||
            !hash_equals($data['sha256'],hash('sha256',$body))||!hash_equals($data['sha256'],hash_file('sha256',$path))) {
            throw new RuntimeException('private_media_retirement_unverified');
        }
        call_user_func($audit,array('filename'=>$name,'size'=>$data['size'],'sha256'=>$data['sha256'],'mtime'=>filemtime($path)));
        if(!unlink($path))throw new RuntimeException('private_media_retirement_failed');
        return true;
    }
    public function hasMemberImage($request,$data) {
        if(($request['kind']??null)!=='member_image')return false;
        $name=$this->name($request,$data);return $name!==null&&(file_exists($this->root.'/'.$name)||is_link($this->root.'/'.$name));
    }
    public function collect($now,$limit=64) {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); }
        $s=@lstat($this->root);
        if(!$s || ($s['mode']&0170000)!==0040000 || $s['uid']!==posix_geteuid() || is_link($this->root)) { return 0; }
        $removed=0;
        // Completed DX image files have no age-based expiry. Only abandoned atomic-write temps are collected.
        foreach(glob($this->root.'/.write-*')?:array() as $path) {
            if($removed>=$limit)break;
            if($this->regular($path) && filemtime($path)<=$now-120) { unlink($path);$removed++; }
        }
        return $removed;
    }
}
