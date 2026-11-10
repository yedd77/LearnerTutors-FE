<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Synchronize AI settings after the complete plugin settings form is saved.
 *
 * This setting is deliberately placed last in settings.php so all provider,
 * model, embedding and credential settings have already been persisted.
 */
class local_ragassistant_admin_setting_configcheckbox_sync extends admin_setting_configcheckbox {
    /**
     * Save the checkbox and synchronize the completed AI configuration.
     *
     * @param mixed $data
     * @return string Empty on success, otherwise the setting error.
     */
    public function write_setting($data) {
        $error = parent::write_setting($data);
        if ($error !== '') {
            return $error;
        }

        try {
            $api = new \\local_ragassistant\\local\\api_client();
            $api->sync_configuration();
        } catch (\\Throwable $exception) {
            // Keep saved settings even if the backend is temporarily offline.
            debugging(
                'RAG Assistant AI configuration sync will retry on the next chat or indexing request: ' .
                    $exception->getMessage(),
                DEBUG_DEVELOPER
            );
        }

        return '';
    }
}
