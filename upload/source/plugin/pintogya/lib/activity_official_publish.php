<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_instant_config.php';
require_once __DIR__.'/activity_publish.php';
require_once __DIR__.'/activity_forum_gate.php';

final class PintogyaActivityOfficialPublish {
    private $repo;
    private $api;
    private $native;
    private $host;
    private $fid;
    private $enabled;
    private $credits;

    // TOGYA_PLUGIN pintogya_official_publish_failure_reason: closed messages, never raw exception details.
    public static function failureMessage($code) {
        $messages=array(
            'activity_posts_disabled'=>'未能发布：本站“新发布与扫码参与”尚未开启。请先保存运行开关；本次未创建主题或发奖。',
            'official_example_rules_unavailable'=>'未能发布：所选示例来源暂不可读。请读取公开活动预览；阶段正文是否解锁不作为发帖前置。本次未创建主题或发奖。',
            'official_example_details_invalid'=>'未能发布：Togya 返回的官方示例身份或阶段元数据未通过校验。请读取公开活动预览；本次未创建主题或发奖。',
            'instant_config_schema_unavailable'=>'未能发布：本站两类即时奖励规则表尚未安装。请检查 0010 安装结果；本次未创建主题或发奖。',
            'activity_reward_rules_unavailable'=>'未能发布：本站未来活动默认金额尚未有效配置。请保存原生积分字段、基础额和阶段额；本次未创建主题或发奖。',
            'official_publication_engine_unavailable'=>'未能发布：本站存在非 InnoDB 数据表，不能保证发布事务安全；本次未创建主题或发奖。',
            'official_publication_existing_conflict'=>'未能发布：该官方示例已有不一致的主题或规则记录。不能覆盖、另建重复主题或改写历史金额；请在只读对账页核对。',
            'official_publication_commit_outcome_unknown'=>'发布结果暂不确定。请重新打开本页核对同一活动；不能另建重复主题或凭提示发奖。',
            'official_publication_rollback_outcome_unknown'=>'发布结果暂不确定。请重新打开本页核对同一活动；不能另建重复主题或凭提示发奖。'
        );
        if(is_string($code) && isset($messages[$code])) { return $messages[$code]; }
        if(in_array($code,array('official_example_publisher_not_allowed','activity_publisher_group_not_permitted',
            'activity_thread_not_permitted'),true)) {
            return '未能发布：指定会员未通过本站 DX 原生发帖权限检查。请核对版块是否开启、会员用户组、Togya 特殊主题及发帖积分下限；用户组留空不会额外限制。';
        }
        if(in_array($code,array('official_native_publication_failed','official_native_publication_mismatch'),true)) {
            return '未能发布：DX 原生发帖未完成或结果不一致。请重新打开后台核对，不要创建重复主题或凭提示发奖。';
        }
        return '未能发布：来源读取或本地发布处理失败。请读取公开活动预览，并在只读对账页核对现有记录；本次未确认发布成功，不要反复创建主题。';
    }

    public function __construct($repo, $api, $native, $host, $fid, $enabled, $credits) {
        if(!PintogyaSiteProfile::validHost($host) ||
            !is_int($fid) || $fid < 1 || $fid > 16777215 || !is_array($credits)) {
            throw new InvalidArgumentException('official_publication_config_invalid');
        }
        $this->repo=$repo; $this->api=$api; $this->native=$native; $this->host=$host;
        $this->fid=$fid; $this->enabled=$enabled===true; $this->credits=$credits;
    }

    public function publishSelected($adminUid, $selection, $defaults, $now) {
        $selection=PintogyaActivityInstantConfig::officialSelections($selection,
            array('uid'=>$selection['publisher_uid'] ?? null));
        $results=array(); $uncertain=false;
        foreach($selection['activity_ids'] as $id) {
            if($uncertain) {
                $results[$id]=array('status'=>'not_attempted','message'=>'前一示例发布结果待核对，本项未尝试；请先读回现有主题。');
                continue;
            }
            try {
                $result=$this->publish($adminUid,array('activity_id'=>$id,
                    'publisher_uid'=>$selection['publisher_uid']),$defaults,$now);
                $results[$id]=array('status'=>'published')+$result;
            } catch(Throwable $error) {
                $uncertain=in_array($error->getMessage(),array('official_publication_commit_outcome_unknown',
                    'official_publication_rollback_outcome_unknown'),true);
                $results[$id]=array('status'=>$uncertain ? 'uncertain' : 'failed',
                    'message'=>self::failureMessage($error->getMessage()));
            }
        }
        return $results;
    }

    public function publish($adminUid, $selection, $defaults, $now) {
        if(!$this->enabled) { throw new RuntimeException('activity_posts_disabled'); }
        if(!is_int($adminUid) || $adminUid < 1 || $adminUid > 16777215 ||
            !is_int($now) || $now < 1 || $now > 4294967295 || !is_array($selection) ||
            !is_int($selection['publisher_uid'] ?? null)) {
            throw new InvalidArgumentException('official_publication_input_invalid');
        }
        $uid=$selection['publisher_uid'];
        $member=$this->native->publisher($uid,$this->fid);
        $selection=PintogyaActivityInstantConfig::officialSelection($selection,$member);
        $snapshot=PintogyaActivityOfficialExamples::readReference($this->api,$selection['activity_id'],'en-US');
        $activity=$snapshot;
        $activity['totalStages']=count($snapshot['stages']);
        $activity['stageDetails']=array();
        foreach($snapshot['stages'] as $stage) {
            $activity['stageDetails'][]=array('id'=>$stage['id'],'position'=>$stage['position'],
                'version'=>$snapshot['stageVersion'],'stageKind'=>$stage['kind'],'title'=>$stage['title']);
        }
        $digest=PintogyaCrypto::digest('pintogya/official-publication/v1',array('host'=>$this->host,'activityId'=>$activity['id']));
        $lock=$this->repo->acquireUidLock($uid); $committing=false; $unknown=false; $started=false;
        try {
            $this->repo->begin(); $started=true;
            $map=$this->repo->activityById($activity['id'],true);
            $stored=$this->repo->activityInstantRuleById($activity['id'],true);
            if($map || $stored) {
                $result=$this->existing($map,$stored,$activity,$uid,$digest);
            } else {
                if(!is_array($defaults) || ($defaults['version'] ?? null)!==PintogyaActivityInstantRule::VERSION) {
                    throw new RuntimeException('activity_reward_rules_unavailable');
                }
                $input=$defaults; unset($input['version']);
                $defaults=PintogyaActivityInstantRule::fromInput($input,$this->credits);
                $rule=PintogyaActivityInstantRule::snapshot($defaults,$activity);
                PintogyaCapabilities::assertPublicationRule($rule);
                $source=array('schema'=>2,'kind'=>'official-reference','admin_uid'=>$adminUid,
                    'publisher_uid'=>$uid,'fid'=>$this->fid,'reference'=>$snapshot);
                $this->repo->insertActivity(array('activity_id'=>$activity['id'],'fid'=>$this->fid,
                    'owner_uid'=>$uid,'binding_generation'=>0,'stage_version'=>$activity['stageVersion'],
                    'source_hash'=>PintogyaActivityPublish::sourceHash($activity),'reservation_digest'=>$digest,
                    'state_code'=>'pending','pending_expires_at'=>$now+900,'created_at'=>$now,
                    'updated_at'=>$now,'published_at'=>0));
                $this->repo->insertActivityInstantRule(array('activity_id'=>$activity['id'],
                    'rule_hash'=>PintogyaCrypto::digest('pintogya_instant_rule_v3',$rule),
                    'rule_json'=>PintogyaCrypto::canonicalJson($rule),'source_json'=>PintogyaCrypto::canonicalJson($source),
                    'state_code'=>'held','created_at'=>$now,'published_at'=>0));
                $native=$this->native->create($uid,$this->fid,$activity['id'],$snapshot['title'],$digest,$now);
                $tid=$native['tid'] ?? null; $publication=$native['publication'] ?? null;
                if(!is_int($tid) || $tid < 1 || $tid > 4294967295 || !in_array($publication,array(1,-1),true)) {
                    throw new RuntimeException('official_native_publication_failed');
                }
                $this->assertThread($tid,$uid,$digest,$publication);
                $this->repo->bindActivityTid($activity['id'],$digest,$tid,$now);
                $this->repo->publishActivityInstantRule($activity['id'],PintogyaCrypto::digest('pintogya_instant_rule_v3',$rule),$now);
                $this->repo->activateActivity($activity['id'],$digest,$tid,$now);
                $result=array('tid'=>$tid,'publication'=>$publication,'created'=>true);
            }
            $committing=true; $this->repo->commit(); $started=false;
            return $result;
        } catch(Throwable $error) {
            if($committing) {
                $unknown=true;
                throw new RuntimeException('official_publication_commit_outcome_unknown',0,$error);
            }
            if($started) {
                try { $this->repo->rollback(); }
                catch(Throwable $rollback) {
                    $unknown=true;
                    throw new RuntimeException('official_publication_rollback_outcome_unknown',0,$rollback);
                }
            }
            throw $error;
        } finally { if(!$unknown) { $this->repo->releaseUidLock($lock); } }
    }

    private function existing($map,$stored,$activity,$uid,$digest) {
        if(!$map || !$stored || $map['state_code']!=='active' || (int)$map['tid'] < 1 ||
            (int)$map['owner_uid']!==$uid || (int)$map['fid']!==$this->fid ||
            (int)$map['binding_generation']!==0 || !hash_equals((string)$map['reservation_digest'],$digest) ||
            !hash_equals((string)$map['source_hash'],PintogyaActivityPublish::sourceHash($activity)) ||
            (int)$map['stage_version']!==$activity['stageVersion'] ||
            $stored['state_code']!=='active' || (int)$stored['published_at']!==(int)$map['published_at']) {
            throw new RuntimeException('official_publication_existing_conflict');
        }
        $source=json_decode($stored['source_json'],true,512,JSON_THROW_ON_ERROR);
        $rule=PintogyaActivityInstantRule::storedSnapshot($stored);
        $legacy=($source['schema'] ?? null)===1 && ($source['kind'] ?? null)==='official-example';
        $reference=($source['schema'] ?? null)===2 && ($source['kind'] ?? null)==='official-reference';
        $sourceActivity=$source[$legacy ? 'snapshot' : 'reference'] ?? null;
        if(!is_array($source) || (!$legacy && !$reference) ||
            ($source['publisher_uid'] ?? null)!==$uid || ($source['fid'] ?? null)!==$this->fid ||
            !is_int($source['admin_uid'] ?? null) || $source['admin_uid'] < 1 ||
            ($sourceActivity['id'] ?? null)!==$activity['id'] ||
            $rule['activity_id']!==$activity['id'] || $rule['stage_version']!==$activity['stageVersion']) {
            throw new RuntimeException('official_publication_existing_conflict');
        }
        if(count($rule['stages']) !== count($activity['stageDetails'])) {
            throw new RuntimeException('official_publication_existing_conflict');
        }
        foreach($activity['stageDetails'] as $stage) {
            $frozen=$rule['stages'][$stage['id']] ?? null;
            if(!$frozen || $frozen['position']!==$stage['position'] || $frozen['kind']!==$stage['stageKind']) {
                throw new RuntimeException('official_publication_existing_conflict');
            }
        }
        $thread=$this->repo->activityThread((int)$map['tid'],true);
        $publication=(int)($thread['displayorder'] ?? -99)===-2 ? -1 : 1;
        $this->assertThread((int)$map['tid'],$uid,$digest,$publication);
        return array('tid'=>(int)$map['tid'],'publication'=>$publication,'created'=>false);
    }

    private function assertThread($tid,$uid,$digest,$publication) {
        $thread=$this->repo->activityThread($tid,true);
        if(!$thread || (int)$thread['fid']!==$this->fid || (int)$thread['authorid']!==$uid ||
            (int)$thread['special']!==127 ||
            ($publication===-1 ? (int)$thread['displayorder']!==-2 : (int)$thread['displayorder']<0) ||
            $this->repo->activityFirstPostMessage($tid)!==PintogyaActivityNativePost::messageForDigest($digest)) {
            throw new RuntimeException('official_native_publication_mismatch');
        }
    }
}

final class PintogyaActivityOfficialNative {
    public function publisher($uid,$fid) {
        return $this->withPublisher($uid,$fid,static function($member) { return $member; });
    }

    public function create($uid,$fid,$activityId,$title,$digest,$now) {
        return $this->withPublisher($uid,$fid,static function() use($activityId,$title,$digest,$now) {
            global $_G;
            if(!class_exists('\\forum\\model_thread')) { C::m('\\forum\\model_thread'); }
            $model=new \forum\model_thread();
            $model->showmessage=static function() { throw new RuntimeException('official_native_publication_failed'); };
            $params=PintogyaActivityNativePost::canonicalParams(array('special'=>127),array(
                'subject'=>PintogyaActivityNativePost::NEUTRAL_TEXT,'message'=>PintogyaActivityNativePost::NEUTRAL_TEXT,
                'activityId'=>$activityId,'reservationDigest'=>$digest),$now);
            $params['subject']=cutstr(trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',strip_tags($title))),80,'');
            $params['save']=0;
            $result=$model->newthread($params);
            if(!in_array($result,array('post_newthread_succeed','post_newthread_mod_succeed'),true) ||
                (int)$model->tid < 1) { throw new RuntimeException('official_native_publication_failed'); }
            return array('tid'=>(int)$model->tid,'publication'=>$result==='post_newthread_mod_succeed' ? -1 : 1);
        },true);
    }

    private function withPublisher($uid,$fid,$callback,$lock=false) {
        global $_G;
        if(!is_int($uid) || $uid < 1 || $uid > 16777215 || !is_int($fid) || $fid < 1 ||
            !defined('IN_ADMINCP') || (int)($_G['uid'] ?? 0) < 1) {
            throw new RuntimeException('official_example_publisher_not_allowed');
        }
        $member=DB::fetch_first('SELECT uid,username,groupid,adminid,accessmasks,credits,regdate,freeze,groupexpiry FROM %t WHERE uid=%d'
            .($lock ? ' FOR UPDATE' : ''),array('common_member',$uid));
        $forumInfo=DB::fetch_first('SELECT fid,status,type FROM %t WHERE fid=%d',array('forum_forum',$fid));
        if(!$member || (int)$member['uid']!==$uid || in_array((int)$member['groupid'],array(4,5,6,7),true) ||
            !empty($member['freeze']) || (!empty($member['groupexpiry']) && (int)$member['groupexpiry']<=TIMESTAMP) ||
            !$forumInfo || (int)$forumInfo['fid']!==$fid || (int)$forumInfo['status']!==1 ||
            !in_array($forumInfo['type'],array('forum','sub'),true)) {
            throw new RuntimeException('official_example_publisher_not_allowed');
        }
        $invalidEngines=DB::result_first("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'");
        if(!is_numeric($invalidEngines) || (int)$invalidEngines!==0) {
            throw new RuntimeException('official_publication_engine_unavailable');
        }
        // Native permission messages can exit instead of throwing. Persist only the real admin
        // session first, then restore request identity even on that native exit path.
        updatesession();
        $original=$_G; $active=true;
        register_shutdown_function(static function() use(&$active,$original) {
            global $_G;
            if($active) { $_G=$original; $active=false; }
        });
        try {
            // Detach nested references before the native model takes request-local member references.
            $_G=self::contextCopy($_G);
            // TOGYA_PLUGIN pintogya_official_native_cache_reentry: loadcache's static loaded set survives context restoration.
            $key='usergroup_'.(int)$member['groupid']; loadcache($key,!is_array($_G['cache'][$key] ?? null));
            $group=$_G['cache'][$key] ?? null;
            if(!is_array($group)) { throw new RuntimeException('official_example_publisher_not_allowed'); }
            if((int)($group['radminid'] ?? 0)===0 && (int)$member['adminid']>0 &&
                (int)$member['groupid']!==(int)$member['adminid']) {
                $adminKey='admingroup_'.(int)$member['adminid'];
                loadcache($adminKey,!is_array($_G['cache'][$adminKey] ?? null));
                if(!is_array($_G['cache'][$adminKey] ?? null)) { throw new RuntimeException('official_example_publisher_not_allowed'); }
                $group=array_merge($group,$_G['cache'][$adminKey]);
            }
            $_G['uid']=$uid; $_G['username']=$member['username']; $_G['member']=$member;
            $_G['groupid']=(int)$member['groupid']; $_G['adminid']=(int)$member['adminid']; $_G['group']=$group;
            $_G['forum']=array(); $_G['thread']=array();
            require_once libfile('function/forum'); require_once libfile('function/post');
            loadforum($fid,0);
            $forum=$_G['forum'] ?? array();
            if((int)($forum['fid'] ?? 0)!==$fid || (int)($forum['status'] ?? 0)!==1 ||
                !in_array($forum['type'] ?? null,array('forum','sub'),true) || !empty($forum['password']) ||
                (int)($forum['allowpost'] ?? 0)===-1 || (int)($forum['allowview'] ?? 0)===-1 ||
                (!empty($forum['viewperm']) && empty($forum['allowview']) && !forumperm($forum['viewperm'])) ||
                (empty($forum['allowpost']) && (empty($forum['postperm']) ? empty($_G['group']['allowpost']) : !forumperm($forum['postperm'])))) {
                throw new RuntimeException('official_example_publisher_not_allowed');
            }
            // TOGYA_PLUGIN pintogya_official_native_forum_plugin_decode: loadforum keeps the stored serialized value.
            $forumPlugins=$forum['threadplugin'] ?? array();
            if(is_string($forumPlugins)) { $forumPlugins=dunserialize($forumPlugins); }
            if(PintogyaActivityForumGate::assertNewThread($fid,$_G['setting'],127,'pintogya','pintogya/thread',
                $forumPlugins,$_G['group']['allowthreadplugin'] ?? array(),$uid,
                in_array('pintogya',$_G['setting']['plugins']['available'] ?? array(),true),$_G['groupid'])!==true) {
                throw new RuntimeException('official_example_publisher_not_allowed');
            }
            // Native formulaperm may return false for an exempt member; denial calls showmessage.
            if(!empty($forum['formulaperm'])) { formulaperm($forum['formulaperm']); }
            require_once libfile('function/credit');
            if(_checklowerlimit('post',$uid,1,$fid,1)!==true) {
                throw new RuntimeException('official_example_publisher_not_allowed');
            }
            return $callback($member);
        } finally { $_G=$original; $active=false; }
    }

    private static function contextCopy($value) {
        if(!is_array($value)) { return $value; }
        $copy=array();
        foreach($value as $key=>$item) { $copy[$key]=self::contextCopy($item); }
        return $copy;
    }
}
