<?php
// This file is part of Moodle - https://moodle.org/
//
// Configuration health checks for the plugin settings page.
//
// Backend requests go through api_client, which uses the saved backend URL and
// the saved encrypted application API key. Credentials are never returned to
// the browser or written to logs.

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

    // Keys the administrator typed but has not saved yet. Blank means "use the saved key".
    foreach (['provider_api_key', 'image_provider_api_key'] as $name) {
        if (isset($config[$name]) && is_string($config[$name])) {
            $value = trim($config[$name]);
            if ($value !== '' && strlen($value) <= 512) {
                $overrides[$name] = $value;
            }
        }
    }

    return $overrides;
}

try {
    $overrides = local_ragassistant_healthcheck_overrides(optional_param('config', '', PARAM_RAW));

    // Release the session lock: the provider tests can take a while.
    \core\session\manager::write_close();
    core_php_time_limit::raise(900);

    $client = new \local_ragassistant\local\api_client();
    $results = [];

    // 1. Backend connectivity and 2. application API key authentication.
    try {
        $connection = $client->test_connection();
        $results['server'] = local_ragassistant_healthcheck_result(
            'pass',
            get_string('healthcheck_server_pass', 'local_ragassistant')
        );
        if (!empty($connection['authenticated']) && ($connection['status'] ?? '') === 'ok') {
            $results['auth'] = local_ragassistant_healthcheck_result(
                'pass',
                get_string('healthcheck_auth_pass', 'local_ragassistant')
            );
        } else {
            $results['auth'] = local_ragassistant_healthcheck_result(
                'fail',
                get_string('backendinvalidresponse', 'local_ragassistant')
            );
        }
    } catch (moodle_exception $exception) {
        local_ragassistant_healthcheck_log('connection', $exception);

        switch ($exception->errorcode) {
            case 'backendrequestfailed':
                // The backend answered with an HTTP error, so it is reachable.
                $results['server'] = local_ragassistant_healthcheck_result(
                    'pass',
                    get_string('healthcheck_server_pass', 'local_ragassistant')
                );
                $results['auth'] = local_ragassistant_healthcheck_result(
                    'fail',
                    local_ragassistant_healthcheck_clean($exception->getMessage())
                );
                break;

            case 'backendinvalidresponse':
                $results['server'] = local_ragassistant_healthcheck_result(
                    'pass',
                    get_string('healthcheck_server_pass', 'local_ragassistant')
                );
                $results['auth'] = local_ragassistant_healthcheck_result(
                    'fail',
                    get_string('backendinvalidresponse', 'local_ragassistant')
                );
                break;

            case 'apikeymissing':
            case 'secretdecryptionfailed':
            case 'encryptionkeymissing':
            case 'encryptionkeyinvalid':
                // The saved key could not be loaded, so no request was sent.
                $results['server'] = local_ragassistant_healthcheck_result(
                    'skipped',
                    get_string('healthcheck_server_skipped', 'local_ragassistant')
                );
                $results['auth'] = local_ragassistant_healthcheck_result(
                    'fail',
                    $exception->errorcode === 'apikeymissing'
                        ? get_string('healthcheck_apikeymissing', 'local_ragassistant')
                        : local_ragassistant_healthcheck_clean($exception->getMessage())
                );
                break;

            case 'backendhttpsrequired':
                $results['server'] = local_ragassistant_healthcheck_result(
                    'fail',
                    get_string('backendhttpsrequired', 'local_ragassistant')
                );
                $results['auth'] = local_ragassistant_healthcheck_result(
                    'skipped',
                    get_string('healthcheck_auth_skipped', 'local_ragassistant')
                );
                break;

            default:
                $results['server'] = local_ragassistant_healthcheck_result(
                    'fail',
                    get_string('healthcheck_server_fail', 'local_ragassistant')
                );
                $results['auth'] = local_ragassistant_healthcheck_result(
                    'skipped',
                    get_string('healthcheck_auth_skipped', 'local_ragassistant')
                );
        }
    } catch (Throwable $exception) {
        local_ragassistant_healthcheck_log('connection', $exception);
        $results['server'] = local_ragassistant_healthcheck_result(
            'fail',
            get_string('healthcheck_server_fail', 'local_ragassistant')
        );
        $results['auth'] = local_ragassistant_healthcheck_result(
            'skipped',
            get_string('healthcheck_auth_skipped', 'local_ragassistant')
        );
    }

    // 3-5. Main LLM, Ollama fallback and image generation. The backend runs these
    // independently and reports pass, fail or skipped for each one.
    $providertests = ['llm', 'fallback', 'image'];
    $backendready = $results['server']['status'] === 'pass' && $results['auth']['status'] === 'pass';
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
        foreach (['fallback', 'image'] as $test) {
            $results[$test] = local_ragassistant_healthcheck_result(
                'skipped',
                get_string('healthcheck_needsmodel', 'local_ragassistant')
            );
        }
    } else {
        try {
            $response = $client->test_configuration($overrides);
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
