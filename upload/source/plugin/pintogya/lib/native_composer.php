<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/activity_native_post.php';
require_once __DIR__.'/activity_forum_gate.php';

final class PintogyaNativeComposer {
    public $obj;
    private $model;
    private $uid;
    private $fid;
    private $session;
    private $tokenHash;
    private $params;
    private $used=false;

    public static function context($fid) {
        global $_G;
        $container=$GLOBALS['modthread']??null;
        if(!class_exists('discuz_container',false)||!($container instanceof discuz_container)||
            !class_exists('\\forum\\model_thread',false)||!($container->getobj() instanceof \forum\model_thread)||
            !is_array($GLOBALS['params']??null)||($_GET['action']??null)!=='newthread'||($_GET['specialextra']??null)!=='pintogya'||
            (int)($_G['uid']??0)<1||(int)($container->member['uid']??0)!==(int)$_G['uid']||
            !is_int($fid)||$fid<1||(int)($container->forum['fid']??0)!==$fid||(int)($_G['fid']??0)!==$fid) {
            throw new RuntimeException('activity_native_context_required');
        }
        return $container;
    }

    public static function assertInitial($fid) {
        global $_G;
        $container=self::context($fid);$model=$container->getobj();
        if((int)$model->tid!==0||(int)$model->pid!==0||!empty($model->param)||
            !is_array($GLOBALS['bfmethods']??null)||$GLOBALS['bfmethods']!==array()||
            !is_array($GLOBALS['afmethods']??null)||$GLOBALS['afmethods']!==array()||
            !in_array((int)($GLOBALS['params']['special']??0),array(0,127),true)||empty($_G['group']['allowsave'])) {
            throw new RuntimeException('activity_native_context_conflict');
        }
        $plugins=$_G['forum']['threadplugin']??array();if(is_string($plugins))$plugins=dunserialize($plugins);
        if(PintogyaActivityForumGate::assertNewThread($fid,$_G['setting'],127,'pintogya','pintogya/thread',
            $plugins,$_G['group']['allowthreadplugin']??array(),(int)$_G['uid'],
            in_array('pintogya',$_G['setting']['plugins']['available']??array(),true),(int)$_G['groupid'])!==true) {
            throw new RuntimeException('activity_thread_not_permitted');
        }
        $funding=$_POST['replycredit_extcredits']??($_GET['replycredit_extcredits']??'0');
        if(!is_scalar($funding)||!in_array(trim((string)$funding),array('','0'),true)) {
            throw new RuntimeException('activity_native_fields_invalid');
        }
        foreach(array('attachnew','attachdel','attachupdate','attachunused') as $key) {
            if(!empty($_POST[$key])||!empty($_GET[$key]))throw new RuntimeException('activity_native_fields_invalid');
        }
        foreach($_FILES??array() as $file) {
            $errors=$file['error']??null;$valid=true;
            if(is_array($errors))array_walk_recursive($errors,static function($error)use(&$valid){$valid=$valid&&$error===UPLOAD_ERR_NO_FILE;});
            else $valid=$errors===UPLOAD_ERR_NO_FILE;
            if(!$valid)throw new RuntimeException('activity_native_fields_invalid');
        }
        return $container;
    }

    public static function attach($fid,$token,$prepared,$session,$now) {
        global $_G;
        $container=self::assertInitial($fid);$bridge=new self();$bridge->model=$container->getobj();
        $bridge->uid=(int)$_G['uid'];$bridge->fid=$fid;$bridge->session=$session;$bridge->tokenHash=hash('sha256',$token);
        $bridge->params=PintogyaActivityNativePost::canonicalParams(array('special'=>127),$prepared,$now)+
            array('replycredit'=>0,'extramessage'=>'','closed'=>0,'tstatus'=>0,'pstatus'=>0);
        // Stock DX appends its plugin trailer after submit; seed raw parameters without a duplicate trailer.
        $GLOBALS['params']=$bridge->params;
        $GLOBALS['params']['message']=substr($bridge->params['message'],0,-strlen(PintogyaActivityNativePost::NATIVE_SUFFIX));
        foreach($bridge->params as $key=>$value) { $_GET[$key]=$_POST[$key]=$_REQUEST[$key]=$value; }
        foreach(array('attachnew','attachdel','attachupdate','attachunused','typeoption') as $key)$_GET[$key]=$_POST[$key]=array();
        foreach(array('replycredit_extcredits','replycredit_times','replycredit_membertimes','replycredit_random','rushreply','addfeed','adddynamic','adddynamic_doing','cronpublish','cronpublishdate') as $key)$_GET[$key]=$_POST[$key]=0;
        foreach(array('subject','content','contentType','contentEditor','typeid','sortid','original','source') as $key)$GLOBALS[$key]=$bridge->params[$key];
        $GLOBALS['cover_aid']=0;$GLOBALS['special']=127;
        $container->attach_before_method('newthread',array($bridge,'beforeNewThread'));
        return $bridge;
    }

    public function beforeNewThread($parameters) {
        global $_G;
        $container=self::context($this->fid);
        if($this->used||$this->obj!==$this->model||$container->getobj()!==$this->model||
            (int)$_G['uid']!==$this->uid||!hash_equals($this->session,pintogya_session_digest())||!empty($this->model->param)) {
            throw new RuntimeException('activity_native_context_changed');
        }
        foreach(array('subject','message','special','save','price','readperm','typeid','sortid','content','contentType','contentEditor') as $key) {
            if(!is_array($parameters)||($parameters[$key]??null)!==$this->params[$key])throw new RuntimeException('activity_native_parameters_changed');
        }
        $this->used=true;
        // Native model initialization fills only unset fields; subsequent native moderation/permission checks remain active.
        $this->model->param=$this->params;
    }

    public static function assertCompletion($fid,$tid,$token,$session) {
        global $_G;
        $container=self::context($fid);$found=null;
        foreach($container->getobj()->methods['newthread'][0]??array() as $callback) {
            if(is_array($callback)&&($callback[0]??null) instanceof self) {
                if($found!==null)throw new RuntimeException('activity_native_context_conflict');$found=$callback[0];
            }
        }
        if(!$found||!$found->used||$found->model!==$container->getobj()||(int)$container->tid!==$tid||
            (int)$_G['uid']!==$found->uid||!hash_equals($found->tokenHash,hash('sha256',$token))||!hash_equals($found->session,$session)) {
            throw new RuntimeException('activity_native_context_changed');
        }
        return $container;
    }
}
