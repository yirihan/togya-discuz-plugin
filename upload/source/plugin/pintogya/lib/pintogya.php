<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once dirname(__FILE__).'/crypto.php';
require_once __DIR__.'/installation.php';
require_once dirname(__FILE__).'/api_client.php';
require_once dirname(__FILE__).'/repository.php';
require_once dirname(__FILE__).'/binding.php';
require_once dirname(__FILE__).'/session.php';
require_once dirname(__FILE__).'/activity_read.php';
require_once dirname(__FILE__).'/activity_public_read.php';
require_once __DIR__.'/checkin_interactions.php';
require_once dirname(__FILE__).'/activity_read_access.php';
require_once __DIR__.'/display_member.php';
require_once __DIR__.'/display_view.php';
require_once dirname(__FILE__).'/activity_settings.php';
require_once dirname(__FILE__).'/activity_instant_config.php';
require_once dirname(__FILE__).'/activity_publish.php';
require_once dirname(__FILE__).'/activity_thread.php';
require_once dirname(__FILE__).'/activity_join.php';
require_once dirname(__FILE__).'/activity_membership_audit.php';
require_once dirname(__FILE__).'/activity_membership_repair.php';
require_once dirname(__FILE__).'/activity_review_evidence.php';
require_once dirname(__FILE__).'/activity_review_submit.php';
require_once dirname(__FILE__).'/activity_review_access.php';
require_once dirname(__FILE__).'/activity_review_moderate.php';
require_once dirname(__FILE__).'/activity_review_status.php';
require_once dirname(__FILE__).'/activity_review_detail.php';
require_once dirname(__FILE__).'/activity_photo_removal.php';
require_once dirname(__FILE__).'/task_context.php';
require_once dirname(__FILE__).'/task_facts.php';
require_once dirname(__FILE__).'/qr.php';

function pintogya_config() {
    global $_G;
    $raw = isset($_G['cache']['plugin']['pintogya']) && is_array($_G['cache']['plugin']['pintogya']) ? $_G['cache']['plugin']['pintogya'] : array();
    return array(
        'enabled' => isset($raw['enabled']) && (is_string($raw['enabled']) || is_int($raw['enabled'])) && (string)$raw['enabled'] === '1',
        'api_base_url' => 'https://api.togya.com',
    );
}

function pintogya_services($apiSender = null, $repository = null, $tokenKey = null, $apiDeadline = null) {
    global $_G;
    $config = pintogya_config();
    if(!$config['enabled']) { throw new RuntimeException('feature_disabled'); }
    $repo = $repository ?: new PintogyaRepository();
    $api = new PintogyaApiClient($config['api_base_url'], $apiSender, $apiDeadline);
    $binding = new PintogyaBinding($repo, $api, $tokenKey);
    $session = new PintogyaSession($repo, $api, $tokenKey);
    $contexts = new PintogyaTaskContext($repo);
    $activityRead = new PintogyaActivityRead($session, $api);
    $extcredits = $_G['setting']['extcredits'] ?? array();
    if(!is_array($extcredits)) { $extcredits = array(); }
    $rewardDefaults = null;
    $rewardJson = $_G['setting'][PintogyaActivityInstantRule::SETTING_KEY] ?? '';
    try { $rewardDefaults = PintogyaActivityInstantConfig::defaultsFromJson($rewardJson, $extcredits); }
    catch(InvalidArgumentException $error) { $rewardDefaults = null; }
    $activityPublish = new PintogyaActivityPublish($repo, $session, $activityRead, pintogya_site_host(),
        (int)($_G['setting']['pintogya_activity_forum_fid'] ?? 0),
        (string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1', $rewardDefaults, $extcredits, true);
    $activityThread = new PintogyaActivityThread($activityPublish, $repo, $activityRead);
    $activityJoin = new PintogyaActivityJoin($repo, $binding, $session, $activityRead, $activityThread,
        'pintogya_qr_data_uri', pintogya_site_host());
    $membershipAudit = new PintogyaActivityMembershipAudit($repo, $session, $activityRead, $activityThread);
    $reviewEvidence = new PintogyaActivityReviewEvidence($session, $tokenKey);
    $activityReview = new PintogyaActivityReviewSubmit($repo, $activityThread, $reviewEvidence);
    $activityModeration = new PintogyaActivityReviewModerate($repo, $activityThread,
        $reviewEvidence, new PintogyaActivityReviewAccess($repo));
    return array(
        'repository' => $repo,
        'api' => $api,
        'binding' => $binding,
        'session' => $session,
        'activity_read' => $activityRead,
        'activity_public_read' => new PintogyaActivityPublicRead($api),
        'activity_publish' => $activityPublish,
        'activity_thread' => $activityThread,
        'activity_join' => $activityJoin,
        'activity_membership_audit' => $membershipAudit,
        'activity_membership_repair' => new PintogyaActivityMembershipRepair($membershipAudit, $repo, $activityJoin),
        'activity_review' => $activityReview,
        'activity_review_evidence' => $reviewEvidence,
        'activity_moderation' => $activityModeration,
        'activity_review_status' => new PintogyaActivityReviewStatus($repo, $activityThread),
        'activity_review_detail' => new PintogyaActivityReviewDetail($repo, $activityThread,
            new PintogyaActivityReviewAccess($repo)),
        'activity_photo_removal_store' => new PintogyaActivityPhotoRemovalStore(),
        'activity_photo_removal' => new PintogyaActivityPhotoRemoval($repo, $activityThread,
            new PintogyaActivityReviewAccess($repo), new PintogyaActivityPhotoRemovalStore()),
        'contexts' => $contexts,
        'facts' => new PintogyaTaskFacts($binding, $session, $contexts),
    );
}

function pintogya_require_member() {
    global $_G;
    $uid = isset($_G['uid']) ? intval($_G['uid']) : 0;
    if($uid < 1) { showmessage('not_loggedin', '', array(), array('login' => 1)); }
    return $uid;
}

function pintogya_display_services($live, $cache = null) {
    if($cache !== null && !defined('PINTOGYA_STATIC_TEST')) { throw new RuntimeException('display_cache_injection_forbidden'); }
    $cache=$cache ?? new PintogyaDisplayCache();
    $api=new PintogyaPublicDisplayApi($cache);
    $session=new PintogyaMemberDisplaySession($live['session'],$cache);
    $reader=new PintogyaActivityRead($session,$api,true);
    $thread=new PintogyaActivityThread($live['activity_publish'],$live['repository'],$reader);
    // Only the returned read services change. The publisher/join/review/reward objects retain their live readers.
    return array_replace($live,array('activity_read'=>$reader,'activity_thread'=>$thread,
        'activity_public_read'=>new PintogyaActivityPublicRead($api),'display_cache'=>$cache,
        'display_view'=>new PintogyaDisplayView($live['repository'],$thread,$session,$cache)));
}

function pintogya_display_headers() {
    if(headers_sent() || !method_exists('PintogyaApiClient','sourceReads')) { return; }
    $counts=PintogyaApiClient::sourceReads(); $parts=array();
    foreach($counts as $kind=>$count) { $parts[]=$kind.'='.$count; }
    header('X-Togya-Plugin-Source-Reads: '.array_sum($counts));
    header('X-Togya-Plugin-Source-Profile: '.implode(',',$parts));
}

function pintogya_checkin_interactions($display) {
    return new PintogyaCheckinInteractions($display['activity_thread'],$display['activity_public_read'],new PintogyaCheckinPostStore(),$display['activity_read']);
}

function pintogya_session_digest() {
    global $_G;
    $sid = isset($_G['sid']) ? (string)$_G['sid'] : '';
    $auth = isset($_G['cookie']['auth']) ? (string)$_G['cookie']['auth'] : '';
    if($sid === '' || $auth === '') { throw new RuntimeException('session_unavailable'); }
    return hash('sha256', "pintogya/session/v2\0".$sid."\0".$auth);
}

function pintogya_site_host() { return pintogya_configured_site_host(); }

function pintogya_assert_post() {
    if(strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '') !== 'POST') { throw new RuntimeException('method_not_allowed'); }
    if(!defined('PINTOGYA_STATIC_TEST')) {
        $host = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : ''));
        if($host !== pintogya_site_host()) { throw new RuntimeException('wrong_host'); }
        $origin = strtolower(rtrim(isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '', '/'));
        if($origin !== 'https://'.pintogya_site_host()) { throw new RuntimeException('wrong_origin'); }
    }
    if(!isset($_POST['formhash']) || !function_exists('formhash') || !hash_equals((string)formhash(), (string)$_POST['formhash'])) { throw new RuntimeException('formhash_invalid'); }
}

function pintogya_task_instance($value) {
    $value = (string)$value;
    if(!preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value)) { throw new InvalidArgumentException('task_instance_invalid'); }
    return $value;
}

function pintogya_api_locale($locale = '') {
    global $_G;
    if($locale === '') { $locale = isset($_G['member']['language']) ? (string)$_G['member']['language'] : 'en-US'; }
    if(in_array($locale, array('zh-TW','zh-Hant','tc'), true)) { return 'zh-Hant'; }
    if(strpos($locale, 'es') === 0) { return 'es'; }
    return 'en-US';
}

function pintogya_lang_pack($locale = '') {
    global $_G;
    if($locale === '') { $locale = isset($_G['member']['language']) ? (string)$_G['member']['language'] : 'en-US'; }
    if(in_array($locale, array('zh-TW','zh-Hant','TC_UTF8','tc'), true)) { $locale = 'zh-TW'; }
    elseif(in_array($locale, array('zh-CN','SC_UTF8','sc'), true)) { $locale = 'zh-CN'; }
    else { $locale = 'en-US'; }
    // TOGYA_PLUGIN pintogya_language_english_fallback: preserve translations; fill only absent keys.
    $english = include dirname(__FILE__).'/../lang/en-US.php';
    $pack = $locale === 'en-US' ? $english : array_replace($english,
        include dirname(__FILE__).'/../lang/'.$locale.'.php');
    // TOGYA_PLUGIN pintogya_unified_site_labels: shared packs retain local display names.
    $siteName = pintogya_site_profile()['request_name'];
    array_walk_recursive($pack, function(&$text) use ($siteName) {
        if(is_string($text)) { $text = str_replace('{forum_name}', $siteName, $text); }
    });
    return $pack;
}

function pintogya_task_lang_pack() {
    return pintogya_lang_pack('en-US');
}

function pintogya_public_links() {
    return array(
        'open' => 'https://togya.com/open-togya',
        'apple' => 'https://apps.apple.com/app/id6796389901',
        'android' => 'https://play.google.com/store/apps/details?id=com.togya.app',
    );
}

function pintogya_apply_install_migrations() {
    if(!defined('PINTOGYA_INSTALL_CONTEXT')) { define('PINTOGYA_INSTALL_CONTEXT', true); }
    foreach(pintogya_verified_install_migrations() as $path) { include $path; }
}

function pintogya_verified_install_migrations() {
    // New installs need no legacy pool/review/rating tables. Existing historical tables are untouched.
    $migrations = array(
        '0001_period_binding.php' => '775effe15a86bbba8c34925b200383ed168594df359f1aeaf6d5c3c2ebe1fc0c',
        '0002_task_context.php' => '8745c40c7b64bc6ae4c9b6100110b7b8e42a065db27045c082232ba6a85d702c',
        '0003_activity_mapping.php' => '2fd7b7346606acabb0782909adc55e3f8cab3c782b46b46e5fb5c803f0d18433',
        '0004_activity_participation.php' => '532ada54492d09e47a53890006da767dec6ef1c64888574588eca66236723104',
        '0010_instant_reward.php' => '55970e36435c2b6042130709a3832d2487a1aab58a013a2ae8ce3b4481fe4c4e',
        '0011_bound_participation_discovery.php' => '1af86c40a1f8849a03049ed4475b458d0c5e63be348968978f56139bc71a0f92',
        '0012_checkin_native_interactions.php' => 'c21b62432f78280631082bd2e635ad56c46fd1f795b3276f2f9122903c36a14e',
        '0013_native_thread_marker.php' => '79e869895900734138f49cc8605adb38099192eec2069d83f8a8088a14f1e09f',
    );
    $paths = array();
    foreach($migrations as $file => $digest) {
        $path = dirname(__FILE__).'/../migrations/'.$file;
        if(is_link($path) || !is_file($path) || !hash_equals($digest, hash_file('sha256', $path))) { throw new RuntimeException('migration_file_mismatch:'.$file); }
        $paths[] = $path;
    }
    return $paths;
}
