<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Password setting that encrypts its value before Moodle stores it.
 *
 * Existing values are deliberately never rendered into the settings page.
 * An empty submission preserves the currently configured secret.
 */
class local_ragassistant_admin_setting_configencryptedpassword extends admin_setting_configpasswordunmask {
    /**
     * Render a blank password input so the saved credential is not sent to
     * the administrator's browser. The description indicates configuration.
     */
    public function output_html($data, $query = '') {
        return parent::output_html('', $query);
    }

    /**
     * Encrypt new values before persisting them in config_plugins.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        $value = trim((string) $data);

        // An empty password field means "keep the existing key".
        if ($value === '') {
            return '';
        }

        if (strlen($value) < 32) {
            return get_string('apikeytoolong', 'local_ragassistant');
        }

        try {
            $encrypted = \local_ragassistant\local\secret_store::encrypt($value);
            set_config('api_key', $encrypted, 'local_ragassistant');
        } catch (\Throwable $exception) {
            debugging('Could not securely store the RAG API key: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return get_string('secretstoragefailed', 'local_ragassistant');
        }

        return '';
    }
}
