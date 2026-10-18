<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_facts.php';

// A journey is capped at 100. Walk existing latest-feed keysets without retaining other members or media.
final class PintogyaActivityInstantHistory {
    public static function decode($value) {
        if(!is_string($value) || strlen($value)>16384) { throw new RuntimeException('instant_history_checkpoint_invalid'); }
        if($value==='') { return null; }
        try { $state=json_decode($value,true,16,JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('instant_history_checkpoint_invalid',0,$error); }
        if(!is_array($state)) { throw new RuntimeException('instant_history_checkpoint_invalid'); }
        return $state;
    }

    public static function advance($binding, $detail, $rule, $stageIds, $saved, $reader, &$budget, $clock, $stop, $onCandidate=null) {
        $context = PintogyaCrypto::digest('pintogya_instant_history_v1', array(
            'uid'=>(int)$binding['uid'], 'user'=>$binding['togya_user_id'], 'generation'=>(int)$binding['generation'],
            'rule'=>$rule, 'stage_ids'=>$stageIds));
        if($saved !== null && !is_array($saved)) { throw new RuntimeException('instant_history_checkpoint_invalid'); }
        $state = $saved;
        if($state === null || ($state['context'] ?? null) !== $context) {
            $state = array('context'=>$context,'remaining'=>$stageIds,'cursor'=>'','last'=>null,'first'=>null);
        }
        self::checkpoint($state, $stageIds, $binding, $rule, $clock());
        $candidates = array(); $pages = 0;
        while($state['remaining'] && $budget > 0 && $pages < 3 && !$stop()) {
            $stageId = $state['remaining'][0];
            $page = $reader($stageId, $state['cursor']); $pages++; $budget--;
            self::page($page, $state, $binding, $rule, $stageId, $clock());
            if($page['nextCursor'] !== null) { $state['cursor'] = $page['nextCursor']; continue; }
            if($state['first'] !== null) {
                $facts = PintogyaActivityInstantFacts::fromJourney($binding, $detail, array(
                    'activityId'=>$rule['activity_id'], 'participant'=>array('id'=>$binding['togya_user_id']),
                    'checkins'=>array($state['first']['checkin'])), $rule, $state['first']['observed_at']);
                if($onCandidate !== null) { foreach($facts['candidates'] as $candidate) { $onCandidate($candidate); } }
                $candidates = array_merge($candidates, $facts['candidates']);
            }
            array_shift($state['remaining']);
            $state['cursor']=''; $state['last']=null; $state['first']=null;
        }
        return array('checkpoint'=>$state['remaining'] ? $state : null, 'candidates'=>$candidates, 'pages'=>$pages);
    }

    private static function checkpoint($state, $stageIds, $binding, $rule, $now) {
        if(!is_array($state) || array_keys($state) !== array('context','remaining','cursor','last','first') ||
            !is_array($state['remaining']) || !array_is_list($state['remaining']) ||
            count($state['remaining']) > count($stageIds) ||
            $state['remaining'] !== array_slice($stageIds, count($stageIds)-count($state['remaining']))) {
            throw new RuntimeException('instant_history_checkpoint_invalid');
        }
        if($state['cursor'] === '') {
            if($state['last'] !== null || $state['first'] !== null) { throw new RuntimeException('instant_history_checkpoint_invalid'); }
            return;
        }
        if(!$state['remaining'] || !is_array($state['last']) ||
            array_keys($state['last']) !== array('created_at','id')) { throw new RuntimeException('instant_history_checkpoint_invalid'); }
        self::stamp($state['last']['created_at']); PintogyaCrypto::assertUuid($state['last']['id']);
        self::cursor($state['cursor'], $binding, $rule, $state['remaining'][0], $state['last']);
        if($state['first'] !== null) {
            if(!is_array($state['first']) || array_keys($state['first']) !== array('checkin','observed_at') ||
                !is_int($state['first']['observed_at']) || $state['first']['observed_at'] > $now) {
                throw new RuntimeException('instant_history_checkpoint_invalid');
            }
            $c = $state['first']['checkin'];
            if(!is_array($c) || array_keys($c) !== array('id','stageId','stageVersion','stagePosition','completesStage','createdAt') ||
                $c['stageId'] !== $state['remaining'][0] || $c['stageVersion'] !== $rule['stage_version'] ||
                $c['stagePosition'] !== $rule['stages'][$c['stageId']]['position'] || $c['completesStage'] !== true ||
                $state['first']['observed_at'] < 1 ||
                strtotime($c['createdAt']) > $state['first']['observed_at'] + 300) {
                throw new RuntimeException('instant_history_checkpoint_invalid');
            }
            PintogyaCrypto::assertUuid($c['id']); self::stamp($c['createdAt']);
            // An earliest own fact cannot precede the oldest row already scanned.
            if(self::older(array('created_at'=>$c['createdAt'],'id'=>$c['id']), $state['last'])) {
                throw new RuntimeException('instant_history_checkpoint_invalid');
            }
        }
    }

    private static function page($page, &$state, $binding, $rule, $stageId, $now) {
        if(!is_array($page) || ($page['activityId'] ?? null) !== $rule['activity_id'] ||
            ($page['stageId'] ?? null) !== $stageId || ($page['stageVersion'] ?? null) !== $rule['stage_version'] ||
            ($page['view'] ?? null) !== 'latest' || !array_key_exists('nextCursor',$page) ||
            !is_array($page['items'] ?? null) || !array_is_list($page['items']) || count($page['items']) > 20 ||
            ($page['nextCursor'] !== null && count($page['items']) !== 20)) {
            throw new RuntimeException('instant_history_page_invalid');
        }
        foreach($page['items'] as $item) {
            if(!is_array($item) || ($item['activityId'] ?? null) !== $rule['activity_id'] ||
                ($item['stageId'] ?? null) !== $stageId || ($item['stageVersion'] ?? null) !== $rule['stage_version'] ||
                !in_array($item['kind'] ?? null,array('completion','question'),true) ||
                !is_bool($item['completesStage'] ?? null)) { throw new RuntimeException('instant_history_page_invalid'); }
            $id = PintogyaCrypto::assertUuid($item['id'] ?? null);
            $author = PintogyaCrypto::assertUuid($item['author']['id'] ?? null);
            $stamp = self::stamp($item['createdAt'] ?? null);
            if(strtotime($stamp) > $now + 300) { throw new RuntimeException('instant_history_page_invalid'); }
            $tuple = array('created_at'=>$stamp,'id'=>$id);
            if($state['last'] !== null && !self::older($tuple,$state['last'])) {
                throw new RuntimeException('instant_history_order_invalid');
            }
            $state['last'] = $tuple;
            if($author === $binding['togya_user_id'] && $item['kind'] === 'completion' && $item['completesStage']) {
                $state['first'] = array('checkin'=>array('id'=>$id,'stageId'=>$stageId,
                    'stageVersion'=>$rule['stage_version'],'stagePosition'=>$rule['stages'][$stageId]['position'],
                    'completesStage'=>true,'createdAt'=>$stamp),'observed_at'=>$now);
            }
        }
        if($page['nextCursor'] !== null) {
            self::cursor($page['nextCursor'], $binding, $rule, $stageId, $state['last']);
            if($page['nextCursor'] === $state['cursor']) { throw new RuntimeException('instant_history_order_invalid'); }
        }
    }

    private static function older($a,$b) {
        $order = strcmp(self::stamp($a['created_at']), self::stamp($b['created_at']));
        return $order < 0 || ($order === 0 && strcmp($a['id'],$b['id']) < 0);
    }

    private static function stamp($value) {
        if(!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',$value,$p) ||
            !checkdate((int)$p[2],(int)$p[3],(int)$p[1]) || (int)$p[4]>23 || (int)$p[5]>59 || (int)$p[6]>59) {
            throw new RuntimeException('instant_history_page_invalid');
        }
        $date = new DateTimeImmutable($value);
        if($date->getTimestamp()<1 || $date->getTimestamp()>4294967295) { throw new RuntimeException('instant_history_page_invalid'); }
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function cursor($value,$binding,$rule,$stageId,$last) {
        if(!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D',$value) || $last === null) {
            throw new RuntimeException('instant_history_cursor_invalid');
        }
        $bytes=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
        if($bytes === false || rtrim(strtr(base64_encode($bytes),'+/','-_'),'=') !== $value) {
            throw new RuntimeException('instant_history_cursor_invalid');
        }
        try { $tuple=json_decode($bytes,true,16,JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('instant_history_cursor_invalid',0,$error); }
        if(!is_array($tuple) || !array_is_list($tuple) || count($tuple)!==4 ||
            $tuple[0] !== array($binding['togya_user_id'],$rule['activity_id'],$stageId,$rule['stage_version'],'latest') ||
            self::stamp($tuple[1]) !== self::stamp($last['created_at']) || $tuple[2] !== $last['id'] || $tuple[3] !== 0) {
            throw new RuntimeException('instant_history_cursor_invalid');
        }
    }
}
