<?php
define('IN_DISCUZ', true);
define('PINTOGYA_STATIC_TEST', true);
define('PINTOGYA_INSTALL_CONTEXT', true);
$root = getenv('TOGYA_PLUGIN_SOURCE') ?: dirname(__DIR__).'/upload/source/plugin/pintogya';
require $root.'/lib/pintogya.php';
require $root.'/lib/maintenance.php';

final class DB {
    public static $database = 'forum_alpha';
    public static $prefix = 'dx_';
    public static $identity = null;
    public static $rows = 0;
    public static $writes = 0;
    public static $engine = 'InnoDB';
    public static function table($name) { return self::$prefix.$name; }
    public static function result_first($sql,$args=array()) {
        if($sql === 'SELECT DATABASE()') { return self::$database; }
        if(strpos($sql,'SELECT ENGINE FROM')===0) { return self::$engine; }
        if(strpos($sql,'GET_LOCK(')!==false || strpos($sql,'RELEASE_LOCK(')!==false) { return 1; }
        if(strpos($sql,'SELECT available')===0) { return 0; }
        if(strpos($sql,'information_schema.TABLES')!==false) { return 1; }
        if(strpos($sql,"WHERE state_code='active'")!==false || strpos($sql,"WHERE state_code='pending'")!==false) { return 0; }
        if(strpos($sql,'SELECT 1 FROM `')===0) { return self::$rows > 0 ? 1 : 0; }
        throw new RuntimeException('unexpected_synthetic_query');
    }
    public static function fetch_first($sql,$args=array()) {
        if(strpos($sql,'SELECT svalue')===0) { return self::$identity===null ? null : array('svalue'=>self::$identity); }
        throw new RuntimeException('unexpected_synthetic_fetch');
    }
    public static function fetch_all($sql,$args=array()) {
        if(strpos($sql,'information_schema.TABLES')!==false) { return array(array('TABLE_NAME'=>self::table('pintogya_binding'))); }
        throw new RuntimeException('unexpected_synthetic_fetch_all');
    }
    public static function insert($table,$row) {
        if($table!=='common_setting' || self::$identity!==null) { throw new RuntimeException('unexpected_synthetic_write'); }
        self::$writes++; self::$identity=$row['svalue']; return 1;
    }
    public static function query($sql) { throw new RuntimeException('unexpected_mutation'); }
}
function updatecache($name) {
    if($name!=='setting') { throw new RuntimeException('unexpected_cache_update'); }
}
function configure($database,$prefix='dx_') {
    global $_G;
    DB::$database=$database;DB::$prefix=$prefix;DB::$identity=null;DB::$rows=0;DB::$writes=0;
    $_G=array('config'=>array('db'=>array(1=>array('dbname'=>$database))),
        'setting'=>array('siteurl'=>'https://forum.example.org/'));
}
$checks=0;
function check($value,$label) { global $checks; if(!$value)throw new RuntimeException('check_failed:'.$label);$checks++; }
function rejects($callable,$label,$expectedSymbol=null) {
    try { $callable(); } catch(Throwable $error) {
        check($expectedSymbol===null||($error instanceof RuntimeException&&$error->getMessage()===$expectedSymbol),$label);return;
    }
    throw new RuntimeException('expected_rejection:'.$label);
}

configure('forum_alpha');
$alpha=PintogyaInstallation::initialize();
check(PintogyaInstallation::validScope($alpha),'fresh_scope');
check(DB::$writes===1,'single_identity_write');
$record=DB::$identity;DB::$rows=3;
check(PintogyaInstallation::initialize()===$alpha && DB::$writes===1,'idempotent_install_identity');
$_G['setting']['siteurl']='https://community.example.net/';
check(PintogyaInstallation::scope()===$alpha,'hostname_change_keeps_entitlements');
check(PintogyaSiteProfile::hostFromSettings($_G['setting'])==='community.example.net','configured_generic_host');
$activity='10000000-0000-4000-8000-000000000001';$member='20000000-0000-4000-8000-000000000001';
$alphaKey=PintogyaActivityInstantRule::rewardKey($alpha,$activity,$member,'base');
check(strlen($alphaKey)===64,'reward_key_format');
check($alphaKey===PintogyaActivityInstantRule::rewardKey(PintogyaInstallation::scope(),$activity,$member,'base'),'hostname_change_keeps_key');
DB::$database='unrelated_database';rejects(static function(){ PintogyaInstallation::scope(); },'connected_database_mismatch');
DB::$database='forum_alpha';DB::$prefix='other_';rejects(static function(){ PintogyaInstallation::scope(); },'table_prefix_mismatch');DB::$prefix='dx_';
rejects(static function(){ PintogyaInstallation::assertScope('togya-forum:30000000-0000-4000-8000-000000000001'); },'cross_installation_scope');
rejects(static function(){ PintogyaInstallation::fromSettings(array(PintogyaInstallation::SETTING_KEY=>'{}'),PintogyaInstallation::databaseBinding()); },'malformed_identity');
DB::$identity=null;rejects(static function(){ PintogyaInstallation::assertInstallable(); },'unknown_existing_rows');
check(DB::$writes===1,'unknown_install_did_not_write');DB::$identity=$record;
DB::$engine='MyISAM';rejects(static function(){ PintogyaInstallation::assertInstallable(); },'nontransactional_settings_rejected');DB::$engine='InnoDB';
configure('forum_beta','bbs_');$beta=PintogyaInstallation::initialize();
check($beta!==$alpha,'independent_installation');
check(PintogyaActivityInstantRule::rewardKey($beta,$activity,$member,'base')!==$alphaKey,'independent_reward_namespace');
DB::$identity=$record;$_G['setting'][PintogyaInstallation::SETTING_KEY]=$record;
rejects(static function(){ PintogyaInstallation::scope(); },'copied_identity_wrong_database');
configure('forum_beta','bbs_');PintogyaInstallation::initialize();
$_G['config']['db']['map']=array('common_setting'=>'external');rejects(static function(){ PintogyaInstallation::scope(); },'mapped_database_rejected');unset($_G['config']['db']['map']);
foreach(array('https://user:pass@forum.example.org/','http://forum.example.org/','https://forum.example.org/app/') as $origin) {
    rejects(static function() use($origin){ PintogyaSiteProfile::hostFromSettings(array('siteurl'=>$origin)); },'unsafe_origin');
}
rejects(static function(){ PintogyaActivityInstantSettlement::grant(null,null,null); },'unverified_reward_backend_closed','extension_not_verified');
rejects(static function(){ (new PintogyaCheckinInteractions(null,null,null))->prepare(0,0,'','','','','comment',0); },'unverified_interaction_backend_closed','extension_not_verified');
check(PintogyaCheckinInteractions::capabilities(1,2)===array('comment'=>false,'rate'=>false),'unverified_buttons_closed');
require_once $root.'/lib/native_task.php';
rejects(static function(){ pintogya_native_services(); },'unverified_tasks_closed','extension_not_verified');
check(PintogyaMaintenance::execute('pintogya.instant_rewards',5)===array('skipped'=>'extension_not_verified'),'unverified_reward_worker_closed');
check(PintogyaMaintenance::execute('pintogya.display_cache',5)===array('skipped'=>'plugin_disabled'),'disabled_worker_no_source');
check(count(pintogya_verified_install_migrations())===8,'migration_hash_integrity');
$digest=hash('sha256','synthetic-native-marker');$message=PintogyaActivityNativePost::messageForDigest($digest);
check(substr($message,strpos($message,"\0\0\0")+3)==='pintogya','native_plugin_identifier_exact');
check(PintogyaActivityNativePost::digestFromMessage($message)===$digest,'native_marker_roundtrip');
check(PintogyaActivityNativePost::digestFromMessage(PintogyaActivityNativePost::MARKER_PREFIX.$digest)===$digest,'legacy_marker_recognition');
check(PintogyaActivityNativePost::digestFromMessage($message.'extra')===null,'marker_closed_suffix');
$_G['forum_firstpid']=9;
$postlist=array(9=>array('tid'=>12,'first'=>1,'message'=>PintogyaActivityNativePost::NATIVE_PREFIX.$digest));
check(PintogyaActivityNativePost::hideRenderedMarker(12)&&$postlist[9]['message']==='','rendered_marker_hidden_in_memory');
$postlist[9]['message']=str_replace("\n","<br />\n",PintogyaActivityNativePost::NATIVE_PREFIX.$digest);
check(PintogyaActivityNativePost::hideRenderedMarker(12)&&$postlist[9]['message']==='','native_html_break_marker_hidden');
$postlist[9]['message']=str_replace("\n",'<br class="edited">',PintogyaActivityNativePost::NATIVE_PREFIX.$digest);
check(!PintogyaActivityNativePost::hideRenderedMarker(12)&&str_contains($postlist[9]['message'],'edited'),'modified_html_body_not_hidden');
$postlist[9]['message']=PintogyaActivityNativePost::NATIVE_PREFIX.$digest.' edited';
check(!PintogyaActivityNativePost::hideRenderedMarker(12)&&str_ends_with($postlist[9]['message'],' edited'),'edited_body_not_hidden');
$postlist[9]['message']=PintogyaActivityNativePost::NATIVE_PREFIX.$digest;
check(!PintogyaActivityNativePost::hideRenderedMarker(13)&&$postlist[9]['message']!=='','other_topic_body_not_hidden');
$postlist[9]['first']=0;
check(!PintogyaActivityNativePost::hideRenderedMarker(12)&&$postlist[9]['message']!=='','reply_body_not_hidden');
unset($postlist,$_G['forum_firstpid']);
require_once $root.'/thread.class.php';
check(PintogyaCapabilities::available('native_composer')===true,'native_composer_adapter_available');
rejects(static function(){(new threadplugin_pintogya())->newthread_submit(1);},'composer_requires_native_controller','activity_native_context_required');
rejects(static function(){(new threadplugin_pintogya())->newthread_submit_end(1,1);},'completion_requires_native_controller','activity_native_context_required');
PintogyaCapabilities::assertPublicationRule(array('total'=>0));check(true,'zero_reward_publication_allowed');
rejects(static function(){PintogyaCapabilities::assertPublicationRule(array('total'=>1));},'paid_publication_stays_closed','extension_not_verified');
rejects(static function(){PintogyaCapabilities::assertPublicationRule(null);},'legacy_paid_publication_stays_closed','extension_not_verified');
require_once $root.'/lib/activity_api.php';
$apiBinding=new class {
    public function latestChallenge(...$args){return array('state'=>'approved','approvedAt'=>10);}
    public function pollLatest(...$args){return true;}
};
$apiReader=new class {public function ownedPublicActivities(...$args){return array(array('id'=>'10000000-0000-4000-8000-000000000001'));}};
$apiPublisher=new class {public function issue(...$args){return array('token'=>str_repeat('a',43),'expiresAt'=>20,'activity'=>array('id'=>'10000000-0000-4000-8000-000000000001'));}};
$composerApi=new PintogyaActivityApi($apiBinding,$apiReader,$apiPublisher,static function(){return '';});
foreach(array('start','poll','list','select')as$operation) {
    $response=$composerApi->execute($operation,1,1,hash('sha256','synthetic-session'),'en-US',10,'10000000-0000-4000-8000-000000000001');
    check(is_array($response)&&($operation==='list'?count($response['activities'])===1:($operation==='select'?strlen($response['token'])===43:$response['state']==='approved')),'composer_api_'.$operation);
}
$xml=simplexml_load_file($root.'/discuz_plugin_pintogya.xml');
$data=$xml->xpath('item[@id="Data"]/item[@id="plugin"]')[0];
$metadata=array();foreach($data->item as $item)$metadata[(string)$item['id']]=(string)$item;
check($metadata['name']==='Togya Plugin','public_name');
check($metadata['version']===PintogyaInstallation::VERSION,'version_consistency');
$modules=unserialize($metadata['modules'],array('allowed_classes'=>false));
check(is_array($modules)&&$modules[0]['menu']==='Togya Plugin','serialized_menu_length');
check($metadata['available']==='0','default_disabled');
$writes=DB::$writes;$finish=false;include $root.'/uninstall.php';
check($finish===true && DB::$writes===$writes,'uninstall_preserves_data');
echo json_encode(array('scope'=>'synthetic unit checks; not a real X5/database installation','checks'=>$checks,'passed'=>true)).PHP_EOL;
