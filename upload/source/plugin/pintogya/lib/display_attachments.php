<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

// Ordinary DX attachment URLs: the granted feed decides which URLs to render, not each image GET.
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
        $s=@lstat($this->root);
        if(!$s || ($s['mode']&0170000)!==0040000 || $s['uid']!==posix_geteuid() || is_link($this->root)) { return null; }
        $name=$this->name($request,$data);
        if(!$name || !$this->regular($this->root.'/'.$name) || filesize($this->root.'/'.$name)!==$data['size']) { return null; }
        return self::PREFIX.$name;
    }
    public function store($request,$data,$body,$now) {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); }
        $name=$this->name($request,$data);
        if(!$name || strlen($body)!==$data['size'] || !hash_equals($data['sha256'],hash('sha256',$body))) {
            throw new RuntimeException('display_cache_image_invalid');
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
