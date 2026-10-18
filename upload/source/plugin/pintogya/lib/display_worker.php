<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/pintogya.php';

final class PintogyaDisplayWorker {
    const CURSOR_KEY='pintogya_display_prewarm_cursor';
    public static function run($limit=12) {
        global $_G;
        if(PHP_SAPI!=='cli' || !is_int($limit) || $limit<1 || $limit>20) { throw new RuntimeException('display_cache_cli_only'); }
        if((int)DB::result_first('SELECT available FROM '.DB::table('common_plugin').' WHERE identifier=%s',array('pintogya'))!==1) {
            return array('skipped'=>'plugin_disabled');
        }
        if(function_exists('loadcache')) { loadcache('plugin'); }
        if(!pintogya_config()['enabled']) { return array('skipped'=>'feature_disabled'); }
        $deadline=hrtime(true)/1000000000+20.0;
        $live=pintogya_services(null,null,null,$deadline);
        $cache=new PintogyaDisplayCache();
        $cursor=(int)DB::result_first('SELECT svalue FROM '.DB::table('common_setting').' WHERE skey=%s',array(self::CURSOR_KEY));
        $row=DB::fetch_first('SELECT activity_pk,activity_id,tid FROM '.DB::table('pintogya_activity').
            ' WHERE state_code=%s AND activity_pk>%d ORDER BY activity_pk LIMIT 1',array('active',$cursor));
        if(!$row && $cursor>0) {
            $row=DB::fetch_first('SELECT activity_pk,activity_id,tid FROM '.DB::table('pintogya_activity').
                ' WHERE state_code=%s ORDER BY activity_pk LIMIT 1',array('active'));
        }
        if($row) {
            try {
                $live['activity_thread']->localProjection((int)$row['tid']);
                // A bounded activity/participant sweep warms pages even when nobody opens the thread.
                $cache->enqueue(PintogyaDisplayCache::request('public_detail',$row['activity_id'],'en-US'));
                $key='pintogya_display_member_cursor_'.(int)$row['activity_pk'];
                $after=(int)DB::result_first('SELECT svalue FROM '.DB::table('common_setting').' WHERE skey=%s',array($key));
                $members=DB::fetch_all('SELECT p.participant_pk,p.uid,p.togya_user_id,p.binding_generation FROM '.DB::table('pintogya_participation').
                    ' p INNER JOIN '.DB::table('pintogya_binding').' b ON b.uid=p.uid AND b.togya_user_id=p.togya_user_id'.
                    ' AND b.generation=p.binding_generation WHERE p.activity_pk=%d AND p.state_code=%s AND b.state_code=%s'.
                    ' AND p.participant_pk>%d ORDER BY p.participant_pk LIMIT 64',array((int)$row['activity_pk'],'active','active',$after));
                $last=0;
                foreach($members as $memberRow) {
                    $cache->enqueue(PintogyaDisplayCache::request('member_detail',$row['activity_id'],'en-US',0,'','','',
                        array('uid'=>(int)$memberRow['uid'],'subject'=>$memberRow['togya_user_id'],'generation'=>(int)$memberRow['binding_generation'])));
                    $last=(int)$memberRow['participant_pk'];
                }
                DB::insert('common_setting',array('skey'=>$key,'svalue'=>(string)$last),false,true);
            } catch(Throwable $error) { /* Invalid local mappings never become upstream requests. */ }
            DB::insert('common_setting',array('skey'=>self::CURSOR_KEY,'svalue'=>(string)$row['activity_pk']),false,true);
        }
        $observed=static function($request) use($live) {
            global $_G;
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0')!=='1') { return; }
            $map=$live['repository']->activityById($request['activityId'],false);
            if(!$map || $map['state_code']!=='active') { throw new RuntimeException('activity_mapping_inactive'); }
            $binding=$live['session']->binding($request['uid']);
            $participant=$live['repository']->participationForUid((int)$map['activity_pk'],$request['uid'],false);
            if($participant && ($participant['state_code'] ?? null)==='revoked') { return; }
            if($participant && ($participant['state_code'] ?? null)==='active' &&
                ($participant['togya_user_id'] ?? null)===$binding['togya_user_id'] &&
                (int)($participant['binding_generation'] ?? 0)===(int)$binding['generation'] &&
                (int)($participant['stage_version'] ?? 0)===(int)$map['stage_version']) { return; }
            // New membership is established by the existing live verifier, never by a cached display response.
            $verified=$live['activity_join']->viewerState($request['uid'],(int)$map['tid'],$request['locale'],time());
            if(($verified['state'] ?? null)!=='joined') { throw new RuntimeException('activity_feed_not_available'); }
        };
        $member=new PintogyaMemberDisplayFill($cache,$live['session'],$observed);
        return (new PintogyaPublicDisplayFill($cache,$live['api'],$member))->run($limit,15);
    }
}
