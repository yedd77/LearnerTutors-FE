<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Encrypts Moodle-to-backend and provider credentials before storing them.
 * The 32-byte master key belongs in Moodle config.php or the PHP environment.
 */
final class secret_store {
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static function encryption_key(): string {
        global $CFG;
        $encoded = $CFG->local_ragassistant_encryption_key ?? getenv('LOCAL_RAGASSISTANT_ENCRYPTION_KEY');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new \moodle_exception('encryptionkeymissing', 'local_ragassistant');
        }
        return $key;
    }

    private static function aad(string $purpose): string {
        return 'local_ragassistant/' . $purpose . '/v1';
    }

    public static function encrypt(string $plaintext, string $purpose = 'api_key'): string {
        if ($plaintext === '') {
            throw new \coding_exception('Cannot encrypt an empty secret.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, self::encryption_key(),
            OPENSSL_RAW_DATA, $iv, $tag, self::aad($purpose), 16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \moodle_exception('secretencryptionfailed', 'local_ragassistant');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $stored, string $purpose = 'api_key'): string {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // Legacy plaintext migration.
        }
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new \moodle_exception('secretdecryptionfailed', 'local_ragassistant');
        }
        $plaintext = openssl_decrypt(
            substr($payload, 28), self::CIPHER, self::encryption_key(),
            OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16),
            self::aad($purpose)
        );
        if (!is_string($plaintext)) {
            throw new \moodle_exception('secretdecryptionfailed', 'local_ragassistant');
        }
        return $plaintext;
    }

    public static function get_api_key(): string {
        $stored = (string) get_config('local_ragassistant', 'api_key');
        if ($stored === '') {
            throw new \moodle_exception('apikeymissing', 'local_ragassistant');
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            set_config('api_key', self::encrypt($stored), 'local_ragassistant');
            return $stored;
        }
        return self::decrypt($stored);
    }

    /**
     * Read an optional Moodle-managed provider key. Never expose it to a page.
     */
    public static function get_provider_api_key(string $provider): string {
        if (!in_array($provider, ['openai', 'gemini'], true)) {
            throw new \invalid_parameter_exception('Unsupported provider.');
        }
        $name = $provider . '_api_key';
        $stored = (string) get_config('local_ragassistant', $name);
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            $encrypted = self::encrypt($stored, $name);
            set_config($name, $encrypted, 'local_ragassistant');
            return $stored;
        }
        return self::decrypt($stored, $name);
    }
}
