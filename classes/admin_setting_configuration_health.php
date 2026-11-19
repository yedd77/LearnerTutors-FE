<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Displays configuration health checks inside the existing settings page.
 */
class local_ragassistant_admin_setting_configuration_health extends admin_setting {

    public function __construct() {
        parent::__construct(
            'local_ragassistant/configuration_health',
            get_string('configurationhealth', 'local_ragassistant'),
            get_string('configurationhealth_desc', 'local_ragassistant'),
            ''
        );
    }

    public function get_setting() {
        return null;
    }

    public function write_setting($data) {
        // This setting is only a UI component. It stores nothing.
        return '';
    }

    public function output_html($data, $query = '') {
        global $OUTPUT;

        // Each test is one row. The JavaScript module fills in the result and message.
        $tests = [];
        foreach (['server', 'auth', 'llm', 'fallback', 'image'] as $key) {
            $tests[] = [
                'key' => $key,
                'label' => get_string('healthcheck_test_' . $key, 'local_ragassistant'),
                'description' => get_string('healthcheck_test_' . $key . '_desc', 'local_ragassistant'),
            ];
        }

        $html = $OUTPUT->render_from_template('local_ragassistant/configuration_health', [
            'buttonlabel' => get_string('runconfigurationtests', 'local_ragassistant'),
            'idlelabel' => get_string('healthcheck_status_idle', 'local_ragassistant'),
            'tests' => $tests,
        ]);

        $this->register();
        return format_admin_setting(
            $this,
            $this->visiblename,
            $html,
            $this->description,
            false,
            '',
            null,
            $query
        );
    }

    private function register() {
        global $PAGE;

        $PAGE->requires->js_call_amd(
            'local_ragassistant/configuration_health',
            'init',
            [[
                'endpoint' => (new moodle_url(
                    '/local/ragassistant/ajax/test_configuration.php'
                ))->out(false),
                'strings' => [
                    'running' => get_string('healthcheck_running', 'local_ragassistant'),
                    'idle' => get_string('healthcheck_status_idle', 'local_ragassistant'),
                    'testing' => get_string('healthcheck_status_testing', 'local_ragassistant'),
                    'pass' => get_string('healthcheck_status_pass', 'local_ragassistant'),
                    'fail' => get_string('healthcheck_status_fail', 'local_ragassistant'),
                    'skipped' => get_string('healthcheck_status_skipped', 'local_ragassistant'),
                ],
            ]]
        );
    }
}
