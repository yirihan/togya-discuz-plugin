<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityCover {
    const MAX_BYTES = 2097152;

    public static function cacheName($url) {
        if(!is_string($url) || strlen($url) > 500) { return ''; }
        if(!preg_match('~^https://(?:i\.ytimg\.com/vi(?:_webp)?/[A-Za-z0-9_-]{11}/[A-Za-z0-9_-]+\.(?:jpg|jpeg|webp)|i\.vimeocdn\.com/video/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp))$~D', $url)) {
            return '';
        }
        return 'pintogya_cover/'.hash('sha256', $url).'.webp';
    }

    public static function displayUrl($url, $attachDir = null, $attachUrl = null) {
        global $_G;
        if(in_array($url, array(
            '/source/plugin/pintogya/static/examples/example-craft.webp',
            '/source/plugin/pintogya/static/examples/example-cooking.webp',
            '/source/plugin/pintogya/static/examples/example-fitness.webp',
        ), true)) {
            $path = dirname(__DIR__).'/static/examples/'.basename($url);
            $info = !is_link($path) && is_file($path) ? @getimagesize($path) : false;
            return $info && ($info[2] ?? 0) === IMAGETYPE_WEBP ? $url : '';
        }
        $name = self::cacheName($url);
        if($name === '') { return ''; }
        $attachDir = $attachDir ?? ($_G['setting']['attachdir'] ?? 'data/attachment');
        $attachUrl = $attachUrl ?? ($_G['setting']['attachurl'] ?? 'data/attachment/');
        $path = rtrim($attachDir, '/').'/forum/'.$name;
        // TOGYA_PLUGIN pintogya_local_video_cover: never load provider images in the browser.
        if(is_link($path) || !is_file($path) || filesize($path) < 1) { return ''; }
        $info = @getimagesize($path);
        if(!$info || ($info[2] ?? 0) !== IMAGETYPE_WEBP) { return ''; }
        return rtrim($attachUrl, '/').'/forum/'.$name;
    }

    // Maintenance-only: callers must first validate the current public activity projection.
    public static function populate($url, $attachDir) {
        if(PHP_SAPI !== 'cli') { throw new RuntimeException('activity_cover_cli_only'); }
        $name = self::cacheName($url);
        if($name === '') { throw new InvalidArgumentException('activity_cover_source_invalid'); }
        $directory = rtrim($attachDir, '/').'/forum/pintogya_cover';
        if(is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755, true))) {
            throw new RuntimeException('activity_cover_directory_invalid');
        }
        $target = rtrim($attachDir, '/').'/forum/'.$name;
        if(self::displayUrl($url, $attachDir, 'data/attachment/') !== '') { return false; }
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host);
        if(!$addresses) { throw new RuntimeException('activity_cover_dns_failed'); }
        foreach($addresses as $address) {
            if(!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('activity_cover_dns_invalid');
            }
        }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RESOLVE => array($host.':443:'.$addresses[0]),
            CURLOPT_WRITEFUNCTION => static function($handle, $chunk) use (&$body) {
                if(strlen($body) + strlen($chunk) > self::MAX_BYTES) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }));
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally { unset($curl); }
        $info = @getimagesizefromstring($body);
        if(!$ok || $status !== 200 || !$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP), true) ||
            $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 8000000) {
            throw new RuntimeException('activity_cover_image_invalid');
        }
        $temporary = tempnam($directory, '.cover-');
        if($temporary === false) { throw new RuntimeException('activity_cover_temp_failed'); }
        try {
            if(file_put_contents($temporary, $body) !== strlen($body)) { throw new RuntimeException('activity_cover_write_failed'); }
            require_once dirname(__DIR__, 2).'/pinmodassist/lib/image.php';
            if(!pinmodassist_threadcover_file_to_webp($temporary, $target)) {
                throw new RuntimeException('activity_cover_conversion_failed');
            }
            return true;
        } finally { @unlink($temporary); }
    }
}
