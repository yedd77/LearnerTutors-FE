<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Background document indexing and suggestion-generation task.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Process one document outside the user's Moodle request.
 */
class index_document extends \\core\\task\\adhoc_task {
    /**
     * Persist a progress update for the document.
     *
     * @param int $documentid
     * @param string $status
     * @param int $progress
     * @param string $stage
     */
    private function update_progress(int $documentid, string $status, int $progress, string $stage): void {
        global $DB;
        $DB->update_record('local_ragassistant_documents', (object) [
            'id' => $documentid,
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'stage' => $stage,
            'timemodified' => time(),
        ]);
    }

    /**
     * Execute the queued job.
     */
    public function execute(): void {
        global $DB;

        $customdata = $this->get_custom_data();
        $documentid = (int) ($customdata->documentid ?? 0);
        $jobtype = (string) ($customdata->jobtype ?? 'index');
        if ($documentid <= 0 || !in_array($jobtype, ['index', 'reindex', 'suggestions'], true)) {
            mtrace('RAG Assistant: document task has invalid data.');
            return;
        }

        $record = $DB->get_record('local_ragassistant_documents', ['id' => $documentid]);
        if (!$record) {
            mtrace('RAG Assistant: document ' . $documentid . ' no longer exists; skipping task.');
            return;
        }

        $this->update_progress($documentid, 'processing', 5, 'preparing');
        try {
            $stored = get_file_storage()->get_file(
                (int) $record->contextid,
                'local_ragassistant',
                'documents',
                (int) $record->fileitemid,
                $record->filepath,
                $record->filename
            );
            if (!$stored || $stored->is_directory()) {
                throw new \\moodle_exception('storedfilemissing', 'local_ragassistant');
            }

            $api = new \\local_ragassistant\\local\\api_client();
            if ($jobtype === 'suggestions') {
                $this->update_progress($documentid, 'processing', 25, 'generating suggestions');
                $result = $api->generate_document_suggestions($stored, $documentid);
                $saved = $this->save_suggestions(
                    $documentid,
                    (int) $record->courseid,
                    (int) $record->userid,
                    $result['suggestions'] ?? []
                );
                mtrace('RAG Assistant: regenerated ' . $saved . ' suggestion(s) for document ' . $documentid . '.');
            } else {
                $this->update_progress($documentid, 'processing', 20, 'indexing document');
                $generatesuggestions = ($jobtype === 'index');
                $result = $api->index_file(
                    $stored,
                    $documentid,
                    (int) $record->courseid,
                    (int) $record->userid,
                    (string) $record->visibility,
                    !empty($record->retrievalenabled),
                    $generatesuggestions
                );

                if ($generatesuggestions) {
                    $this->update_progress($documentid, 'processing', 90, 'saving suggestions');
                    $saved = $this->save_suggestions(
                        $documentid,
                        (int) $record->courseid,
                        (int) $record->userid,
                        $result['suggestions'] ?? []
                    );
                } else {
                    $saved = 0;
                }
                mtrace('RAG Assistant: indexed document ' . $documentid . ' (' .
                    (int) ($result['indexed_chunks'] ?? 0) . ' chunks); saved ' . $saved . ' suggestion(s).');
            }

            $this->update_progress($documentid, 'ready', 100, 'complete');
        } catch (\\Throwable $exception) {
            $this->update_progress($documentid, 'failed', 100, 'failed');
            mtrace('RAG Assistant: ' . $jobtype . ' task failed for document ' . $documentid . ': ' .
                $exception->getMessage());
            // Do not automatically retry; a lecturer can explicitly queue the job again.
        }
    }

    /**
     * Validate and atomically replace suggestions when valid replacements exist.
     *
     * @param int $documentid
     * @param int $courseid
     * @param int $userid
     * @param mixed $suggestions
     * @return int
     */
    private function save_suggestions(int $documentid, int $courseid, int $userid, $suggestions): int {
        global $DB;
        if (!is_array($suggestions)) {
            return 0;
        }
        $cleaned = [];
        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion)) {
                continue;
            }
            $suggestion = \\core_text::substr(trim(clean_param($suggestion, PARAM_TEXT)), 0, 500);
            $key = \\core_text::strtolower($suggestion);
            if ($suggestion !== '' && !isset($cleaned[$key])) {
                $cleaned[$key] = $suggestion;
            }
            if (count($cleaned) >= 3) {
                break;
            }
        }
        // Preserve existing suggestions if the model returns no valid replacements.
        if (!$cleaned) {
            return 0;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $documentid]);
            foreach ($cleaned as $suggestion) {
                $DB->insert_record('local_ragassistant_suggestions', (object) [
                    'courseid' => $courseid,
                    'documentid' => $documentid,
                    'userid' => $userid,
                    'suggestion' => $suggestion,
                    'timecreated' => time(),
                ]);
            }
            $transaction->allow_commit();
        } catch (\\Throwable $exception) {
            $transaction->rollback($exception);
            throw $exception;
        }
        return count($cleaned);
    }
}
