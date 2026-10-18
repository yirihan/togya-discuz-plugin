<?php
define('IN_DISCUZ',true);define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE')?:dirname(__DIR__).'/upload/source/plugin/pintogya';
require $source.'/lib/uninstall_guard.php';
final class DB {
    public static $rows=array();public static $drops=array();
    public static function table($logical) { return 'fixture_'.$logical; }
    public static function result_first($sql,$args) {
        if(str_contains($sql,'information_schema.TABLES'))return 1;
        if($sql==='SELECT COUNT(*) FROM %t')return self::$rows[$args[0]]??0;
        throw new RuntimeException('unexpected_test_query');
    }
    public static function query($sql) { self::$drops[]=$sql; }
}
$cases=array();
try {
    foreach(PintogyaUninstallGuard::TABLES as$table) {
        DB::$rows=array($table=>1);$refused=false;
        try { include $source.'/uninstall.php'; }
        catch(RuntimeException $error) {$refused=$error->getMessage()==='pintogya_uninstall_requires_verified_remote_revoke';}
        if(!$refused||DB::$drops)throw new RuntimeException('nonempty_table_not_preserved');
        $cases[]=array('table'=>$table,'refused'=>true,'drops'=>0);
    }
    DB::$rows=array();$finish=false;include $source.'/uninstall.php';
    $expected=array_map(static function($table){return 'DROP TABLE IF EXISTS `'.DB::table($table).'`';},PintogyaUninstallGuard::TABLES);
    if(!$finish||DB::$drops!==$expected)throw new RuntimeException('empty_cleanup_changed');
    echo json_encode(array('passed'=>true,'nonempty_cases'=>$cases,'empty_cleanup_tables'=>count($expected),
        'scope'=>'Synthetic SQL adapter only; verifies original all-record refusal and empty-table list. No database mutation, native uninstall retry or registration/message proof.')).PHP_EOL;
}catch(Throwable $error){echo json_encode(array('passed'=>false,'symbol'=>$error->getMessage())).PHP_EOL;exit(1);}
