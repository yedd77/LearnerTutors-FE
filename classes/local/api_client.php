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

        return rtrim(
            (string) ($url ?: 'http://127.0.0.1:8000'),
            '/'
        );
    }

    /**
     * Return the secret configured by a site administrator.
     */
    private function get_api_key(): string
    {
        $key = (string) get_config('local_ragassistant', 'api_key');

        if ($key === '') {
            throw new \moodle_exception(
                'apikeymissing',
                'local_ragassistant'
            );
        }

        return $key;
    }

    /**
     * Synchronize the administrator-selected AI configuration with FastAPI.
     * Provider credentials remain on the backend and are never sent by Moodle.
     */
    public function sync_configuration(): array
    {
        $result = $this->post_json('/api/v1/admin/config', [
            'llm_provider' => trim((string) get_config('local_ragassistant', 'llm_provider')) ?: 'openai',
            'llm_model' => trim((string) get_config('local_ragassistant', 'chat_model')) ?: 'gpt-4o-luna',
            'embedding_provider' => trim((string) get_config('local_ragassistant', 'embedding_provider')) ?: 'openai',
            'embedding_model' => trim((string) get_config('local_ragassistant', 'embedding_model')),
            'gemini_embed_dimensions' => max(0, (int) (get_config('local_ragassistant', 'gemini_embed_dimensions') ?: 768)),
        ]);

        // A model change invalidates generated suggestions; an embedding change
        // also switches Chroma collections. Rebuild every Moodle source document.
        if (!empty($result['reindex_required']) &&
                !get_config('local_ragassistant', 'reindex_pending')) {
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
            curl_close($handle);

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
            curl_close($handle);

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
        bool $retrievalenabled = true
    ): array {
        // Keep backend indexing and query embedding settings synchronized.
        $this->sync_configuration();
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
     * Enable or disable retrieval for a document.
     *
     * The FastAPI endpoint must support:
     * PATCH /api/v1/documents/{documentid}/retrieval
     */
    public function set_document_retrieval(
        int $documentid,
        bool $enabled
    ): array {
        return $this->patch_json(
            '/api/v1/documents/' .
            rawurlencode((string) $documentid) .
            '/retrieval',
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
    private function decode_response(
        $body,
        string $error,
        int $status
    ): array {
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
