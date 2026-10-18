<?php
// Real model classes with synthetic in-memory storage/API; no HTTP, DX or SQL proof.
define('IN_DISCUZ',true);define('PINTOGYA_STATIC_TEST',true);define('TIMESTAMP',1800000000);
$source=getenv('TOGYA_PLUGIN_SOURCE');
if(!$source||!is_file($source.'/lib/binding.php')||!extension_loaded('sodium'))exit(2);
require $source.'/lib/crypto.php';require $source.'/lib/binding.php';require $source.'/lib/session.php';

final class TogyaQaProtocolStore {
    public $bindings=array();public $challenges=array();public $owner=1;public $deactivated=0;
    private $snapshot;
    public function assertCurrentTaskInstance($uid,$task,$instance){if($uid!==$this->owner||$task!==0)throw new RuntimeException('fixture_owner_mismatch');}
    public function bindingForUid($uid,$lock){return $this->bindings[$uid]??null;}
    public function activeBindingForUid($uid,$lock){$row=$this->bindingForUid($uid,$lock);return $row&&$row['state_code']==='active'?$row:null;}
    public function bindingForTogyaUser($id,$lock){foreach($this->bindings as$row)if($row['togya_user_id']===$id)return $row;return null;}
    public function latestChallenge($uid,$task,$instance,$lock){$found=null;foreach($this->challenges as$row)if($row['uid']===$uid&&$row['task_id']===$task&&$row['task_instance']===$instance)$found=$row;return $found;}
    public function acquireUidLock($uid){return 'synthetic-lock';}
    public function releaseUidLock($lock){}
    public function begin(){if($this->snapshot!==null)throw new RuntimeException('nested_fixture_transaction');$this->snapshot=array($this->bindings,$this->challenges,$this->deactivated);}
    public function commit(){$this->snapshot=null;}
    public function rollback(){if($this->snapshot!==null)list($this->bindings,$this->challenges,$this->deactivated)=$this->snapshot;$this->snapshot=null;}
    public function supersedePendingChallenges($uid,$task,$instance,$now){foreach($this->challenges as$id=>$row)if($row['uid']===$uid&&$row['task_id']===$task&&$row['task_instance']===$instance&&$row['state_code']==='pending')$this->challenges[$id]['state_code']='superseded';}
    public function supersedeAllChallengesForUid($uid,$now){foreach($this->challenges as$id=>$row)if($row['uid']===$uid&&$row['state_code']==='pending')$this->challenges[$id]['state_code']='superseded';}
    public function insertChallenge($row){$this->challenges[$row['challenge_id']]=$row;}
    public function updateChallenge($id,$values,$expected){if(($this->challenges[$id]['state_code']??null)!==$expected)throw new RuntimeException('fixture_challenge_state');$this->challenges[$id]=array_replace($this->challenges[$id],$values);}
    public function createBinding($row){if(isset($this->bindings[$row['uid']]))throw new RuntimeException('fixture_binding_duplicate');$this->bindings[$row['uid']]=$row;}
    public function updateBinding($uid,$values,$generation=null){if(!isset($this->bindings[$uid])||($generation!==null&&$this->bindings[$uid]['generation']!==$generation))throw new RuntimeException('binding_changed');$this->bindings[$uid]=array_replace($this->bindings[$uid],$values);}
    public function deactivateActivityForUidIfInstalled($uid,$now){$this->deactivated++;}
    public function revokeBinding($uid,$now){$this->updateBinding($uid,array('state_code'=>'revoked','access_ciphertext'=>'','revoked_at'=>$now,'updated_at'=>$now));}
    public function finishBindingRevoke($uid,$generation,$now){$this->updateBinding($uid,array('access_ciphertext'=>'','refresh_ciphertext'=>'','updated_at'=>$now),$generation);}
}

final class TogyaQaProtocolApi {
    public $starts=0;public $polls=0;public $refreshes=0;public $reads=0;public $logouts=0;
    public $mode='pending';public $badHost=false;public $extraStart=false;public $logoutFails=false;
    public $userId='synthetic-provider-a';public $onRead=null;public $readFailures=0;
    public function startBrowserLogin($hash,$locale){
        $this->starts++;$id=PintogyaCrypto::uuid();$nonce=PintogyaCrypto::b64urlEncode(str_repeat('n',32));
        $body=array('challengeId'=>$id,'createdAt'=>gmdate('c',TIMESTAMP),'expiresAt'=>gmdate('c',TIMESTAMP+120),
            'pollIntervalSeconds'=>2,'qrUrl'=>'https://'.($this->badHost?'other.example.net':'togya.com').'/auth/browser?challenge='.$id.'&nonce='.$nonce);
        if($this->extraStart)$body['unexpected']=true;return $body;
    }
    public function session(){return array('accessToken'=>'synthetic-access-only','refreshToken'=>'synthetic-refresh-'.str_repeat('r',48),'expiresIn'=>3600,
        'user'=>array('id'=>$this->userId,'displayName'=>'Synthetic Person','avatarUrl'=>null,'email'=>null));}
    public function pollBrowserLogin($id,$verifier,$device){$this->polls++;if($this->mode==='error')throw new RuntimeException('api_http_403');return $this->mode==='approved'?array('status'=>'approved','session'=>$this->session()):array('status'=>'pending','session'=>null);}
    public function logoutSession($refresh){$this->logouts++;if($this->logoutFails)throw new RuntimeException('synthetic_remote_failure');return true;}
    public function refreshSession($refresh){$this->refreshes++;return $this->session();}
    public function getConnections($access){$this->reads++;if($this->readFailures>0){$this->readFailures--;throw new RuntimeException('api_unauthorized');}if($this->onRead)($this->onRead)();return array('items'=>array());}
}

$checks=0;$cases=array();
function qaCheck($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function qaReject($callback,$code){try{$callback();}catch(Throwable $error){qaCheck($error->getMessage()===$code,'wrong_closed_error');return;}throw new RuntimeException('expected_rejection');}
function qaFixture(){
    $repo=new TogyaQaProtocolStore();$api=new TogyaQaProtocolApi();$key=str_repeat('k',32);
    return array($repo,$api,new PintogyaBinding($repo,$api,$key),$key);
}
function qaStart($binding,$uid=1,$digest=null){return $binding->startChallenge($uid,0,'account',$digest??hash('sha256','synthetic-forum-session'),'en-US');}
function qaPoll($binding,$uid=1,$digest=null,$errors=false){return $binding->pollLatest($uid,0,'account',$digest??hash('sha256','synthetic-forum-session'),$errors);}
function qaApproved(){list($repo,$api,$binding,$key)=qaFixture();qaStart($binding);$api->mode='approved';qaCheck(qaPoll($binding),'approval_setup');return array($repo,$api,$binding,$key);}
function qaCase($name,$callback){global $cases;try{$callback();$cases[]=array('case'=>$name,'passed'=>true);}catch(Throwable $error){$cases[]=array('case'=>$name,'passed'=>false,'class'=>get_class($error));}}

qaCase('pending_does_not_bind',static function(){
    list($repo,$api,$binding)=qaFixture();$start=qaStart($binding);qaCheck(str_starts_with($repo->challenges[$start['challengeId']]['device_id'],'togya-forum-'),'generic_device');
    qaCheck(qaPoll($binding)===false&&$repo->bindings===array(),'pending_not_approval');
});
qaCase('approval_encrypted_and_idempotent',static function(){
    list($repo,$api,$binding,$key)=qaApproved();$row=$repo->bindings[1];
    qaCheck($row['access_ciphertext']!==$api->session()['accessToken']&&$row['refresh_ciphertext']!==$api->session()['refreshToken'],'stored_encrypted');
    qaCheck(PintogyaCrypto::open($row['access_ciphertext'],PintogyaCrypto::tokenAad(1,$row['togya_user_id'],1,'access'),$key)===$api->session()['accessToken'],'owner_aad');
    qaCheck(qaPoll($binding)&&$api->polls===1&&count($repo->bindings)===1&&$repo->bindings[1]['generation']===1,'repeat_no_new_session');
});
qaCase('wrong_session_cannot_poll',static function(){
    list($repo,$api,$binding)=qaFixture();qaStart($binding);qaCheck(qaPoll($binding,1,hash('sha256','other-session'))===false&&$api->polls===0,'foreign_session_not_read');
});
qaCase('invalid_uid_cannot_start',static function(){
    list($repo,$api,$binding)=qaFixture();qaReject(static function()use($binding){qaStart($binding,0);},'challenge_owner_invalid');qaCheck($api->starts===0,'invalid_owner_no_source');
});
qaCase('expired_challenge_wipes_verifier',static function(){
    list($repo,$api,$binding)=qaFixture();$start=qaStart($binding);$repo->challenges[$start['challengeId']]['expires_at']=TIMESTAMP-1;
    qaCheck(qaPoll($binding)===false&&$api->polls===0,'expired_no_source');
    qaCheck($repo->challenges[$start['challengeId']]['state_code']==='expired'&&$repo->challenges[$start['challengeId']]['verifier_ciphertext']==='','expired_wiped');
});
qaCase('denied_challenge_does_not_bind',static function(){
    list($repo,$api,$binding)=qaFixture();$start=qaStart($binding);$repo->challenges[$start['challengeId']]['state_code']='denied';
    qaCheck(qaPoll($binding)===false&&$repo->bindings===array()&&$api->polls===0,'denial_terminal');
});
qaCase('provider_failure_fails_closed',static function(){
    list($repo,$api,$binding)=qaFixture();qaStart($binding);$api->mode='error';qaCheck(qaPoll($binding)===false&&$repo->bindings===array(),'provider_error_no_binding');
});
qaCase('qr_origin_and_closed_fields',static function(){
    list($repo,$api,$binding)=qaFixture();$api->badHost=true;qaReject(static function()use($binding){qaStart($binding);},'qr_url_invalid');qaCheck($repo->challenges===array(),'unsafe_qr_not_stored');
    $api->badHost=false;$api->extraStart=true;qaReject(static function()use($binding){qaStart($binding);},'closed_object_invalid');
});
qaCase('existing_identity_cannot_change',static function(){
    list($repo,$api,$binding)=qaApproved();$saved=$repo->bindings[1];qaStart($binding);$api->userId='synthetic-provider-b';
    qaReject(static function()use($binding){qaPoll($binding,1,null,true);},'activity_join_identity_mismatch');qaCheck($repo->bindings[1]===$saved,'identity_rollback');
});
qaCase('provider_owner_cannot_move_accounts',static function(){
    list($repo,$api,$binding)=qaApproved();$repo->owner=2;qaStart($binding,2);
    qaReject(static function()use($binding){qaPoll($binding,2,null,true);},'activity_join_identity_mismatch');qaCheck(!isset($repo->bindings[2]),'no_second_owner');
});
qaCase('unlink_revokes_and_clears_credentials',static function(){
    list($repo,$api,$binding)=qaApproved();qaCheck($binding->unlink(1)==='disconnected'&&$api->logouts===1,'unlink_result');
    qaCheck($repo->bindings[1]['state_code']==='revoked'&&$repo->bindings[1]['access_ciphertext']===''&&$repo->bindings[1]['refresh_ciphertext']===''&&$repo->deactivated===1,'unlink_local_state');
});
qaCase('unlink_remote_failure_blocks_relink',static function(){
    list($repo,$api,$binding)=qaApproved();$api->logoutFails=true;
    qaReject(static function()use($binding){$binding->unlink(1);},'remote_revoke_pending');
    qaCheck($repo->bindings[1]['state_code']==='revoked'&&$repo->bindings[1]['refresh_ciphertext']!=='','pending_revoke_preserved');
    qaReject(static function()use($binding){qaStart($binding);},'remote_revoke_pending');
    $api->logoutFails=false;qaCheck($binding->unlink(1)==='disconnected'&&$binding->status(1)==='disconnected','revoke_recovery');
});
qaCase('session_read_and_revocation_race',static function(){
    list($repo,$api,$binding,$key)=qaApproved();$session=new PintogyaSession($repo,$api,$key);
    qaCheck($session->connections(1)===array('items'=>array()),'authorized_model_read');
    $api->onRead=static function()use($repo){$repo->bindings[1]['state_code']='revoked';};
    qaReject(static function()use($session){$session->connections(1);},'binding_inactive');
});
qaCase('refresh_keeps_identity_and_generation',static function(){
    list($repo,$api,$binding,$key)=qaApproved();$repo->bindings[1]['access_expires_at']=time()-1;
    $session=new PintogyaSession($repo,$api,$key);qaCheck($session->connections(1)===array('items'=>array()),'refresh_read');
    qaCheck($api->refreshes===1&&$repo->bindings[1]['generation']===1,'refresh_same_generation');
});
qaCase('refresh_identity_mismatch_rejected',static function(){
    list($repo,$api,$binding,$key)=qaApproved();$repo->bindings[1]['access_expires_at']=time()-1;$saved=$repo->bindings[1];$api->userId='synthetic-provider-b';
    $session=new PintogyaSession($repo,$api,$key);qaReject(static function()use($session){$session->connections(1);},'refresh_session_invalid');
    qaCheck($repo->bindings[1]===$saved,'refresh_mismatch_no_write');
});
qaCase('unauthorized_read_refresh_bounded',static function(){
    list($repo,$api,$binding,$key)=qaApproved();$api->readFailures=2;$session=new PintogyaSession($repo,$api,$key);
    qaReject(static function()use($session){$session->connections(1);},'api_unauthorized');
    qaCheck($api->refreshes===1&&$api->reads===2,'no_unbounded_refresh');
});

$passed=!in_array(false,array_column($cases,'passed'),true);
echo json_encode(array('scope'=>'synthetic binding/session component protocol only; no HTTP, database, native install or live account proof',
    'cases'=>$cases,'assertions'=>$checks,'passed'=>$passed)).PHP_EOL;
exit($passed?0:1);
