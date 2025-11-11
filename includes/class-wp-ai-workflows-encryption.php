<?php
class WP_AI_Workflows_Encryption {
    private static $encryption_key;

    public static function init() {
        self::$encryption_key = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'fallback-key';
    }

    public static function encrypt($data) {
        if (!extension_loaded('openssl')) {
            return base64_encode($data);
        }
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', self::$encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($data) {
        if (!extension_loaded('openssl')) {
            return base64_decode($data);
        }
        $data = base64_decode($data);
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        return openssl_decrypt($encrypted, 'aes-256-cbc', self::$encryption_key, 0, $iv);
    }
}
