<?php
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
if(function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR,"Run as the forum web-service account, not root.\n"); exit(2);
}

// DX clears non-superglobal variables during bootstrap; keep CLI state local.
function togya_plugin_cli_main($arguments) {
$args = array(); $command = 'status';
foreach(array_slice($arguments,1) as $argument) {
    if(strncmp($argument,'--',2) === 0) {
        $pair = explode('=',substr($argument,2),2);
        if(count($pair) !== 2 || isset($args[$pair[0]])) { fwrite(STDERR,"Invalid CLI arguments\n"); exit(2); }
        $args[$pair[0]] = $pair[1];
    } elseif(in_array($argument,array('status','run','worker'),true)) { $command = $argument; }
    else { fwrite(STDERR,"Invalid CLI command\n"); exit(2); }
}
if(array_diff(array_keys($args),array('site','host','task','limit')) ||
    !isset($args['host']) || preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/D',$args['host']) !== 1 ||
    (isset($args['limit']) && preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$args['limit']) !== 1)) {
    fwrite(STDERR,"A canonical --host and valid options are required.\n"); exit(2);
}
$root = realpath($args['site'] ?? dirname(__DIR__,3));
if(!$root || !is_file($root.'/source/class/class_core.php') ||
    realpath($root.'/source/plugin/pintogya') !== realpath(__DIR__)) {
    fwrite(STDERR,"Invalid Discuz installation\n"); exit(2);
}
define('TOGYA_PLUGIN_MAINTENANCE_CLI_ARGS_JSON',json_encode($args));
define('TOGYA_PLUGIN_WORKER_CHILD',$command === 'worker');
chdir($root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = $args['host'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/forum.php';
$_SERVER['PHP_SELF'] = '/forum.php';
$buffer = ob_get_level(); ob_start();
try {
    require $root.'/source/class/class_core.php';
    $app = C::app();
    $app->init_cron = false; $app->init_session = false; $app->init_user = false;
    $app->init();
    global $_G;
    require_once __DIR__.'/lib/site_profile.php';
    if(PintogyaSiteProfile::hostFromSettings($_G['setting'] ?? array()) !== $args['host']) {
        throw new RuntimeException('maintenance_origin_mismatch');
    }
    require_once __DIR__.'/lib/maintenance.php';
    if($command === 'worker') {
        $result = PintogyaMaintenanceRunner::publicResult(PintogyaMaintenance::execute($args['task'] ?? '',(int)($args['limit'] ?? 5)));
    } elseif($command === 'run') {
        $result = PintogyaMaintenance::runDue((int)($args['limit'] ?? 5));
    } else {
        $result = array('tasks'=>PintogyaMaintenance::status());
    }
    while(ob_get_level()>$buffer) { ob_end_clean(); }
    echo json_encode(array('ret'=>0,'result'=>$result),JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) {
    while(ob_get_level()>$buffer) { ob_end_clean(); }
    // Never expose provider bodies, configuration or exception stack traces.
    echo json_encode(array('ret'=>1,'result'=>array('state'=>'outcome_unknown','errors'=>array(array('code'=>'maintenance_failed'))))).PHP_EOL;
    exit(1);
}
}
togya_plugin_cli_main($argv);
