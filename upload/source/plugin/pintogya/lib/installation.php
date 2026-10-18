<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/crypto.php';

final class PintogyaInstallation {
    const SETTING_KEY = 'pintogya_installation_identity';
    const VERSION = '1.0.0-alpha.3-x5';

    public static function validScope($scope) {
        return is_string($scope) && preg_match('/\Atogya-forum:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $scope) === 1;
    }

    public static function databaseBinding() {
        global $_G;
        $config = $_G['config']['db'] ?? null;
        $expected = is_array($config) ? ($config[1]['dbname'] ?? null) : null;
        if(!is_string($expected) || $expected === '' || !empty($config['map'])) {
            throw new RuntimeException('installation_database_configuration_invalid');
        }
        $current = (string)DB::result_first('SELECT DATABASE()');
        if($current === '' || !hash_equals($expected, $current)) {
            throw new RuntimeException('installation_database_scope_mismatch');
        }
        $settingsTable = DB::table('common_setting');
        return hash('sha256', $current."\0".$settingsTable);
    }

    public static function fromSettings($settings, $databaseBinding) {
        $json = is_array($settings) ? ($settings[self::SETTING_KEY] ?? null) : null;
        if(!is_string($json) || strlen($json) > 256 || !is_string($databaseBinding) ||
            preg_match('/\A[0-9a-f]{64}\z/D', $databaseBinding) !== 1) {
            throw new RuntimeException('installation_identity_missing');
        }
        try { $record = json_decode($json, true, 4, JSON_THROW_ON_ERROR); }
        catch(Throwable $error) { throw new RuntimeException('installation_identity_invalid'); }
        if(!is_array($record) || array_keys($record) !== array('schema','id','database_binding') ||
            $record['schema'] !== 1 || !is_string($record['id']) ||
            !self::validScope('togya-forum:'.$record['id']) ||
            !is_string($record['database_binding']) || !hash_equals($databaseBinding, $record['database_binding'])) {
            throw new RuntimeException('installation_identity_invalid');
        }
        return 'togya-forum:'.$record['id'];
    }

    public static function scope() {
        global $_G;
        return self::fromSettings($_G['setting'] ?? array(), self::databaseBinding());
    }

    public static function assertScope($scope) {
        if(!self::validScope($scope) || !hash_equals(self::scope(), $scope)) {
            throw new RuntimeException('installation_scope_mismatch');
        }
    }

    public static function assertInstallable() {
        global $_G;
        $binding = self::databaseBinding();
        if(strtoupper((string)DB::result_first('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',array(DB::table('common_setting')))) !== 'INNODB') {
            throw new RuntimeException('installation_settings_not_transactional');
        }
        $record = DB::fetch_first('SELECT svalue FROM %t WHERE skey=%s', array('common_setting', self::SETTING_KEY));
        if($record) {
            self::fromSettings(array(self::SETTING_KEY => $record['svalue']), $binding);
            return;
        }
        $tables = DB::fetch_all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
        $prefix = DB::table('pintogya_');
        foreach($tables as $row) {
            $table = (string)$row['TABLE_NAME'];
            if(strncmp($table, $prefix, strlen($prefix)) !== 0) { continue; }
            if(preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('installation_table_invalid'); }
            if((int)DB::result_first('SELECT 1 FROM `'.$table.'` LIMIT 1') === 1) {
                throw new RuntimeException('installation_existing_data_requires_migration');
            }
        }
    }

    public static function initialize() {
        global $_G;
        if(!defined('PINTOGYA_INSTALL_CONTEXT') || PINTOGYA_INSTALL_CONTEXT !== true) {
            throw new RuntimeException('installation_context_required');
        }
        $binding = self::databaseBinding();
        $lock = 'togya_install_'.substr($binding, 0, 32);
        if((string)DB::result_first('SELECT GET_LOCK(%s,10)', array($lock)) !== '1') {
            throw new RuntimeException('installation_lock_unavailable');
        }
        try {
            $record = DB::fetch_first('SELECT svalue FROM %t WHERE skey=%s', array('common_setting', self::SETTING_KEY));
            if($record) {
                $scope = self::fromSettings(array(self::SETTING_KEY => $record['svalue']), $binding);
                $_G['setting'][self::SETTING_KEY] = $record['svalue'];
                return $scope;
            }
            self::assertInstallable();
            $json = json_encode(array('schema'=>1, 'id'=>PintogyaCrypto::uuid(), 'database_binding'=>$binding), JSON_THROW_ON_ERROR);
            if(DB::insert('common_setting', array('skey'=>self::SETTING_KEY, 'svalue'=>$json)) === false) {
                throw new RuntimeException('installation_identity_write_failed');
            }
            $saved = DB::fetch_first('SELECT svalue FROM %t WHERE skey=%s', array('common_setting', self::SETTING_KEY));
            if(!$saved || !hash_equals($json, (string)$saved['svalue'])) { throw new RuntimeException('installation_identity_readback_failed'); }
            $_G['setting'][self::SETTING_KEY] = $json;
            if(function_exists('updatecache')) { updatecache('setting'); }
            return self::fromSettings($_G['setting'], $binding);
        } finally {
            DB::result_first('SELECT RELEASE_LOCK(%s)', array($lock));
        }
    }
}
