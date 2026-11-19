<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-to-server client for the trusted local FastAPI service.
 */
class api_client
{
    /** @var string|null Backend URL typed in the settings form, used only for a configuration test. */
    private ?string $baseurloverride;

    /** @var string|null Application API key typed in the settings form, used only for a configuration test. */
    private ?string $apikeyoverride;

    /**
     * Normal use passes nothing and reads the saved settings. The configuration health
     * check passes values typed into the settings form so they can be tested before saving.
     *
     * @param string|null $baseurl Unsaved backend URL, or null for the saved one.
     * @param string|null $apikey Unsaved application API key, or null for the saved one.
     */
    public function __construct(?string $baseurl = null, ?string $apikey = null)
    {
        $this->baseurloverride = ($baseurl !== null && trim($baseurl) !== '') ? trim($baseurl) : null;
        $this->apikeyoverride = ($apikey !== null && trim($apikey) !== '') ? trim($apikey) : null;
    }

    /**
     * Return configured backend base URL.
     */
    public function get_base_url(): string
    {
        $url = $this->baseurloverride ?? get_config('local_ragassistant', 'api_base_url');

        $url = rtrim((string) ($url ?: 'http://127.0.0.1:8000'), '/');
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \moodle_exception('backendconnectionfailed', 'local_ragassistant');
        }

        $host = strtolower($parts['host']);
        $isloopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (strtolower($parts['scheme']) !== 'https' && !$isloopback) {
            throw new \moodle_exception('backendhttpsrequired', 'local_ragassistant');
        }

        return $url;
    }

    /**
     * Return the shared secret, decrypting it only inside Moodle PHP.
     */
    private function get_api_key(): string
    {
        return $this->apikeyoverride ?? secret_store::get_api_key();
    }

    /**
     * Verify that FastAPI is reachable and accepts the configured shared key.
     */
    public function test_connection(): array
    {
        // A short limit: this tiny authenticated request answers at once when the backend
        // is up. A proxy or port forward can accept the connection while the backend is
        // down, which would otherwise hold the health check for the full default timeout.
        return $this->post_json('/api/v1/admin/connection-test', [], 10);
    }

    /**
     * Run the backend's live configuration tests without saving anything.
     *
     * Saved settings are used unless an administrator supplied unsaved form
     * values in $overrides. Provider keys default to the keys saved in Moodle;
     * they are decrypted only here and sent over this authenticated
     * server-to-server request, never to browsers.
     *
     * @param array $overrides Validated unsaved values: llm_provider, llm_model,
     *     fallback_model, image_provider, image_model, reuse_chat_api_key,
     *     provider_api_key and image_provider_api_key.
     */
    public function test_configuration(array $overrides = []): array
    {
        $llmprovider = $overrides['llm_provider']
            ?? trim((string) get_config('local_ragassistant', 'llm_provider'));
        $imageprovider = $overrides['image_provider']
            ?? (trim((string) get_config('local_ragassistant', 'image_provider')) ?: 'openai');

        $payload = [
            'llm_provider' => $llmprovider,
            'llm_model' => $overrides['llm_model']
                ?? trim((string) get_config('local_ragassistant', 'chat_model')),
            'fallback_model' => $overrides['fallback_model']
                ?? trim((string) get_config('local_ragassistant', 'fallback_model')),
            'image_provider' => $imageprovider,
            'image_model' => $overrides['image_model']
                ?? trim((string) get_config('local_ragassistant', 'image_model')),
            'reuse_chat_api_key' => $overrides['reuse_chat_api_key']
                ?? (bool) get_config('local_ragassistant', 'reuse_chat_api_key'),
            'openai_api_key' => secret_store::get_provider_api_key('openai'),
            'gemini_api_key' => secret_store::get_provider_api_key('gemini'),
            'openai_image_api_key' => secret_store::get_image_provider_api_key('openai'),
            'gemini_image_api_key' => secret_store::get_image_provider_api_key('gemini'),
        ];

        // A key typed into the settings form but not saved yet applies to the
        // provider currently selected, matching how the setting saves it.
        if (!empty($overrides['provider_api_key']) && in_array($llmprovider, ['openai', 'gemini'], true)) {
            $payload[$llmprovider . '_api_key'] = $overrides['provider_api_key'];
        }
        if (!empty($overrides['image_provider_api_key']) && in_array($imageprovider, ['openai', 'gemini'], true)) {
            $payload[$imageprovider . '_image_api_key'] = $overrides['image_provider_api_key'];
        }

        return $this->post_json('/api/v1/admin/test-configuration', $payload);
    }

    /**
     * Synchronize configuration only when administrator-selected values change.
     *
     * The backend persists its runtime configuration, so repeating this
     * network round-trip for every student message is unnecessary.
     */
    public function sync_configuration_if_changed(): array
    {
        $configuration = [
            'llm_provider' => trim((string) get_config('local_ragassistant', 'llm_provider')),
            'llm_model' => trim((string) get_config('local_ragassistant', 'chat_model')),
            'fallback_provider' => 'ollama',
            'fallback_model' => trim((string) get_config('local_ragassistant', 'fallback_model')),
            'image_provider' => trim((string) get_config('local_ragassistant', 'image_provider')) ?: 'openai',
            'image_model' => trim((string) get_config('local_ragassistant', 'image_model')),
            'reuse_chat_api_key' => (bool) get_config('local_ragassistant', 'reuse_chat_api_key'),
        ];

        // Provider keys are included only in the server-to-server sync payload.
        // Fingerprint their hashes, never store plaintext credentials as sync metadata.
        $providerkeys = [
            'openai_api_key' => secret_store::get_provider_api_key('openai'),
            'gemini_api_key' => secret_store::get_provider_api_key('gemini'),
        ];
        $imageproviderkeys = [
            'openai_image_api_key' => secret_store::get_image_provider_api_key('openai'),
            'gemini_image_api_key' => secret_store::get_image_provider_api_key('gemini'),
        ];
        $fingerprintdata = $configuration + [
            'openai_api_key_fingerprint' => hash('sha256', $providerkeys['openai_api_key']),
            'gemini_api_key_fingerprint' => hash('sha256', $providerkeys['gemini_api_key']),
            'openai_image_api_key_fingerprint' => hash('sha256', $imageproviderkeys['openai_image_api_key']),
            'gemini_image_api_key_fingerprint' => hash('sha256', $imageproviderkeys['gemini_image_api_key']),
        ];
        $fingerprint = hash(
            'sha256',
            json_encode($fingerprintdata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $lastfingerprint = (string) get_config(
            'local_ragassistant',
            'synced_config_fingerprint'
        );

        if ($lastfingerprint !== '' && hash_equals($lastfingerprint, $fingerprint)) {
            return [
                'synchronized' => false,
                'reindex_required' => false,
            ];
        }

        $result = $this->sync_configuration();
        set_config('synced_config_fingerprint', $fingerprint, 'local_ragassistant');

        return $result;
    }

    /**
     * Synchronize the administrator-selected AI configuration with FastAPI.
     * Provider credentials are decrypted only in PHP and sent only over this
     * authenticated server-to-server request; they are never sent to browsers.
     */
    public function sync_configuration(): array
    {
        $payload = [
            'llm_provider' => trim((string) get_config('local_ragassistant', 'llm_provider')),
            'llm_model' => trim((string) get_config('local_ragassistant', 'chat_model')),
            'fallback_provider' => 'ollama',
            'fallback_model' => trim((string) get_config('local_ragassistant', 'fallback_model')),
            'image_provider' => trim((string) get_config('local_ragassistant', 'image_provider')) ?: 'openai',
            'image_model' => trim((string) get_config('local_ragassistant', 'image_model')),
            'reuse_chat_api_key' => (bool) get_config('local_ragassistant', 'reuse_chat_api_key'),
            // Credentials are decrypted only in PHP and transmitted over the authenticated
            // Moodle-to-FastAPI connection. FastAPI encrypts them before persistence.
            'openai_api_key' => secret_store::get_provider_api_key('openai'),
            'gemini_api_key' => secret_store::get_provider_api_key('gemini'),
            'openai_image_api_key' => secret_store::get_image_provider_api_key('openai'),
            'gemini_image_api_key' => secret_store::get_image_provider_api_key('gemini'),
        ];

        // A blank key keeps the backend's stored copy, so keys deleted in Moodle are
        // removed there with an explicit clear flag. A key saved again since then wins.
        $pendingclears = secret_store::get_pending_key_clears();
        foreach ($pendingclears as $field) {
            if ($payload[$field] === '') {
                $payload['clear_' . $field] = true;
            }
        }

        $result = $this->post_json('/api/v1/admin/config', $payload);
        if ($pendingclears) {
            secret_store::forget_pending_key_clears();
        }

        // The backend reports when stored document vectors need rebuilding.
        if (!empty($result['reindex_required'])) {
            // This flag is informational only; it must never block chat requests
            // or prevent a later configuration change from queueing its own rebuild.
            set_config('reindex_pending', 1, 'local_ragassistant');
            try {
                \core\task\manager::queue_adhoc_task(
                    new \local_ragassistant\task\reindex_documents()
                );
            } catch (\Throwable $exception) {
                set_config('reindex_pending', 0, 'local_ragassistant');
                throw $exception;
            }
        }

        return $result;
    }

    /**
     * Send a JSON POST request.
     *
     * @param string $path Backend path, for example /api/v1/chat.
     * @param array $payload Request body.
     * @param int $timeout Maximum seconds for the whole request.
     */
    public function post_json(string $path, array $payload, int $timeout = 420): array
    {
        $handle = curl_init($this->get_base_url() . $path);

        if ($handle === false) {
            throw new \moodle_exception(
                'backendconnectionfailed',
                'local_ragassistant'
            );
        }

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \moodle_exception(
                'backendinvalidresponse',
                'local_ragassistant',
                '',
                null,
                $e->getMessage()
            );
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-RAG-API-Key: ' . $this->get_api_key(),
            ],
            CURLOPT_POSTFIELDS => $json,
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $this->decode_response($body, $error, $status);
    }

    /**
     * Send a JSON PATCH request.
     */
    public function patch_json(string $path, array $payload): array
    {
        $handle = curl_init($this->get_base_url() . $path);

        if ($handle === false) {
            throw new \moodle_exception(
                'backendconnectionfailed',
                'local_ragassistant'
            );
        }

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \moodle_exception(
                'backendinvalidresponse',
                'local_ragassistant',
                '',
                null,
                $e->getMessage()
            );
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-RAG-API-Key: ' . $this->get_api_key(),
            ],
            CURLOPT_POSTFIELDS => $json,
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $this->decode_response($body, $error, $status);
    }

    /**
     * Upload a Moodle stored file for indexing.
     */
    public function index_file(
        \stored_file $file,
        int $documentid,
        int $courseid,
        int $ownerid,
        string $visibility,
        bool $retrievalenabled = true,
        bool $generatesuggestions = false
    ): array {
        // Keep backend indexing and query embedding settings synchronized.
        $this->sync_configuration_if_changed();
        $temp = $file->copy_content_to_temp();

        if (!$temp || !is_file($temp)) {
            throw new \moodle_exception(
                'filetempfailed',
                'local_ragassistant'
            );
        }

        try {
            $handle = curl_init(
                $this->get_base_url() . '/api/v1/documents/index'
            );

            if ($handle === false) {
                throw new \moodle_exception(
                    'backendconnectionfailed',
                    'local_ragassistant'
                );
            }

            $mime = function_exists('mime_content_type')
                ? (mime_content_type($temp) ?: 'application/octet-stream')
                : 'application/octet-stream';

            $post = [
                'file' => new \CURLFile(
                    $temp,
                    $mime,
                    $file->get_filename()
                ),
                'document_id' => (string) $documentid,
                'course_id' => (string) $courseid,
                'owner_id' => (string) $ownerid,
                'visibility' => $visibility,
                'retrieval_enabled' => $retrievalenabled ? '1' : '0',
                'generate_suggestions' => $generatesuggestions ? '1' : '0',
            ];

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-RAG-API-Key: ' . $this->get_api_key(),
                ],
                CURLOPT_POSTFIELDS => $post,
            ]);

            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );
            curl_close($handle);

            return $this->decode_response($body, $error, $status);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Regenerate document-grounded suggestions without reindexing vectors.
     */
    public function generate_document_suggestions(\stored_file $file, int $documentid): array {
        $this->sync_configuration_if_changed();
        $temp = $file->copy_content_to_temp();
        if (!$temp || !is_file($temp)) {
            throw new \moodle_exception('filetempfailed', 'local_ragassistant');
        }

        try {
            $handle = curl_init($this->get_base_url() . '/api/v1/documents/suggestions');
            if ($handle === false) {
                throw new \moodle_exception('backendconnectionfailed', 'local_ragassistant');
            }
            $mime = function_exists('mime_content_type')
                ? (mime_content_type($temp) ?: 'application/octet-stream')
                : 'application/octet-stream';
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 240,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-RAG-API-Key: ' . $this->get_api_key(),
                ],
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($temp, $mime, $file->get_filename()),
                    'document_id' => (string) $documentid,
                ],
            ]);
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            return $this->decode_response($body, $error, $status);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Generate one suggestion for a document without re-indexing its vectors.
     */
    public function generate_document_suggestion(\stored_file $file, int $documentid, int $slot, array $existing): array {
        $this->sync_configuration_if_changed();
        $temp = $file->copy_content_to_temp();
        if (!$temp || !is_file($temp)) {
            throw new \moodle_exception('filetempfailed', 'local_ragassistant');
        }
        try {
            $handle = curl_init(
                $this->get_base_url() . '/api/v1/documents/' .
                rawurlencode((string) $documentid) . '/suggestions/' . $slot
            );
            if ($handle === false) {
                throw new \moodle_exception('backendconnectionfailed', 'local_ragassistant');
            }
            $mime = function_exists('mime_content_type')
                ? (mime_content_type($temp) ?: 'application/octet-stream')
                : 'application/octet-stream';
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 240,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-RAG-API-Key: ' . $this->get_api_key(),
                ],
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($temp, $mime, $file->get_filename()),
                    'existing_suggestions' => json_encode($existing, JSON_UNESCAPED_UNICODE),
                ],
            ]);
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            return $this->decode_response($body, $error, $status);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Enable or disable retrieval for a document.
     */
    public function set_document_retrieval(int $documentid, bool $enabled): array {
        return $this->patch_json(
            '/api/v1/documents/' . rawurlencode((string) $documentid) . '/retrieval',
            ['enabled' => $enabled]
        );
    }

    /**
     * Remove a document's vectors from the backend.
     */
    public function delete_document(int $documentid, int $courseid): array
    {
        $handle = curl_init(
            $this->get_base_url() .
            '/api/v1/documents/' .
            rawurlencode((string) $documentid) .
            '?' .
            http_build_query(['course_id' => $courseid], '', '&', PHP_QUERY_RFC3986)
        );

        if ($handle === false) {
            throw new \moodle_exception(
                'backendconnectionfailed',
                'local_ragassistant'
            );
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-RAG-API-Key: ' . $this->get_api_key(),
            ],
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $this->decode_response($body, $error, $status);
    }

    /**
     * Validate HTTP status and decode the backend response.
     */
    private function decode_response($body, string $error, int $status): array
    {
        if ($body === false || $error !== '') {
            throw new \moodle_exception(
                'backendconnectionfailed',
                'local_ragassistant',
                '',
                null,
                $error
            );
        }

        $data = json_decode((string) $body, true);

        if ($status < 200 || $status >= 300) {
            $detail = 'HTTP ' . $status;

            if (is_array($data) && array_key_exists('detail', $data)) {
                $rawdetail = $data['detail'];

                if (is_string($rawdetail) || is_numeric($rawdetail)) {
                    $detail = (string) $rawdetail;
                } else {
                    $encoded = json_encode(
                        $rawdetail,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );

                    $detail = $encoded !== false
                        ? $encoded
                        : 'Backend returned a non-string error detail.';
                }
            }

            throw new \moodle_exception(
                'backendrequestfailed',
                'local_ragassistant',
                '',
                $detail
            );
        }

        if (!is_array($data)) {
            throw new \moodle_exception(
                'backendinvalidresponse',
                'local_ragassistant'
            );
        }

        return $data;
    }
}
