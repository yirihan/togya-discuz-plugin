<?php
define('PINTOGYA_STATIC_TEST',true);
$root=getenv('TOGYA_PLUGIN_SOURCE');
if(!$root||!is_file($root.'/lib/pintogya.php'))exit(2);
require $root.'/lib/pintogya.php';
require $root.'/lib/activity_official_publish.php';
$constructors=array(
    static function($host){return new PintogyaActivityPublish(null,null,null,$host,0,false);},
    static function($host){return new PintogyaActivityJoin(null,null,null,null,null,static function(){return '';},$host);},
    static function($host){return new PintogyaActivityOfficialPublish(null,null,null,$host,1,false,array());}
);
$checks=0;
foreach(array('www.example.org','forum.example.org','community.example.net')as$host) {
    if(PintogyaSiteProfile::hostFromSettings(array('siteurl'=>'https://'.$host.'/'))!==$host)throw new RuntimeException('canonical_host_failed');$checks++;
    foreach($constructors as$make){$make($host);$checks++;}
}
foreach(array('http://forum.example.org','example.org:443','user@example.org','UPPER.example.org','foo..example.org','bad example.org',"forum.example.org\n",'')as$host) {
    if(PintogyaSiteProfile::validHost($host))throw new RuntimeException('unsafe_host_accepted');$checks++;
    foreach($constructors as$make) {
        $rejected=false;try{$make($host);}catch(InvalidArgumentException $error){$rejected=true;}
        if(!$rejected)throw new RuntimeException('constructor_accepted_unsafe_host');$checks++;
    }
}
echo json_encode(array('scope'=>'Canonical origin and constructor checks only; no HTTP, identity transfer, provider or permission proof',
    'checks'=>$checks,'passed'=>true)).PHP_EOL;
