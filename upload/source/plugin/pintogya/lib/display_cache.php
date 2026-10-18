<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/crypto.php';

// Display leases are not membership or reward evidence. Producers run only in the maintenance CLI.
final class PintogyaDisplayCache {
    const EXPIRES_NEVER = 0; // Content retention only; permissions, auth and worker leases stay independent.
    const LEGACY_TTL = 2678400;
    const REFRESH_INTERVAL = 900;
    const RETRY_HOLD = 900;
    const DEMAND_WINDOW = 3600;
    const MAX_JOBS = 8192;
    const MAX_IMAGE_BYTES = 12582912;
    const MAX_SNAPSHOT_BYTES = 2097152;
    const MAX_DISK_BYTES = 2147483648;
    private $root;
    private $clock;
    private $load;
    private $save;
    private $remove;
    private $attachmentRoot;

    public function __construct($test = null) {
        if($test !== null && !defined('PINTOGYA_STATIC_TEST')) { throw new RuntimeException('display_cache_injection_forbidden'); }
        $this->clock = $test['clock'] ?? static function() { return time(); };
        $this->load = $test['load'] ?? static function($key) {
            global $_G;
            // Reuse DX's request/Redis cache. Long-running CLI producers re-read the local backend.
            loadcache($key, PHP_SAPI === 'cli');
            return $_G['cache'][$key] ?? null;
        };
        $this->save = $test['save'] ?? static function($key, $data) {
            global $_G;
            savecache($key, $data); $_G['cache'][$key] = $data;
        };
        $this->remove = $test['remove'] ?? static function($key) {
            global $_G;
            table_common_syscache::t()->delete($key); unset($_G['cache'][$key]);
        };
        $site = realpath(defined('DISCUZ_ROOT') ? DISCUZ_ROOT : dirname(__DIR__, 4));
        if(!is_string($site) || $site === '') { throw new RuntimeException('display_cache_site_invalid'); }
        // Provision once as the FPM/maintenance owner. This is persistent and outside every webroot.
        $this->root = $test['root'] ?? '/var/cache/pintogya-display-'.substr(hash('sha256', $site), 0, 24);
        $this->attachmentRoot = $test !== null ? $this->root.'/attachments' : $site.'/data/attachment/forum/pintogya';
        $parent = realpath(dirname($this->root));
        if(!$parent || $parent !== dirname($this->root) || str_starts_with($this->root.'/', $site.'/')) {
            throw new RuntimeException('display_cache_root_invalid');
        }
        $this->directory($this->root);
        $this->directory($this->root.'/queue');
        $this->directory($this->root.'/images');
        $this->directory($this->root.'/snapshots');
    }

    public static function request($kind, $activityId, $locale = 'en-US', $version = 0, $stageId = '', $cursor = '', $media = '', $identity = null) {
        if(!in_array($kind, array('public_detail','public_feed','public_comments','public_image',
            'member_detail','member_feed','member_image'), true) ||
            !in_array($locale, array('en-US','zh-Hant','es'), true) || !is_int($version) || $version < 0 ||
            !is_string($cursor) || ($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor)) ||
            !is_string($media)) { throw new InvalidArgumentException('display_cache_request_invalid'); }
        $activityId = PintogyaCrypto::assertUuid($activityId);
        $detail = str_ends_with($kind, '_detail');
        $comments = $kind === 'public_comments';
        if($detail ? ($version !== 0 || $stageId !== '' || $cursor !== '' || $media !== '') : $version < 1) {
            throw new InvalidArgumentException('display_cache_request_invalid');
        }
        if(!$detail && !$comments) { $stageId = PintogyaCrypto::assertUuid($stageId); }
        if($comments && ($stageId !== '' || $media !== '')) { throw new InvalidArgumentException('display_cache_request_invalid'); }
        if(str_ends_with($kind, '_image')) {
            if(!preg_match('~^checkins/[0-9a-f-]{36}/[0-9a-f]{32}\.(?:jpg|png|webp)$~D', $media)) {
                throw new InvalidArgumentException('display_cache_media_invalid');
            }
            PintogyaCrypto::assertUuid(substr($media, 9, 36));
        } elseif($media !== '') { throw new InvalidArgumentException('display_cache_media_invalid'); }
        $uid = 0; $subject = ''; $generation = 0;
        if(str_starts_with($kind, 'member_')) {
            if(!is_array($identity) || !is_int($identity['uid'] ?? null) || $identity['uid'] < 1 || $identity['uid'] > 16777215 ||
                !is_int($identity['generation'] ?? null) || $identity['generation'] < 1) {
                throw new InvalidArgumentException('display_cache_identity_invalid');
            }
            $uid = $identity['uid']; $generation = $identity['generation'];
            $subject = PintogyaCrypto::assertUuid($identity['subject'] ?? null);
        } elseif($identity !== null) { throw new InvalidArgumentException('display_cache_identity_invalid'); }
        return array('kind'=>$kind,'activityId'=>$activityId,'locale'=>$locale,'version'=>$version,
            'stageId'=>$stageId,'cursor'=>$cursor,'media'=>$media,'uid'=>$uid,'subject'=>$subject,'generation'=>$generation);
    }

    public static function validate($request) {
        if(!is_array($request) || array_keys($request) !== array('kind','activityId','locale','version','stageId','cursor','media','uid','subject','generation')) {
            throw new InvalidArgumentException('display_cache_request_invalid');
        }
        $identity = str_starts_with((string)$request['kind'], 'member_') ?
            array('uid'=>$request['uid'],'subject'=>$request['subject'],'generation'=>$request['generation']) : null;
        $canonical = self::request($request['kind'],$request['activityId'],$request['locale'],$request['version'],
            $request['stageId'],$request['cursor'],$request['media'],$identity);
        if($canonical !== $request) { throw new InvalidArgumentException('display_cache_request_invalid'); }
        return $request;
    }

    public static function id($request) { return hash('sha256', json_encode(self::validate($request), JSON_THROW_ON_ERROR)); }
    // Fits even the native 32-byte cname schema. The full digest is also checked inside the envelope.
    public function nativeKey($request) { return $this->keyForId(self::id($request)); }
    private function keyForId($id) { return 'ptgd_'.substr(hash('sha256',$this->root."\0".$id),0,27); }
    public function now() { return (int)call_user_func($this->clock); }

    public function peek($request) {
        if($this->denied($request)) { return null; }
        return $this->retained($request);
    }

    // Retention and permission are independent; producers alone may inspect denied bytes.
    private function retained($request) {
        $id = self::id($request);
        // Native update-cache may remove common_syscache/Redis. The private disk snapshot is authoritative.
        $raw=$this->readFile($this->root.'/snapshots/'.$id.'.json',self::MAX_SNAPSHOT_BYTES+1024);
        if($raw===null) { return null; }
        try { $entry=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { return null; }
        $now = $this->now();
        if(!is_array($entry) || array_keys($entry) !== array('schema','id','issuedAt','expiresAt','digest','data') ||
            !in_array($entry['schema'],array(1,2),true) || $entry['id'] !== $id || !is_int($entry['issuedAt']) || !is_int($entry['expiresAt']) ||
            $entry['issuedAt'] < 1 || $entry['issuedAt'] > $now ||
            ($entry['schema']===2 ? $entry['expiresAt']!==self::EXPIRES_NEVER :
                ($entry['expiresAt']<=$entry['issuedAt'] || $entry['expiresAt']>$entry['issuedAt']+self::LEGACY_TTL)) ||
            !is_array($entry['data']) || !is_string($entry['digest'])) { return null; }
        try { $json = json_encode($entry['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { return null; }
        if(strlen($json) > self::MAX_SNAPSHOT_BYTES || !hash_equals(hash('sha256', $json), $entry['digest'])) { return null; }
        // Adopt intact historical mirrors without rewriting their source timestamp or clearing denial markers.
        $entry['expiresAt']=self::EXPIRES_NEVER;
        return $entry;
    }

    public function read($request) {
        if($this->denied($request)) { throw new RuntimeException('activity_display_forbidden'); }
        $entry = $this->peek($request);
        if($entry !== null) { return $entry['data']; }
        $this->enqueue($request,true);
        throw new RuntimeException('activity_display_pending');
    }

    public function image($request) {
        $data = $this->read($request);
        if(array_keys($data) !== array('contentType','size','sha256') ||
            !in_array($data['contentType'], array('image/jpeg','image/png','image/webp'), true) ||
            !is_int($data['size']) || $data['size'] < 1 || $data['size'] > self::MAX_IMAGE_BYTES ||
            !is_string($data['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) {
            throw new RuntimeException('display_cache_image_invalid');
        }
        $body = $this->readFile($this->root.'/images/'.self::id($request).'.bin', self::MAX_IMAGE_BYTES);
        if($body === null || strlen($body) !== $data['size'] || !hash_equals(hash('sha256', $body), $data['sha256'])) {
            $this->invalidate($request); $this->enqueue($request,true);
            throw new RuntimeException('activity_display_pending');
        }
        return array('contentType'=>$data['contentType'],'body'=>$body);
    }

    private function attachments() {
        require_once __DIR__.'/display_attachments.php';
        return new PintogyaDisplayAttachments($this->attachmentRoot);
    }

    public function imageUrl($request) {
        if(($request['kind']??null)!=='public_image') { return null; }
        $entry=$this->peek($request);
        return $entry ? $this->attachments()->url($request,$entry['data']) : null;
    }

    public function materializeImages($limit=64) {
        $this->cli();
        if(!is_int($limit)||$limit<1||$limit>64) { throw new InvalidArgumentException('display_cache_batch_invalid'); }
        $attachments=$this->attachments();$attachments->collect($this->now(),$limit);$written=0;$scanned=0;$retired=0;
        foreach($this->jobFiles() as $path) {
            $job=$this->job($path);
            if(!$job || !str_ends_with($job['request']['kind'],'_image'))continue;
            $r=$job['request'];$entry=$this->peek($r);
            if($r['kind']==='member_image') {
                $entry=$this->retained($r);
                $body=null;
                if(!$entry) {
                    // A legacy public write may have preceded a failed snapshot write; retain its queued identity.
                    $body=$this->readFile($this->root.'/images/'.self::id($r).'.bin',self::MAX_IMAGE_BYTES);
                    if($body===null)continue;
                    $info=@getimagesizefromstring($body);$mime=$info['mime']??null;
                    if(!in_array($mime,array('image/jpeg','image/png','image/webp'),true))throw new RuntimeException('private_media_retirement_unverified');
                    $entry=array('data'=>array('contentType'=>$mime,'size'=>strlen($body),'sha256'=>hash('sha256',$body)));
                }
                if(!$attachments->hasMemberImage($r,$entry['data']))continue;
                if($scanned>=$limit)break;$scanned++;
                if($body===null)$body=$this->readFile($this->root.'/images/'.self::id($r).'.bin',self::MAX_IMAGE_BYTES);
                if($body===null)throw new RuntimeException('private_media_retirement_unverified');
                $this->directory($this->root.'/retired-member-images');
                $audit=function($evidence)use($r) {
                    $record=array('schema'=>1,'operation'=>'verified_public_mirror_retirement','preparedAt'=>$this->now(),
                        'requestId'=>self::id($r),'privateCopy'=>'images/'.self::id($r).'.bin')+$evidence;
                    $this->writeFile($this->root.'/retired-member-images/'.self::id($r).'.json',json_encode($record,JSON_THROW_ON_ERROR));
                };
                if($attachments->retireMemberImage($r,$entry['data'],$body,$audit))$retired++;
                continue;
            }
            if(!$entry || $attachments->url($r,$entry['data'])!==null)continue;
            if($scanned>=$limit)break;
            $scanned++;
            try {
                $image=$this->image($r);
                if($attachments->store($r,$entry['data'],$image['body'],$this->now()))$written++;
            } catch(RuntimeException $error) {
                if($error->getMessage()!=='activity_display_pending')throw $error;
            }
        }
        return array('scanned'=>$scanned,'written'=>$written,'retiredPrivate'=>$retired);
    }

    public function enqueue($request,$demand = false) {
        self::validate($request);
        if(!is_bool($demand)) { throw new InvalidArgumentException('display_cache_demand_invalid'); }
        if($this->peek($request) !== null) { return false; }
        return $this->locked(function() use($request,$demand) {
            $path = $this->jobPath($request); $job = $this->job($path); $now = $this->now();
            // Only a server-issued next cursor can create a pagination job.
            if($request['cursor'] !== '' && (!$job || self::expired($job['expiresAt'],$now))) { return false; }
            if($job && $demand && ($job['demandedAt']===0 || $job['demandedAt']<=$now-self::DEMAND_WINDOW)) {
                // Promote existing work, never erase its auth retry time, lease or failed-attempt history.
                $job['demandedAt']=$now;
                $this->writeFile($path,json_encode($job,JSON_THROW_ON_ERROR));
            }
        if($job && (in_array($job['state'], array('queued','leased'), true) ||
                ($job['state'] === 'held' && $job['notBefore'] > $now))) { return false; }
            if(!$job && count($this->jobFiles()) >= self::MAX_JOBS) { return false; }
            $this->writeFile($path, json_encode(array('request'=>$request,'state'=>'queued','attempts'=>0,
                'createdAt'=>$now,'notBefore'=>$now,'lease'=>'','leaseUntil'=>0,'expiresAt'=>$now+3600,
                'demandedAt'=>$demand?$now:0), JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function offer($request, $expiresAt) {
        $this->cli(); self::validate($request);
        return $this->saveOffer($request,$expiresAt);
    }

    public function offerNext($parent) {
        self::validate($parent);
        if(!in_array($parent['kind'],array('public_feed','public_comments','member_feed'),true)) { return false; }
        $entry=$this->peek($parent); $cursor=$entry['data']['nextCursor'] ?? null;
        if(!is_string($cursor) || $cursor==='' || $cursor===$parent['cursor']) { return false; }
        $request=$parent; $request['cursor']=$cursor; self::validate($request);
        // Recover an offer even if a worker stopped after publishing the page but before scheduling children.
        return $this->saveOffer($request,$entry['expiresAt']);
    }

    private function saveOffer($request, $expiresAt) {
        if($request['cursor'] === '' || !is_int($expiresAt) || $expiresAt < 0 || self::expired($expiresAt,$this->now()) ||
            $expiresAt > $this->now()+self::LEGACY_TTL) { throw new InvalidArgumentException('display_cache_offer_invalid'); }
        return $this->locked(function() use($request,$expiresAt) {
            $path=$this->jobPath($request);
            $existing=$this->job($path);
            if($existing && !self::expired($existing['expiresAt'],$this->now())) { return false; }
            if(!$existing && count($this->jobFiles())>=self::MAX_JOBS) { return false; }
            $now=$this->now();
            $this->writeFile($path,json_encode(array('request'=>$request,'state'=>'offered','attempts'=>0,
                'createdAt'=>$now,'notBefore'=>$now,'lease'=>'','leaseUntil'=>0,'expiresAt'=>$expiresAt,'demandedAt'=>0),JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function claim() {
        $this->cli();
        return $this->locked(function() {
            $due = array(); $now = $this->now();
            foreach($this->jobFiles() as $path) {
                $job = $this->job($path);
                if(!$job || self::expired($job['expiresAt'],$now) || $job['notBefore'] > $now ||
                    !in_array($job['state'], array('queued','leased','done'), true) || $job['attempts'] >= 5) { continue; }
                // Immutable image keys are refreshed only if missing/invalid; parent permission checks stay local.
                if($job['state']==='done' && str_ends_with($job['request']['kind'],'_image')) { continue; }
                $rank=$this->fillRank($job,$now);
                $due[] = array($rank,$job['createdAt'],basename($path),$job);
            }
            if(!$due) { return null; }
            usort($due, static function($a,$b) { return array_slice($a,0,3) <=> array_slice($b,0,3); });
            $job = $due[0][3]; $job['state']='leased'; $job['attempts']++;
            $job['lease']=bin2hex(random_bytes(16)); $job['leaseUntil']=$now+60; $job['notBefore']=$now+60;
            $this->writeFile($this->jobPath($job['request']), json_encode($job, JSON_THROW_ON_ERROR));
            return $job;
        });
    }

    private function fillRank($job,$now) {
        $r=$job['request'];$kind=$r['kind'];
        $base=array('public_detail'=>0,'public_feed'=>1,'public_comments'=>1,'public_image'=>2,
            'member_detail'=>3,'member_feed'=>4,'member_image'=>5)[$kind];
        // Valid mirrors can wait for periodic refresh; a pending refresh is not missing content.
        if($this->peek($r)!==null) { return 30+$base; }
        if($job['demandedAt']>0&&$job['demandedAt']>$now-self::DEMAND_WINDOW) { return $base; }
        if(in_array($kind,array('member_feed','member_image'),true)) {
            $parent=$r;$parent['kind']='member_detail';$parent['version']=0;
            $parent['stageId']=$parent['cursor']=$parent['media']='';
            if($this->peek($parent)===null) { return 17; }
            if($kind==='member_image') {
                $feed=$r;$feed['kind']='member_feed';$feed['media']='';
                if($this->peek($feed)===null) { return 17; }
            }
            // Finish this granted identity while its read session is usable, before the next cold identity.
            return $kind==='member_feed'?13:14;
        }
        return $kind==='member_detail'?15:10+$base;
    }

    public function hasForegroundDemand() {
        $now=$this->now();
        foreach($this->jobFiles() as $path) {
            $job=$this->job($path);
            if($job && $job['demandedAt']>0 && $job['demandedAt']>$now-self::DEMAND_WINDOW && $job['notBefore']<=$now &&
                !self::expired($job['expiresAt'],$now) && $job['attempts']<5 &&
                in_array($job['state'],array('queued','leased'),true) && $this->peek($job['request'])===null) { return true; }
        }
        return false;
    }

    public function hasInitialFillWork() {
        $this->cli(); $now=$this->now();
        foreach($this->jobFiles() as $path) {
            $job=$this->job($path);
            if($job && str_starts_with($job['request']['kind'],'member_') &&
                $job['notBefore']<=$now && !self::expired($job['expiresAt'],$now) && $job['attempts']<5 &&
                in_array($job['state'],array('queued','leased'),true) &&
                $this->peek($job['request'])===null) { return true; }
        }
        return false;
    }

    public function complete($lease, $data, $expiresAt = null, $image = null) {
        $this->cli();
        if(!is_array($data)) { throw new InvalidArgumentException('display_cache_payload_invalid'); }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if(strlen($json) > self::MAX_SNAPSHOT_BYTES || self::hasSecret($data)) { throw new InvalidArgumentException('display_cache_payload_invalid'); }
        return $this->locked(function() use($lease,$data,$expiresAt,$image,$json) {
            $job = $this->currentLease($lease); $now = $this->now();
            if($job === null) { return false; }
            $expiry = $expiresAt === null ? self::EXPIRES_NEVER : $expiresAt;
            if($expiry !== self::EXPIRES_NEVER) { throw new RuntimeException('display_cache_expiry_invalid'); }
            if($image !== null) {
                $this->validateImage($image);
                $bytes = strlen($image['body']);
                $imagePath=$this->root.'/images/'.self::id($job['request']).'.bin';
                $previousImage=$this->regular($imagePath) ? filesize($imagePath) : 0;
                if($this->diskBytes()-$previousImage+$bytes > self::MAX_DISK_BYTES) { throw new RuntimeException('display_cache_disk_full'); }
                $data = array('contentType'=>$image['contentType'],'size'=>$bytes,'sha256'=>hash('sha256',$image['body']));
                $this->writeFile($imagePath, $image['body']);
                if($job['request']['kind']==='public_image')$this->attachments()->store($job['request'],$data,$image['body'],$now);
            } elseif(str_ends_with($job['request']['kind'], '_image')) { throw new RuntimeException('display_cache_image_required'); }
            $entry = array('schema'=>2,'id'=>self::id($job['request']),'issuedAt'=>$now,'expiresAt'=>$expiry,
                'digest'=>hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),'data'=>$data);
            $snapshot=json_encode($entry,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $path=$this->root.'/snapshots/'.self::id($job['request']).'.json';
            $previous=$this->regular($path) ? filesize($path) : 0;
            if($this->diskBytes()-$previous+strlen($snapshot)>self::MAX_DISK_BYTES) { throw new RuntimeException('display_cache_disk_full'); }
            $this->writeFile($path,$snapshot);
            $this->unlinkFile($this->denialPath($job['request']));
            try { call_user_func($this->save,$this->nativeKey($job['request']),$entry); }
            catch(Throwable $error) { /* The durable mirror survives native cache outages/refreshes. */ }
            $job['state']='done'; $job['attempts']=0; $job['lease']=''; $job['leaseUntil']=0; $job['expiresAt']=$expiry;
            $job['notBefore']=$now+self::REFRESH_INTERVAL;
            $this->writeFile($this->jobPath($job['request']),json_encode($job,JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function defer($lease, $retryAt) {
        $this->cli();
        if(!is_int($retryAt) || $retryAt < 1 || $retryAt > $this->now()+self::RETRY_HOLD) {
            throw new InvalidArgumentException('display_cache_defer_invalid');
        }
        return $this->locked(function() use($lease,$retryAt) {
            $job=$this->currentLease($lease); if(!$job) { return false; }
            // Waiting for the existing auth quota is not a failed source attempt or a new permission lease.
            $job['state']='queued'; $job['attempts']=max(0,$job['attempts']-1);
            $job['notBefore']=max($this->now()+1,$retryAt); $job['lease']=''; $job['leaseUntil']=0;
            $this->writeFile($this->jobPath($job['request']),json_encode($job,JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function failed($lease, $retryAfter = null) {
        $this->cli();
        if($retryAfter !== null && (!is_int($retryAfter) || $retryAfter<1 || $retryAfter>900)) { throw new InvalidArgumentException('display_cache_retry_invalid'); }
        return $this->locked(function() use($lease,$retryAfter) {
            $job = $this->currentLease($lease);
            if(!$job) { return false; }
            $job['state']=$job['attempts'] >= 5 ? 'held' : 'queued';
            $job['notBefore']=$this->now()+max($retryAfter ?? 0,$job['state']==='held' ? self::RETRY_HOLD : min(300,15*(2 ** $job['attempts'])));
            $job['lease']=''; $job['leaseUntil']=0;
            $this->writeFile($this->jobPath($job['request']),json_encode($job,JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function invalidate($request) {
        self::validate($request);
        $this->locked(function() use($request) {
            $this->unlinkFile($this->root.'/snapshots/'.self::id($request).'.json');
            try { call_user_func($this->remove, $this->nativeKey($request)); } catch(Throwable $error) { /* Disk removal is authoritative. */ }
            $path = $this->jobPath($request); $this->unlinkFile($path);
            $this->unlinkFile($this->root.'/images/'.self::id($request).'.bin');
            $this->unlinkFile($this->denialPath($request));
        });
    }

    private function denialPath($request) { return $this->root.'/snapshots/'.self::id($request).'.denied'; }
    private function denied($request) {
        $path=$this->denialPath($request);
        return file_exists($path) || is_link($path);
    }

    public function deny($request) {
        $this->cli(); self::validate($request);
        $this->locked(function() use($request) { $this->writeFile($this->denialPath($request),"1\n"); });
    }

    public function rejected($lease) {
        $this->cli();
        return $this->locked(function() use($lease) {
            $job=$this->currentLease($lease); if(!$job) { return false; }
            $entry=$this->retained($job['request']);
            $this->writeFile($this->denialPath($job['request']),"1\n");
            $job['state']='held'; $job['notBefore']=$this->now()+self::RETRY_HOLD;
            $job['expiresAt']=$entry ? self::EXPIRES_NEVER : $job['notBefore'];
            $job['lease']=''; $job['leaseUntil']=0;
            $this->writeFile($this->jobPath($job['request']),json_encode($job,JSON_THROW_ON_ERROR));
            return true;
        });
    }

    public function collect($limit = 64) {
        $this->cli();
        if(!is_int($limit) || $limit < 1 || $limit > 64) { throw new InvalidArgumentException('display_cache_gc_limit_invalid'); }
        return $this->locked(function() use($limit) {
            $removed = 0; $now = $this->now();
            foreach($this->jobFiles() as $path) {
                if($removed >= $limit) { break; }
                $job = $this->job($path);
                if($job&&$job['request']['kind']==='member_image'&&$this->regular($this->root.'/images/'.self::id($job['request']).'.bin')) { continue; }
                if($job && (!self::expired($job['expiresAt'],$now) || $job['leaseUntil'] > $now)) { continue; }
                $id = basename($path, '.json');
                call_user_func($this->remove, $this->keyForId($id));
                // Queue expiry never deletes content, image bytes or a permission-denial marker.
                $this->unlinkFile($path); $removed++;
            }
            foreach(array('queue','images','snapshots') as $directory) {
                foreach(glob($this->root.'/'.$directory.'/.write-*') ?: array() as $path) {
                    if($removed >= $limit) { break 2; }
                    if($this->regular($path) && filemtime($path) <= $now-120) { $this->unlinkFile($path); $removed++; }
                }
            }
            return $removed;
        });
    }

    private function currentLease($lease) {
        if(!is_array($lease) || !is_array($lease['request'] ?? null) || !is_string($lease['lease'] ?? null)) { return null; }
        $job = $this->job($this->jobPath($lease['request']));
        return $job && $job['state']==='leased' && $job['leaseUntil'] > $this->now() &&
            hash_equals($job['lease'],$lease['lease']) ? $job : null;
    }
    private function jobPath($request) { return $this->root.'/queue/'.self::id($request).'.json'; }
    private static function expired($expiresAt,$now) { return $expiresAt!==self::EXPIRES_NEVER && $expiresAt<=$now; }
    private function jobFiles() { return glob($this->root.'/queue/'.str_repeat('[a-f0-9]',64).'.json') ?: array(); }
    private function job($path) {
        $raw = $this->readFile($path, 8192);
        if($raw === null) { return null; }
        try {
            $job = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($job)) { return null; }
            $keys=array('request','state','attempts','createdAt','notBefore','lease','leaseUntil','expiresAt');
            if(array_keys($job)===$keys) { $job['demandedAt']=0; } // Existing month-mirror queue, no destructive migration.
            if(array_keys($job)!==array_merge($keys,array('demandedAt'))) { return null; }
            self::validate($job['request']);
            if($path!==$this->jobPath($job['request']) || !in_array($job['state'],array('queued','leased','held','done','offered'),true) ||
                !is_int($job['attempts']) || $job['attempts']<0 || $job['attempts']>5 || !is_string($job['lease']) ||
                !preg_match('/^(?:[a-f0-9]{32})?$/D',$job['lease'])) { return null; }
            foreach(array('createdAt','notBefore','leaseUntil','expiresAt','demandedAt') as $key) if(!is_int($job[$key]) || $job[$key]<0) { return null; }
            if($job['expiresAt']!==self::EXPIRES_NEVER && $this->retained($job['request'])!==null) {
                $job['expiresAt']=self::EXPIRES_NEVER;
            }
            return $job;
        } catch(Throwable $error) { return null; }
    }
    private function locked($callback) {
        $path=$this->root.'/queue.lock';
        if(is_link($path) || (file_exists($path) && !$this->regular($path))) { throw new RuntimeException('display_cache_lock_invalid'); }
        $old=umask(0077);
        try { $handle=fopen($path,'c+b'); } finally { umask($old); }
        if(!$handle) { throw new RuntimeException('display_cache_lock_unavailable'); }
        try {
            $stat=fstat($handle);
            if(!$this->owned($stat) || $stat['nlink']!==1 || !flock($handle,LOCK_EX)) { throw new RuntimeException('display_cache_lock_invalid'); }
            return $callback();
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
    private function directory($path) {
        if(!file_exists($path) && !is_link($path) && !@mkdir($path,0700) && !is_dir($path)) { throw new RuntimeException('display_cache_directory_unavailable'); }
        clearstatcache(true,$path); $stat=@lstat($path);
        if(!$stat || is_link($path) || ($stat['mode'] & 0170000)!==0040000 ||
            ($stat['mode'] & 0077)!==0 || !$this->owned($stat)) { throw new RuntimeException('display_cache_directory_invalid'); }
    }
    private function owned($stat) { return is_array($stat) && function_exists('posix_geteuid') && $stat['uid']===posix_geteuid(); }
    private function regular($path) {
        clearstatcache(true,$path); $stat=@lstat($path);
        return $stat && ($stat['mode'] & 0170000)===0100000 && $stat['nlink']===1 &&
            ($stat['mode'] & 0077)===0 && $this->owned($stat);
    }
    private function readFile($path,$limit) {
        if(!$this->regular($path)) { return null; }
        $handle=@fopen($path,'rb'); if(!$handle) { return null; }
        try { $s=fstat($handle); return $s['size']<1 || $s['size']>$limit ? null : stream_get_contents($handle,$limit+1); }
        finally { fclose($handle); }
    }
    private function writeFile($path,$body) {
        if(file_exists($path) && !$this->regular($path)) { throw new RuntimeException('display_cache_file_invalid'); }
        if(is_link($path)) { throw new RuntimeException('display_cache_file_invalid'); }
        $temp=dirname($path).'/.write-'.bin2hex(random_bytes(12)); $old=umask(0077);
        try { $handle=fopen($temp,'xb'); } finally { umask($old); }
        if(!$handle) { throw new RuntimeException('display_cache_write_failed'); }
        try {
            if(fwrite($handle,$body)!==strlen($body) || !fflush($handle)) { throw new RuntimeException('display_cache_write_failed'); }
            fclose($handle); $handle=null;
            if(!rename($temp,$path)) { throw new RuntimeException('display_cache_write_failed'); }
        } finally { if(is_resource($handle)) { fclose($handle); } if(is_file($temp)) { unlink($temp); } }
    }
    private function unlinkFile($path) { if($this->regular($path)) { unlink($path); } }
    private function diskBytes() {
        $bytes=0; $files=array_merge(glob($this->root.'/images/*.bin') ?: array(),glob($this->root.'/images/.write-*') ?: array(),
            glob($this->root.'/snapshots/*.json') ?: array());
        if(count($files)>self::MAX_JOBS*2) { throw new RuntimeException('display_cache_disk_full'); }
        foreach($files as $path) { if($this->regular($path)) { $bytes+=filesize($path); } }
        return $bytes;
    }
    private function validateImage($image) {
        if(!is_array($image) || !is_string($image['body'] ?? null) || strlen($image['body'])<1 ||
            strlen($image['body'])>self::MAX_IMAGE_BYTES) { throw new RuntimeException('display_cache_image_invalid'); }
        $info=@getimagesizefromstring($image['body']);
        $mime=$info['mime'] ?? null;
        if(!$info || $info[0]<1 || $info[1]<1 ||
            !in_array($mime,array('image/jpeg','image/png','image/webp'),true) || $mime!==($image['contentType'] ?? null)) {
            throw new RuntimeException('display_cache_image_invalid');
        }
    }
    private static function hasSecret($data) {
        foreach($data as $key=>$value) {
            if(is_string($key) && preg_match('/^(?:accessToken|refreshToken|authorization|cookie|password|email)$/iD',$key)) { return true; }
            if(is_array($value) && self::hasSecret($value)) { return true; }
        }
        return false;
    }
    private function cli() { if(PHP_SAPI!=='cli') { throw new RuntimeException('display_cache_cli_only'); } }
}
