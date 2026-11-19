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
 * Configuration health checks for the plugin settings page.
 *
 * Backend requests go through api_client, which uses the saved backend URL and
 * the saved encrypted application API key unless the administrator typed new
 * values to test. Credentials are never returned to the browser or written to logs.
 *
 * @package    local_ragassistant
 * @copyright  2026 Zarith
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

/**
 * Build one test result for the browser.
 *
 * @param string $status pass, fail or skipped
 * @param string $message Human-readable, credential-free message
 * @return array
 */
function local_ragassistant_healthcheck_result(string $status, string $message): array {
    return ['status' => $status, 'message' => $message];
}

/**
 * Write a credential-free diagnostic line to the PHP error log.
 *
 * debugging() must not be used here: with developer debugging and Whoops
 * enabled it is raised as an error and replaces the JSON response with HTML.
 *
 * @param string $check Name of the failed check
 * @param Throwable $exception
 */
function local_ragassistant_healthcheck_log(string $check, Throwable $exception): void {
    // Moodle appends the debug info (for example the curl error) to the message in developer mode.
    // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- debugging() breaks the JSON reply, see above.
    error_log('local_ragassistant configuration health: ' . $check . ' check failed: ' .
        get_class($exception) . ': ' . $exception->getMessage());
}

/**
 * Shorten and clean a message reported by the backend before showing it.
 *
 * @param mixed $message
 * @return string
 */
function local_ragassistant_healthcheck_clean($message): string {
    if (!is_string($message) && !is_numeric($message)) {
        return '';
    }
    $text = clean_param((string) $message, PARAM_TEXT);
    // Hide anything that looks like a provider key, even a partial one.
    $text = preg_replace('/\b(?:sk-|AIza)[A-Za-z0-9_\-\*\.]{6,}/', '[hidden]', $text);
    return shorten_text($text, 300);
}

/**
 * Check that Moodle background tasks run. Document indexing is an ad hoc task, so
 * uploads are not processed while cron is stopped or indexing tasks keep failing.
 *
 * @return array
 */
function local_ragassistant_healthcheck_cron(): array {
    global $DB;

    $now = time();
    $lastcron = (int) get_config('tool_task', 'lastcronstart');
    if ($lastcron === 0) {
        return local_ragassistant_healthcheck_result('fail', get_string('healthcheck_cron_never', 'local_ragassistant'));
    }
    if ($now - $lastcron > HOURSECS) {
        return local_ragassistant_healthcheck_result(
            'fail',
            get_string('healthcheck_cron_overdue', 'local_ragassistant', format_time($now - $lastcron))
        );
    }

    $failing = $DB->count_records_select(
        'task_adhoc',
        'component = :component AND faildelay > 0',
        ['component' => 'local_ragassistant']
    );
    if ($failing) {
        return local_ragassistant_healthcheck_result(
            'fail',
            get_string('healthcheck_cron_failing', 'local_ragassistant', $failing)
        );
    }

    $waiting = $DB->count_records_select(
        'task_adhoc',
        'component = :component AND timestarted IS NULL AND nextruntime < :cutoff',
        ['component' => 'local_ragassistant', 'cutoff' => $now - HOURSECS]
    );
    if ($waiting) {
        return local_ragassistant_healthcheck_result(
            'fail',
            get_string('healthcheck_cron_waiting', 'local_ragassistant', $waiting)
        );
    }

    return local_ragassistant_healthcheck_result(
        'pass',
        get_string('healthcheck_cron_pass', 'local_ragassistant', format_time($now - $lastcron))
    );
}

/**
 * Read the unsaved settings form values sent by the browser.
 *
 * Only known fields are accepted. Fields that are absent fall back to the
 * saved settings inside api_client::test_configuration().
 *
 * @param string $rawconfig JSON object from the browser
 * @return array
 */
function local_ragassistant_healthcheck_overrides(string $rawconfig): array {
    if ($rawconfig === '') {
        return [];
    }

    $config = json_decode($rawconfig, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new invalid_parameter_exception('Invalid configuration.');
    }

    $overrides = [];

    $providers = [
        'llm_provider' => ['openai', 'gemini', 'ollama'],
        'image_provider' => ['openai', 'gemini'],
    ];
    foreach ($providers as $name => $allowed) {
        if (isset($config[$name]) && in_array($config[$name], $allowed, true)) {
            $overrides[$name] = $config[$name];
        }
    }

    foreach (['llm_model', 'fallback_model', 'image_model'] as $name) {
        if (array_key_exists($name, $config) && is_string($config[$name])) {
            $overrides[$name] = core_text::substr(trim(clean_param($config[$name], PARAM_TEXT)), 0, 160);
        }
    }

    if (array_key_exists('reuse_chat_api_key', $config)) {
        $overrides['reuse_chat_api_key'] = !empty($config['reuse_chat_api_key']);
    }

    // Keys the administrator typed but has not saved yet, including the application key.
    // Blank means "use the saved key". Testing never saves anything.
    foreach (['provider_api_key', 'image_provider_api_key', 'api_key'] as $name) {
        if (isset($config[$name]) && is_string($config[$name])) {
            $value = trim($config[$name]);
            if ($value !== '' && strlen($value) <= 512) {
                $overrides[$name] = $value;
            }
        }
    }

    // The backend URL as typed, so a new URL can be tested before it is saved. The API
    // client applies the same rules as for the saved URL (HTTPS except on loopback).
    if (isset($config['api_base_url']) && is_string($config['api_base_url'])) {
        $url = trim($config['api_base_url']);
        if ($url !== '' && strlen($url) <= 255) {
            $overrides['api_base_url'] = $url;
        }
    }

    return $overrides;
}

try {
    $overrides = local_ragassistant_healthcheck_overrides(optional_param('config', '', PARAM_RAW));

    // Release the session lock: the provider tests can take a while.
    \core\session\manager::write_close();
    core_php_time_limit::raise(900);

    // Typed (unsaved) backend URL and application key are used for this test run only.
    $client = new \local_ragassistant\local\api_client(
        $overrides['api_base_url'] ?? null,
        $overrides['api_key'] ?? null
    );
    $results = [];

    // Only the checks the page asks for run, for example just the ones that failed last
    // time. Every AI check needs the backend, so it re-checks the server too. The backend
    // always tests the LLM, so any AI run also reports the LLM.
    $alltests = ['server', 'cron', 'llm', 'image', 'fallback'];
    $requested = array_values(array_intersect($alltests, explode(',', optional_param('tests', '', PARAM_TEXT))));
    if (!$requested) {
        $requested = $alltests;
    }
    $providertests = array_values(array_intersect(['llm', 'image', 'fallback'], $requested));
    if ($providertests && !in_array('llm', $providertests, true)) {
        array_unshift($providertests, 'llm');
    }
    $runserver = in_array('server', $requested, true) || $providertests;

    if ($runserver) {
        // 1. Application server: the URL is reachable, then the saved API key is accepted.
        // The API client needs the saved key to send any request, so a missing or unreadable
        // key is reported before the URL can be tried.
        try {
            $connection = $client->test_connection();
            $results['server'] = (!empty($connection['authenticated']) && ($connection['status'] ?? '') === 'ok')
                ? local_ragassistant_healthcheck_result('pass', get_string('healthcheck_server_pass', 'local_ragassistant'))
                : local_ragassistant_healthcheck_result('fail', get_string('backendinvalidresponse', 'local_ragassistant'));
        } catch (moodle_exception $exception) {
            local_ragassistant_healthcheck_log('server', $exception);

            switch ($exception->errorcode) {
                case 'backendrequestfailed':
                    // Reachable, but the backend refused the request (normally a wrong API key).
                    $message = local_ragassistant_healthcheck_clean($exception->getMessage());
                    break;
                case 'backendinvalidresponse':
                    $message = get_string('backendinvalidresponse', 'local_ragassistant');
                    break;
                case 'apikeymissing':
                    $message = get_string('healthcheck_apikeymissing', 'local_ragassistant');
                    break;
                case 'secretdecryptionfailed':
                case 'encryptionkeymissing':
                case 'encryptionkeyinvalid':
                    $message = local_ragassistant_healthcheck_clean($exception->getMessage());
                    break;
                case 'backendhttpsrequired':
                    $message = get_string('backendhttpsrequired', 'local_ragassistant');
                    break;
                default:
                    $message = get_string('healthcheck_server_fail', 'local_ragassistant');
            }
            $results['server'] = local_ragassistant_healthcheck_result('fail', $message);
        } catch (Throwable $exception) {
            local_ragassistant_healthcheck_log('server', $exception);
            $results['server'] = local_ragassistant_healthcheck_result(
                'fail',
                get_string('healthcheck_server_fail', 'local_ragassistant')
            );
        }
    }

    if (in_array('cron', $requested, true)) {
        // 2. Background tasks. Moodle-side only, so it runs even when the backend is down.
        try {
            $results['cron'] = local_ragassistant_healthcheck_cron();
        } catch (Throwable $exception) {
            local_ragassistant_healthcheck_log('cron', $exception);
            $results['cron'] = local_ragassistant_healthcheck_result(
                'fail',
                get_string('healthcheck_cron_error', 'local_ragassistant')
            );
        }
    }

    // 3-5. Main LLM, image generation and Ollama fallback. The backend runs these
    // independently and reports pass, fail or skipped for each one.
    if ($providertests) {
        $backendready = isset($results['server']) && $results['server']['status'] === 'pass';
        $llmmodel = $overrides['llm_model'] ?? trim((string) get_config('local_ragassistant', 'chat_model'));

        if (!$backendready) {
            foreach ($providertests as $test) {
                $results[$test] = local_ragassistant_healthcheck_result(
                    'skipped',
                    get_string('healthcheck_requires_backend', 'local_ragassistant')
                );
            }
        } else if ($llmmodel === '') {
            // The backend rejects a request without a chat model, so nothing can run.
            $results['llm'] = local_ragassistant_healthcheck_result(
                'fail',
                get_string('healthcheck_llmmodelmissing', 'local_ragassistant')
            );
            foreach (array_diff($providertests, ['llm']) as $test) {
                $results[$test] = local_ragassistant_healthcheck_result(
                    'skipped',
                    get_string('healthcheck_needsmodel', 'local_ragassistant')
                );
            }
        } else {
            try {
                // A blank model makes the backend skip that check, so one that already
                // passed is not run again (image generation is the slowest and costliest).
                $testoverrides = $overrides;
                if (!in_array('image', $providertests, true)) {
                    $testoverrides['image_model'] = '';
                }
                if (!in_array('fallback', $providertests, true)) {
                    $testoverrides['fallback_model'] = '';
                }
                $response = $client->test_configuration($testoverrides);
                $reported = (isset($response['results']) && is_array($response['results']))
                    ? $response['results']
                    : $response;

                // The backend reports these checks as llm, fallback_model and image_generation.
                $backendkeys = ['llm' => 'llm', 'fallback' => 'fallback_model', 'image' => 'image_generation'];
                foreach ($providertests as $test) {
                    $item = $reported[$backendkeys[$test]] ?? null;
                    $status = is_array($item) ? ($item['status'] ?? '') : '';

                    if (!in_array($status, ['pass', 'fail', 'skipped'], true)) {
                        $results[$test] = local_ragassistant_healthcheck_result(
                            'fail',
                            get_string('healthcheck_notreported', 'local_ragassistant')
                        );
                        continue;
                    }

                    $message = local_ragassistant_healthcheck_clean($item['message'] ?? '');
                    $results[$test] = local_ragassistant_healthcheck_result(
                        $status,
                        $message !== '' ? $message : get_string('healthcheck_status_' . $status, 'local_ragassistant')
                    );
                }
            } catch (Throwable $exception) {
                local_ragassistant_healthcheck_log('provider', $exception);
                $message = ($exception instanceof moodle_exception && $exception->errorcode === 'backendrequestfailed')
                    ? local_ragassistant_healthcheck_clean($exception->getMessage())
                    : get_string('healthcheck_requestfailed', 'local_ragassistant');

                foreach ($providertests as $test) {
                    $results[$test] = local_ragassistant_healthcheck_result('fail', $message);
                }
            }
        }
    }

    $counts = array_count_values(array_column($results, 'status'));

    echo json_encode([
        'success' => true,
        'summary' => get_string('healthcheck_summary', 'local_ragassistant', (object) [
            'pass' => $counts['pass'] ?? 0,
            'fail' => $counts['fail'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
        ]),
        'results' => $results,
    ]);
} catch (Throwable $exception) {
    local_ragassistant_healthcheck_log('request', $exception);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => get_string('healthcheck_requestfailed', 'local_ragassistant'),
    ]);
}
