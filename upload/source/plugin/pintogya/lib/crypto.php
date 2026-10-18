<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

final class PintogyaCrypto {
    const TOKEN_KEY_ENV = 'PINTOGYA_TOKEN_ENCRYPTION_KEY';
    const TOKEN_KEY_FILE_ENV = 'PINTOGYA_TOKEN_ENCRYPTION_KEY_FILE';
    const TOKEN_KEY_FILE = '/etc/pintogya/token-encryption.key';

    public static function b64urlEncode($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64urlDecode($value, $length = null) {
        if(!is_string($value) || $value === '' || strpos($value, '=') !== false || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new InvalidArgumentException('invalid_base64url');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $bytes = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        if($bytes === false || self::b64urlEncode($bytes) !== $value || ($length !== null && strlen($bytes) !== intval($length))) {
            throw new InvalidArgumentException('invalid_base64url');
        }
        return $bytes;
    }

    public static function uuid() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public static function assertUuid($value) {
        if(!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', strtolower($value))) {
            throw new InvalidArgumentException('invalid_uuid');
        }
        return strtolower($value);
    }

    public static function canonicalJson($value) {
        return json_encode(self::sortValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function digest($namespace, $value) {
        return hash('sha256', (string)$namespace."\0".self::canonicalJson($value));
    }

    public static function tokenKeyFromEnvironment() {
        $value = getenv(self::TOKEN_KEY_ENV);
        if($value === false || $value === '') {
            // FPM keeps its existing environment; only CLI uses the private key file.
            if(PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) { throw new RuntimeException('token_key_unavailable'); }
            $path = getenv(self::TOKEN_KEY_FILE_ENV) ?: self::TOKEN_KEY_FILE;
            clearstatcache(true, $path);
            $before = @lstat($path);
            if(!is_array($before)) { throw new RuntimeException('token_key_unavailable'); }
            if(realpath($path) !== $path || ($before['mode'] & 0170000) !== 0100000 ||
                ($before['mode'] & 0777) !== 0600 || $before['uid'] !== posix_geteuid() ||
                $before['nlink'] !== 1 || $before['size'] !== 43) {
                throw new RuntimeException('token_key_file_invalid');
            }
            $handle = @fopen($path, 'rb');
            if($handle === false) { throw new RuntimeException('token_key_unavailable'); }
            try {
                $opened = fstat($handle);
                if(!is_array($opened) || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] ||
                    $opened['mode'] !== $before['mode'] || $opened['uid'] !== $before['uid'] ||
                    $opened['nlink'] !== 1 || $opened['size'] !== 43) {
                    throw new RuntimeException('token_key_file_invalid');
                }
                $value = fread($handle, 44);
                if(!is_string($value) || strlen($value) !== 43) { throw new RuntimeException('token_key_file_invalid'); }
            } finally { fclose($handle); }
        }
        try { return self::b64urlDecode($value, 32); } catch(Throwable $e) { throw new RuntimeException('token_key_invalid'); }
    }

    public static function seal($plaintext, $aad, $key = null) {
        if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) { throw new RuntimeException('sodium_unavailable'); }
        if(!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > 16384 || !is_string($aad) || $aad === '') { throw new InvalidArgumentException('seal_input'); }
        $key = $key === null ? self::tokenKeyFromEnvironment() : $key;
        if(!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) { throw new InvalidArgumentException('seal_key'); }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return self::b64urlEncode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key));
    }

    public static function open($sealed, $aad, $key = null) {
        if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) { throw new RuntimeException('sodium_unavailable'); }
        $packed = self::b64urlDecode($sealed);
        if(strlen($packed) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || !is_string($aad) || $aad === '') { throw new RuntimeException('sealed_value_invalid'); }
        $key = $key === null ? self::tokenKeyFromEnvironment() : $key;
        if(!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) { throw new RuntimeException('seal_key'); }
        $nonce = substr($packed, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($packed, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        if($plaintext === false || $plaintext === '') { throw new RuntimeException('sealed_value_invalid'); }
        return $plaintext;
    }

    public static function tokenAad($uid, $togyaUserId, $generation, $tokenType) {
        if(intval($uid) < 1 || !self::validOpaqueId($togyaUserId) || intval($generation) < 1 || !in_array($tokenType, array('access','refresh','verifier'), true)) {
            throw new InvalidArgumentException('token_aad');
        }
        return "pintogya/token/v1\0".intval($uid)."\0".$togyaUserId."\0".intval($generation)."\0".$tokenType;
    }

    public static function challengeAad($uid, $taskId, $taskInstance, $challengeId) {
        self::assertUuid($challengeId);
        if(intval($uid) < 1 || intval($taskId) < 0 || !is_string($taskInstance) || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $taskInstance)) {
            throw new InvalidArgumentException('challenge_aad');
        }
        return "pintogya/challenge-verifier/v1\0".intval($uid)."\0".intval($taskId)."\0".$taskInstance."\0".$challengeId;
    }

    public static function validOpaqueId($value, $max = 200) {
        return is_string($value) && $value !== '' && strlen($value) <= intval($max) && preg_match('/^[A-Za-z0-9._:-]+$/D', $value);
    }

    private static function sortValue($value) {
        if(!is_array($value)) { return $value; }
        if($value === array() || array_keys($value) === range(0, count($value) - 1)) {
            $out = array(); foreach($value as $item) { $out[] = self::sortValue($item); } return $out;
        }
        ksort($value, SORT_STRING);
        foreach($value as $key => $item) { $value[$key] = self::sortValue($item); }
        return $value;
    }
}
