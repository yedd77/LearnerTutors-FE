<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin setting that shows the configuration health checks on the settings page.
 *
 * @package    local_ragassistant
 * @copyright  2026 Zarith
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Displays configuration health checks inside the existing settings page.
 *
 * @package    local_ragassistant
 * @copyright  2026 Zarith
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_ragassistant_admin_setting_configuration_health extends admin_setting {
    /**
     * Create the setting. It is a UI component only and stores no value.
     */
    public function __construct() {
        parent::__construct(
            'local_ragassistant/configuration_health',
            get_string('configurationhealth', 'local_ragassistant'),
            get_string('configurationhealth_desc', component: 'local_ragassistant'),
            ''
        );
    }

    /**
     * There is no stored value.
     *
     * @return null
     */
    public function get_setting() {
        return null;
    }

    /**
     * Nothing is saved for this setting.
     *
     * @param mixed $data Submitted value (ignored).
     * @return string Empty string: no error.
     */
    public function write_setting($data) {
        // This setting is only a UI component. It stores nothing.
        return '';
    }

    /**
     * Render the health check panel and load its JavaScript.
     *
     * @param mixed $data Setting value (unused).
     * @param string $query Admin search query, for highlighting.
     * @return string HTML.
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        // Each test is one row. The JavaScript module fills in the result and message.
        // Order: application server, background tasks, then the AI checks in the order of
        // the settings sections, with the local fallback last.
        $tests = [];
        foreach (['server', 'cron', 'llm', 'image', 'embedding', 'fallback'] as $key) {
            $label = get_string('healthcheck_test_' . $key, 'local_ragassistant');
            $tests[] = [
                'key' => $key,
                'label' => $label,
                'testing' => get_string('healthcheck_testing_row', 'local_ragassistant', $label),
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

    /**
     * Load the JavaScript module with its endpoint, strings and saved-key flags.
     */
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
                    // The JS replaces {seconds} with the time elapsed.
                    'elapsed' => get_string('healthcheck_elapsed', 'local_ragassistant', '{seconds}'),
                    'idle' => get_string('healthcheck_status_idle', 'local_ragassistant'),
                    'testing' => get_string('healthcheck_status_testing', 'local_ragassistant'),
                    'pass' => get_string('healthcheck_status_pass', 'local_ragassistant'),
                    'fail' => get_string('healthcheck_status_fail', 'local_ragassistant'),
                    'skipped' => get_string('healthcheck_status_skipped', 'local_ragassistant'),
                    'keysaved' => get_string('healthcheck_keysaved', 'local_ragassistant'),
                    'keynone' => get_string('healthcheck_keynone', 'local_ragassistant'),
                    'savelocked' => get_string('healthcheck_savelocked', 'local_ragassistant'),
                    'stale' => get_string('healthcheck_stale', 'local_ragassistant'),
                    'notneeded' => get_string('healthcheck_notneeded', 'local_ragassistant'),
                    'embeddingnotneeded' => get_string('healthcheck_embedding_notneeded', 'local_ragassistant'),
                    // The JS fills in {pass}, {fail} and {skipped} for all rows together.
                    'summary' => get_string('healthcheck_summary', 'local_ragassistant',
                        (object) ['pass' => '{pass}', 'fail' => '{fail}', 'skipped' => '{skipped}']),
                ],
                'keys' => $this->saved_keys(),
            ]]
        );
    }

    /**
     * Which providers have a saved key, as booleans only. Key values never reach the page.
     *
     * @return array Field name => provider => whether a key is saved.
     */
    private function saved_keys(): array {
        $saved = fn(string $name): bool => (string) get_config('local_ragassistant', $name) !== '';
        return [
            'provider_api_key' => [
                'openai' => $saved('openai_api_key'),
                'gemini' => $saved('gemini_api_key'),
            ],
            'image_provider_api_key' => [
                'openai' => $saved('image_openai_api_key'),
                'gemini' => $saved('image_gemini_api_key'),
            ],
            'embedding_provider_api_key' => [
                'openai' => $saved('embedding_openai_api_key'),
                'gemini' => $saved('embedding_gemini_api_key'),
            ],
        ];
    }
}
