<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaActivityNativePost {
    const NEUTRAL_TEXT = 'Togya activity';
    const NATIVE_PREFIX = "Togya activity\npintogya:";
    const NATIVE_SUFFIX = "\0\0\0pintogya";
    // Kept only for exact recognition of the experimental legacy format.
    const MARKER_PREFIX = "Togya activity\0\0\0pintogya:";

    public static function messageForDigest($digest) {
        if(!is_string($digest) || !preg_match('/^[0-9a-f]{64}$/D', $digest)) {
            throw new InvalidArgumentException('activity_reservation_digest_invalid');
        }
        return self::NATIVE_PREFIX.$digest.self::NATIVE_SUFFIX;
    }

    public static function digestFromMessage($message) {
        if(!is_string($message)) { return null; }
        if(strlen($message) === strlen(self::NATIVE_PREFIX) + 64 + strlen(self::NATIVE_SUFFIX) &&
            str_starts_with($message,self::NATIVE_PREFIX) && str_ends_with($message,self::NATIVE_SUFFIX)) {
            $digest=substr($message,strlen(self::NATIVE_PREFIX),64);
        } elseif(strlen($message) === strlen(self::MARKER_PREFIX) + 64 && str_starts_with($message,self::MARKER_PREFIX)) {
            $digest=substr($message,strlen(self::MARKER_PREFIX));
        } else { return null; }
        return preg_match('/^[0-9a-f]{64}$/D', $digest) ? $digest : null;
    }

    public static function hideRenderedMarker($tid) {
        global $_G,$postlist;
        $pid=(int)($_G['forum_firstpid']??0);$post=$postlist[$pid]??null;
        if(!is_int($tid)||$tid<1||!is_array($post)||(int)($post['tid']??0)!==$tid||empty($post['first'])||
            !is_string($post['message']??null)) { return false; }
        $message=str_replace(array("<br />\n","<br/>\n","<br>\n",'<br />','<br/>','<br>'),"\n",$post['message']);
        if(strlen($message)!==strlen(self::NATIVE_PREFIX)+64||self::digestFromMessage($message.self::NATIVE_SUFFIX)===null) { return false; }
        // Stock DX already removed the plugin trailer; suppress only the exact machine-owned body in memory.
        $postlist[$pid]['message']='';
        return true;
    }

    public static function upgradeLegacyMarkers($limit = 1000) {
        if(!defined('PINTOGYA_INSTALL_CONTEXT') || !is_int($limit) || $limit<1 || $limit>1000) {
            throw new RuntimeException('activity_marker_migration_context_invalid');
        }
        PintogyaInstallation::databaseBinding();
        $shards=DB::fetch_all('SELECT DISTINCT t.posttableid FROM %t a INNER JOIN %t t ON t.tid=a.tid WHERE a.tid>0 ORDER BY t.posttableid LIMIT 65',array('pintogya_activity','forum_thread'));
        if(count($shards)>64) { throw new RuntimeException('activity_marker_migration_bound_exceeded'); }
        $targets=array();
        foreach($shards as $shard) {
            $id=(int)$shard['posttableid'];$table=table_forum_post::get_tablename($id);
            if(!is_string($table) || !preg_match('/\Aforum_post(?:_[0-9]+)?\z/D',$table)) {
                throw new RuntimeException('activity_marker_table_invalid');
            }
            foreach(array($table,'forum_thread','pintogya_activity') as $logical) {
                if(strtoupper((string)DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array(DB::table($logical))))!=='INNODB') {
                    throw new RuntimeException('activity_marker_engine_invalid');
                }
            }
            $rows=DB::fetch_all('SELECT a.activity_pk,a.reservation_digest,p.pid FROM %t a INNER JOIN %t t ON t.tid=a.tid AND t.fid=a.fid AND t.authorid=a.owner_uid INNER JOIN %t p ON p.tid=t.tid AND p.fid=a.fid AND p.authorid=a.owner_uid AND p.first=1 WHERE t.special=127 AND t.posttableid=%d AND BINARY p.message=BINARY CONCAT(%s,a.reservation_digest) ORDER BY a.activity_pk LIMIT '.($limit+1),
                array('pintogya_activity','forum_thread',$table,$id,self::MARKER_PREFIX));
            foreach($rows as $row) {
                $row['table']=$table;$row['shard']=$id;
                $row['message']=self::messageForDigest($row['reservation_digest']);$targets[]=$row;
                if(count($targets)>$limit) { throw new RuntimeException('activity_marker_migration_bound_exceeded'); }
            }
        }
        $updated=0;
        foreach($targets as $row) {
            // Recheck ownership/mapping and exact old bytes atomically; never overwrite an edited body.
            DB::query('UPDATE %t p INNER JOIN %t t ON t.tid=p.tid AND t.fid=p.fid AND t.authorid=p.authorid INNER JOIN %t a ON a.tid=t.tid AND a.fid=t.fid AND a.owner_uid=t.authorid SET p.message=%s WHERE p.pid=%d AND p.first=1 AND t.special=127 AND t.posttableid=%d AND a.activity_pk=%d AND a.reservation_digest=%s AND BINARY p.message=BINARY %s',
                array($row['table'],'forum_thread','pintogya_activity',$row['message'],(int)$row['pid'],$row['shard'],(int)$row['activity_pk'],$row['reservation_digest'],self::MARKER_PREFIX.$row['reservation_digest']));
            $updated+=(int)DB::affected_rows();
        }
        return array('scanned'=>count($targets),'updated'=>$updated);
    }

    public static function actionToken($post) {
        $token = is_array($post) ? ($post['pintogya_action_token'] ?? null) : null;
        if(!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new InvalidArgumentException('activity_action_token_invalid');
        }
        return $token;
    }

    public static function canonicalParams($params, $prepared, $now) {
        if(!is_array($params) || !is_array($prepared) ||
            ($prepared['subject'] ?? null) !== self::NEUTRAL_TEXT ||
            ($prepared['message'] ?? null) !== self::NEUTRAL_TEXT ||
            !is_int($now) || $now < 1) {
            throw new InvalidArgumentException('activity_native_post_invalid');
        }
        PintogyaCrypto::assertUuid($prepared['activityId'] ?? null);
        $nativeMessage = self::messageForDigest($prepared['reservationDigest'] ?? null);
        foreach(array(
            'subject' => self::NEUTRAL_TEXT, 'message' => $nativeMessage,
            'content' => '', 'contentType' => 'text', 'contentEditor' => 'default',
            'typeid' => 0, 'sortid' => 0, 'readperm' => 0, 'price' => 0,
            'isanonymous' => 0, 'save' => 1, 'publishdate' => $now,
            'sticktopic' => 0, 'digest' => 0, 'ordertype' => 0,
            'hiddenreplies' => 0, 'tags' => '', 'htmlon' => 0,
            'imgcontent' => 0, 'imgcontentwidth' => 0, 'usesig' => 0,
            'typeexpiration' => 0, 'original' => '', 'source' => '',
            'geoloc' => '', 'allownoticeauthor' => 0,
            'bbcodeoff' => 0, 'smileyoff' => 0, 'parseurloff' => 0,
        ) as $key => $value) {
            $params[$key] = $value;
        }
        return $params;
    }
}
