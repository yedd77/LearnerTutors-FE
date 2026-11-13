<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Encrypts the shared FastAPI credential before it is stored in Moodle config.
 *
 * The 32-byte master key belongs in Moodle config.php (outside the database),
 * or may be supplied through LOCAL_RAGASSISTANT_ENCRYPTION_KEY in the PHP environment.
 */
final class secret_store {
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'local_ragassistant/api_key/v1';

    /**
     * Return the decoded 32-byte encryption key.
     *
     * @throws \moodle_exception
     */
    private static function encryption_key(): string {
        global $CFG;
        $encoded = $CFG->local_ragassistant_encryption_key ?? getenv('LOCAL_RAGASSISTANT_ENCRYPTION_KEY');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (!is_string($key) || strlen($key) !== 32) {
            throw new \moodle_exception('encryptionkeymissing', 'local_ragassistant');
        }

        return $key;
    }

    /**
     * Encrypt a secret using AES-256-GCM with a fresh random nonce.
     */
    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            throw new \coding_exception('Cannot encrypt an empty API key.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \moodle_exception('secretencryptionfailed', 'local_ragassistant');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an encrypted API key. Legacy plaintext is returned for migration.
     */
    public static function decrypt(string $stored): string {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new \moodle_exception('secretdecryptionfailed', 'local_ragassistant');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD
        );

        if (!is_string($plaintext)) {
            throw new \moodle_exception('secretdecryptionfailed', 'local_ragassistant');
        }

        return $plaintext;
    }

    /**
     * Read the configured API key and transparently encrypt legacy plaintext.
     */
    public static function get_api_key(): string {
        $stored = (string) get_config('local_ragassistant', 'api_key');
        if ($stored === '') {
            throw new \moodle_exception('apikeymissing', 'local_ragassistant');
        }

        if (!str_starts_with($stored, self::PREFIX)) {
            // One-time migration of existing Moodle installations.
            set_config('api_key', self::encrypt($stored), 'local_ragassistant');
            return $stored;
        }

        return self::decrypt($stored);
    }
}
