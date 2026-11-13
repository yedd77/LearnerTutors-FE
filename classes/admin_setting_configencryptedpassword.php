<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Password setting that encrypts its value before Moodle stores it.
 * Existing values are never rendered to the administrator's browser.
 * An empty submission preserves the currently configured secret.
 */
class local_ragassistant_admin_setting_configencryptedpassword extends admin_setting_configpasswordunmask {
    public function output_html($data, $query = '') {
        return parent::output_html('', $query);
    }

    public function write_setting($data) {
        $value = trim((string) $data);
        if ($value === '') {
            return '';
        }

        // Moodle setting names use component/setting notation.
        $parts = explode('/', $this->name);
        $settingname = end($parts);
        $minimumlength = $settingname === 'api_key' ? 32 : 20;
        if (!in_array($settingname, ['api_key', 'openai_api_key', 'gemini_api_key'], true)
                || strlen($value) < $minimumlength) {
            return get_string(
                $settingname === 'api_key' ? 'apikeytoolong' : 'providerkeytoolong',
                'local_ragassistant'
            );
        }

        try {
            $encrypted = \local_ragassistant\local\secret_store::encrypt($value, $settingname);
            set_config($settingname, $encrypted, 'local_ragassistant');
        } catch (\Throwable $exception) {
            debugging('Could not securely store an API credential: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return get_string('secretstoragefailed', 'local_ragassistant');
        }
        return '';
    }
}
