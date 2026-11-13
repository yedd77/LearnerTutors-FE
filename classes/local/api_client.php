<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-to-server client for the trusted local FastAPI service.
 */
class api_client
{
    /**
     * Return configured backend base URL.
     */
    public function get_base_url(): string
    {
        $url = get_config('local_ragassistant', 'api_base_url');

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
        return secret_store::get_api_key();
    }

    /**
     * Verify that FastAPI is reachable and accepts the configured shared key.
     */
    public function test_connection(): array
    {
        return $this->post_json('/api/v1/admin/connection-test', []);
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
            'image_model' => trim((string) get_config('local_ragassistant', 'image_model')),
        ];

        // Provider keys are included only in the server-to-server sync payload.
        // Fingerprint their hashes, never store plaintext credentials as sync metadata.
        $providerkeys = [
            'openai_api_key' => secret_store::get_provider_api_key('openai'),
            'gemini_api_key' => secret_store::get_provider_api_key('gemini'),
        ];
        $fingerprintdata = $configuration + [
            'openai_api_key_fingerprint' => hash('sha256', $providerkeys['openai_api_key']),
            'gemini_api_key_fingerprint' => hash('sha256', $providerkeys['gemini_api_key']),
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
     * Provider credentials remain on the backend and are never sent by Moodle.
     */
    public function sync_configuration(): array
    {
        $result = $this->post_json('/api/v1/admin/config', [
            'llm_provider' => trim((string) get_config('local_ragassistant', 'llm_provider')),
            'llm_model' => trim((string) get_config('local_ragassistant', 'chat_model')),
            'image_model' => trim((string) get_config('local_ragassistant', 'image_model')),
            // Keys are decrypted only in PHP and transmitted over the authenticated
            // Moodle-to-FastAPI connection. FastAPI encrypts them before persistence.
            'openai_api_key' => secret_store::get_provider_api_key('openai'),
            'gemini_api_key' => secret_store::get_provider_api_key('gemini'),
        ]);

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
     */
    public function post_json(string $path, array $payload): array
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
            CURLOPT_TIMEOUT => 420,
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
