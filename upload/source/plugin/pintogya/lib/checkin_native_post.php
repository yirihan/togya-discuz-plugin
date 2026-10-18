<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaCheckinNativePost {
    const PREFIX='Togya check-in discussion.';
    public static function message($tid) {
        return self::PREFIX."\n[url=forum.php?mod=viewthread&tid=".$tid.']View activity stages[/url]';
    }

    public static function authorizeOwner($tid,$owner) {
        global $_G;
        if((int)($_G['uid']??0)<1 || !is_int($tid)||$tid<1)throw new RuntimeException('checkin_interaction_unavailable');
        if(!empty($_G['config']['db']['map'])||strtolower((string)($_G['config']['db']['common']['engine']??''))!=='innodb') {
            throw new RuntimeException('checkin_interaction_unavailable');
        }
        $member=table_common_member::t()->fetch((int)$owner['uid']);
        if(!$member||in_array((int)$member['groupid'],array(4,5,6,7),true)||!empty($member['freeze'])||
            (!empty($member['groupexpiry'])&&(int)$member['groupexpiry']<=time()))throw new RuntimeException('checkin_interaction_unavailable');
        // Persist the caller's real session before evaluating a different member's native permissions.
        updatesession();$original=$_G;$active=true;
        register_shutdown_function(static function()use(&$active,$original){global $_G;if($active){$_G=$original;$active=false;}});
        try {
            $_G=self::copyContext($_G);
            $key='usergroup_'.(int)$member['groupid'];loadcache($key,!is_array($_G['cache'][$key]??null));
            $group=$_G['cache'][$key]??null;if(!is_array($group))throw new RuntimeException('checkin_interaction_unavailable');
            if((int)($group['radminid']??0)===0&&(int)$member['adminid']>0&&(int)$member['groupid']!==(int)$member['adminid']) {
                $key='admingroup_'.(int)$member['adminid'];loadcache($key,!is_array($_G['cache'][$key]??null));
                if(!is_array($_G['cache'][$key]??null))throw new RuntimeException('checkin_interaction_unavailable');
                $group=array_merge($group,$_G['cache'][$key]);
            }
            $_G['uid']=(int)$member['uid'];$_G['username']=$member['username'];$_G['member']=$member;
            $_G['groupid']=(int)$member['groupid'];$_G['adminid']=(int)$member['adminid'];$_G['group']=$group;
            $_G['forum']=array();$_G['thread']=array();require_once libfile('function/forum');require_once libfile('function/post');
            require_once libfile('function/forumlist');
            loadforum(null,$tid);$forum=$_G['forum']??array();$thread=$_G['forum_thread']??array();
            if((int)($thread['tid']??0)!==$tid||(int)($thread['special']??0)!==127||(int)($thread['displayorder']??-1)<0||
                !empty($thread['closed'])||(int)($forum['status']??0)!==1||!in_array($forum['type']??'',array('forum','sub'),true)||
                !empty($forum['password'])||(int)($forum['allowview']??0)===-1||(int)($forum['allowreply']??0)===-1||
                (!empty($forum['viewperm'])&&empty($forum['allowview'])&&!forumperm($forum['viewperm']))||
                (empty($forum['allowreply'])&&(empty($forum['replyperm'])?empty($group['allowreply']):!forumperm($forum['replyperm'])))) {
                throw new RuntimeException('checkin_interaction_unavailable');
            }
            PintogyaActivityReadAccess::assertCurrent($tid);
            if(checkautoclose($thread))throw new RuntimeException('checkin_interaction_unavailable');
        } finally { $_G=$original;$active=false; }
    }

    public static function create($tid,$owner,$now) {
        require_once libfile('function/forum');
        $thread=DB::fetch_first('SELECT * FROM %t WHERE tid=%d FOR UPDATE',array('forum_thread',$tid));
        if(!$thread||(int)$thread['special']!==127||(int)$thread['displayorder']<0||!empty($thread['closed'])) {
            throw new RuntimeException('checkin_interaction_unavailable');
        }
        $fid=(int)$thread['fid'];$forum=DB::fetch_first('SELECT fid,fup,status,type FROM %t WHERE fid=%d FOR UPDATE',array('forum_forum',$fid));
        if(!$forum||(int)$forum['status']!==1||!in_array($forum['type'],array('forum','sub'),true))throw new RuntimeException('checkin_interaction_unavailable');
        $pid=(int)insertpost(array('fid'=>$fid,'tid'=>$tid,'first'=>0,'author'=>$owner['username'],'authorid'=>(int)$owner['uid'],
            'subject'=>'','dateline'=>$now,'message'=>self::message($tid),'content'=>'{}','source'=>'{}',
            'useip'=>'127.0.0.1','port'=>0,'invisible'=>0,'anonymous'=>0,'usesig'=>0,'htmlon'=>0,
            'bbcodeoff'=>0,'smileyoff'=>1,'parseurloff'=>1,'attachment'=>0,'status'=>0));
        if($pid<1)throw new RuntimeException('checkin_interaction_insert_failed');
        $post=table_forum_post::t()->fetch_post('tid:'.$tid,$pid);
        if(!$post||(int)$post['authorid']!==(int)$owner['uid']||(int)$post['first']!==0)throw new RuntimeException('checkin_interaction_insert_failed');
        $fields=array('replies'=>1,'lastposter'=>array($owner['username']),
            'maxposition'=>array(max((int)($thread['maxposition']??0),(int)($post['position']??0))));
        if((int)$thread['lastpost']<$now)$fields['lastpost']=array($now);
        table_forum_thread::t()->increase($tid,$fields);
        $last=$tid."\t".cutstr($thread['subject'],80)."\t".$now."\t".$owner['username'];
        table_forum_forum::t()->update($fid,array('lastpost'=>$last));
        table_forum_forum::t()->update_forum_counter($fid,0,1,1);
        if($forum['type']==='sub')table_forum_forum::t()->update((int)$forum['fup'],array('lastpost'=>$last));
        // Imported anchors count as native posts, but never run reply-credit rules or activity rewards.
        DB::query('UPDATE %t SET posts=posts+1 WHERE uid=%d',array('common_member_count',(int)$owner['uid']));
        if(DB::affected_rows()!==1)throw new RuntimeException('checkin_interaction_counter_failed');
        return $pid;
    }

    public static function invalidate($tid,$pid) {
        require_once libfile('function/post');
        deletethreadcaches($tid);table_forum_postcache::t()->delete($pid);
    }
    private static function copyContext($value) {
        if(!is_array($value))return $value;$copy=array();foreach($value as $key=>$item)$copy[$key]=self::copyContext($item);return $copy;
    }
}
