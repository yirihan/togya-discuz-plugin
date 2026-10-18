<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaRefreshDeferred extends RuntimeException {
    public $retryAt;
    public function __construct($retryAt, $code = 'auth_refresh_budget_wait') {
        $this->retryAt = (int)$retryAt;
        parent::__construct($code);
    }
}

final class PintogyaRefreshBudget {
    const SETTING_KEY = 'pintogya_refresh_not_before';
    const WINDOW_SECONDS = 900;

    // TOGYA_PLUGIN: pintogya_refresh_budget -- one durable attempt/site/rolling 15 minutes, including failures.
    public static function reserve($testConfig = null) {
        if($testConfig !== null && !(defined('PINTOGYA_STATIC_TEST') && PINTOGYA_STATIC_TEST === true)) {
            throw new RuntimeException('auth_refresh_budget_injection_forbidden');
        }
        $db = null;
        try {
            $config = $testConfig ?? (C::app()->config['db'][1] ?? null);
            if(!is_array($config) || !preg_match('/^[A-Za-z0-9_]+$/D', (string)($config['tablepre'] ?? '')) ||
                !is_string($config['dbname'] ?? null) || $config['dbname'] === '') {
                throw new RuntimeException('auth_refresh_budget_config_invalid');
            }
            // An independent, nonpersistent connection commits before HTTP; a caller rollback cannot refund it.
            $db = new mysqli();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
            $db->options(MYSQLI_OPT_READ_TIMEOUT, 3);
            if(!@$db->real_connect($config['dbhost'], $config['dbuser'], $config['dbpw'],
                $config['dbname'], (int)($config['dbport'] ?? 3306))) {
                throw new RuntimeException('auth_refresh_budget_connection_invalid');
            }
            $table = $config['tablepre'].'common_setting';
            $engine = self::query($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."'")->fetch_row();
            if(!$engine || strtoupper((string)$engine[0]) !== 'INNODB') {
                throw new RuntimeException('auth_refresh_budget_engine_invalid');
            }
            self::query($db, 'SET SESSION innodb_lock_wait_timeout=1');
            self::query($db, 'START TRANSACTION');
            $key = self::SETTING_KEY;
            self::query($db, "INSERT IGNORE INTO `".$table."` (skey,svalue) VALUES ('".$key."','0')");
            $row = self::query($db, "SELECT svalue,UNIX_TIMESTAMP() FROM `".$table."` WHERE skey='".$key."' FOR UPDATE")->fetch_row();
            if(!$row || !preg_match('/^[0-9]{1,11}$/D', (string)$row[0]) || (int)$row[1] < 1) {
                throw new RuntimeException('auth_refresh_budget_state_invalid');
            }
            $now = (int)$row[1];
            if((int)$row[0] > $now) { throw new PintogyaRefreshDeferred((int)$row[0]); }
            self::query($db, "UPDATE `".$table."` SET svalue='".($now + self::WINDOW_SECONDS)."' WHERE skey='".$key."'");
            self::query($db, 'COMMIT');
        } catch(PintogyaRefreshDeferred $error) {
            throw $error;
        } catch(Throwable $error) {
            // Unknown DB/COMMIT outcome never permits HTTP or exposes connection details.
            throw new PintogyaRefreshDeferred(time() + self::WINDOW_SECONDS, 'auth_refresh_budget_unavailable');
        } finally {
            if($db !== null) { try { $db->close(); } catch(Throwable $ignored) {} }
        }
    }

    private static function query($db, $sql) {
        $result = $db->query($sql);
        if($result === false) { throw new RuntimeException('auth_refresh_budget_database_failed'); }
        return $result;
    }
}
