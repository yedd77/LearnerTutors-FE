<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Background document indexing and suggestion-generation task.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Process one document outside the user's Moodle request.
 */
class index_document extends \core\task\adhoc_task {
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
     * Update suggestion progress without changing the document's ready status.
     *
     * @param int $documentid
     * @param string $stage
     */
    private function update_suggestion_stage(int $documentid, string $stage): void {
        global $DB;
        $DB->update_record('local_ragassistant_documents', (object) [
            'id' => $documentid,
            'stage' => $stage,
            'jobtype' => 'suggestions',
            'timemodified' => time(),
        ]);
    }

    /**
     * Queue suggestion generation separately from vector indexing.
     *
     * @param int $documentid
     */
    private function queue_suggestion_task(int $documentid): void {
        global $DB;
        $task = new self();
        $task->set_component('local_ragassistant');
        $task->set_custom_data([
            'documentid' => $documentid,
            'jobtype' => 'suggestions',
        ]);
        $DB->set_field('local_ragassistant_documents', 'stage', 'suggestions queued', ['id' => $documentid]);
        $DB->set_field('local_ragassistant_documents', 'jobtype', 'suggestions', ['id' => $documentid]);
        \core\task\manager::queue_adhoc_task($task);
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
                throw new \moodle_exception('storedfilemissing', 'local_ragassistant');
            }

            $api = new \local_ragassistant\local\api_client();
            if ($jobtype === 'suggestions') {
                // Suggestion work is independent: keep the indexed document ready
                // so retrieval and management actions remain available.
                $this->update_suggestion_stage($documentid, 'generating suggestions');
                $result = $api->generate_document_suggestions($stored, $documentid);
                $suggestions = $result['suggestions'] ?? [];
                if (!is_array($suggestions) || count($suggestions) < 3) {
                    mtrace('RAG Assistant: backend returned incomplete suggestions for document ' .
                        $documentid . '; using local fallback.');
                    $suggestions = $this->fallback_suggestions(
                        (string) $record->filename,
                        (string) ($record->lecturernote ?? '')
                    );
                }
                $saved = $this->save_suggestions(
                    $documentid,
                    (int) $record->courseid,
                    (int) $record->userid,
                    $suggestions
                );
                $this->update_suggestion_stage(
                    $documentid,
                    $saved > 0 ? 'complete' : 'suggestions unavailable'
                );
                mtrace('RAG Assistant: generated ' . $saved . ' suggestion(s) for document ' . $documentid . '.');
                return;
            }

            $this->update_progress($documentid, 'processing', 20, 'indexing document');
            // Keep the indexing request focused on parsing and embeddings only.
            // The separate suggestion task is queued after the document is ready.
            $result = $api->index_file(
                $stored,
                $documentid,
                (int) $record->courseid,
                (int) $record->userid,
                (string) $record->visibility,
                !empty($record->retrievalenabled),
                false
            );

            $this->update_progress($documentid, 'ready', 100, 'complete');
            mtrace('RAG Assistant: indexed document ' . $documentid . ' (' .
                (int) ($result['indexed_chunks'] ?? 0) . ' chunks).');

            // Save usable starter questions immediately after indexing. The
            // LLM task may take minutes; students should not wait for it.
            $this->save_suggestions(
                $documentid,
                (int) $record->courseid,
                (int) $record->userid,
                $this->fallback_suggestions(
                    (string) $record->filename,
                    (string) ($record->lecturernote ?? '')
                )
            );

            // Initial indexing and reindexing both refresh starter suggestions,
            // but suggestion failure must never roll back a successful vector index.
            try {
                $this->queue_suggestion_task($documentid);
            } catch (\Throwable $exception) {
                // Keep the indexed document usable even if Moodle cannot queue
                // the independent suggestion task.
                $saved = $this->save_suggestions(
                    $documentid,
                    (int) $record->courseid,
                    (int) $record->userid,
                    $this->fallback_suggestions(
                        (string) $record->filename,
                        (string) ($record->lecturernote ?? '')
                    )
                );
                $this->update_suggestion_stage(
                    $documentid,
                    $saved > 0 ? 'complete (local fallback)' : 'suggestions failed'
                );
                mtrace('RAG Assistant: could not queue suggestions for document ' . $documentid . ': ' .
                    $exception->getMessage());
            }
        } catch (\Throwable $exception) {
            if ($jobtype === 'suggestions') {
                // The indexed vectors are already valid. Never force the lecturer
                // to reindex just because the LLM or suggestion endpoint failed.
                try {
                    $saved = $this->save_suggestions(
                        $documentid,
                        (int) $record->courseid,
                        (int) $record->userid,
                        $this->fallback_suggestions(
                            (string) $record->filename,
                            (string) ($record->lecturernote ?? '')
                        )
                    );
                    $this->update_suggestion_stage(
                        $documentid,
                        $saved > 0 ? 'complete (local fallback)' : 'suggestions failed'
                    );
                } catch (\Throwable $fallbackexception) {
                    $this->update_suggestion_stage($documentid, 'suggestions failed');
                    mtrace('RAG Assistant: local suggestion fallback failed for document ' .
                        $documentid . ': ' . $fallbackexception->getMessage());
                }
            } else {
                $this->update_progress($documentid, 'failed', 100, 'failed');
            }
            mtrace('RAG Assistant: ' . $jobtype . ' task failed for document ' . $documentid . ': ' .
                $exception->getMessage());
            // Suggestion errors do not change the ready status or indexed vectors.
        }
    }

    /**
     * Create deterministic starter questions when the backend/LLM is unavailable.
     *
     * These questions are deliberately based on lecturer-provided document metadata
     * rather than fabricated document content. They keep the chatbot usable while
     * allowing the lecturer to retry suggestion generation independently.
     *
     * @param string $filename
     * @param string $lecturernote
     * @return array
     */
    private function fallback_suggestions(string $filename, string $lecturernote): array {
        $title = trim(pathinfo($filename, PATHINFO_FILENAME));
        $title = preg_replace('/\\s+/', ' ', $title);
        $title = $title !== '' ? $title : 'this document';
        $questions = [
            'What are the main concepts covered in ' . $title . '?',
            'What important details and examples should I understand from ' . $title . '?',
            'How can I apply the knowledge from ' . $title . ' to a practical situation?',
        ];
        if (trim($lecturernote) !== '') {
            $note = \core_text::substr(trim(clean_param($lecturernote, PARAM_TEXT)), 0, 180);
            $questions[2] = 'How does ' . $title . ' relate to this lecturer note: ' . $note . '?';
        }
        return $questions;
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
            $suggestion = \core_text::substr(trim(clean_param($suggestion, PARAM_TEXT)), 0, 500);
            $key = \core_text::strtolower($suggestion);
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
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
            throw $exception;
        }
        return count($cleaned);
    }
}
