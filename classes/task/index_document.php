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
        $record = (object) [
            'id' => $documentid,
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'stage' => $stage,
            'timemodified' => time(),
        ];
        if ($status !== 'failed') {
            // A new attempt clears the reason of the previous failure.
            $record->errormessage = null;
        }
        $DB->update_record('local_ragassistant_documents', $record);
    }

    /**
     * Lecturer-facing reason for a failed indexing job, or '' when there is nothing useful to say.
     *
     * The backend's own rejection message is kept as is (for example "PDF exceeds the
     * 300-page limit."). Connection errors are summarised: their details (host, curl
     * text) stay in the task log only.
     *
     * @param \Throwable $exception
     * @return string
     */
    private function failure_reason(\Throwable $exception): string {
        if (!$exception instanceof \moodle_exception) {
            return '';
        }
        if ($exception->errorcode === 'backendrequestfailed' && is_string($exception->a)) {
            return \core_text::substr(trim(clean_param($exception->a, PARAM_TEXT)), 0, 500);
        }
        if ($exception->errorcode === 'backendconnectionfailed') {
            $timedout = stripos((string) $exception->debuginfo, 'timed out') !== false;
            return get_string($timedout ? 'indexfailedtimeout' : 'indexfailedconnection', 'local_ragassistant');
        }
        if ($exception->errorcode === 'storedfilemissing') {
            return get_string('storedfilemissing', 'local_ragassistant');
        }
        return '';
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

        // Reflect full-document suggestion generation in each visible table slot.
        if ($stage === 'generating suggestions') {
            $DB->set_field('local_ragassistant_suggestions', 'status', 'generating', [
                'documentid' => $documentid,
            ]);
        } else if ($stage === 'suggestions failed') {
            $DB->set_field('local_ragassistant_suggestions', 'status', 'failed', [
                'documentid' => $documentid,
                'status' => 'generating',
            ]);
        }
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
        $slot = (int) ($customdata->slot ?? 0);
        if ($documentid <= 0 || !in_array($jobtype, ['index', 'reindex', 'suggestions', 'suggestion_slot'], true) ||
                ($jobtype === 'suggestion_slot' && ($slot < 1 || $slot > 3))) {
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
            $ispastexam = \local_ragassistant\local\past_exam::is_past_exam($record);

            // Past exam papers have no starter suggestions: they could reveal its questions.
            if ($ispastexam && ($jobtype === 'suggestions' || $jobtype === 'suggestion_slot')) {
                $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $documentid]);
                if ($jobtype === 'suggestions') {
                    $DB->set_field('local_ragassistant_documents', 'stage', 'complete', ['id' => $documentid]);
                }
                mtrace('RAG Assistant: document ' . $documentid . ' is a past exam paper; no suggestions generated.');
                return;
            }

            if ($jobtype === 'suggestion_slot') {
                $this->generate_one_suggestion($api, $stored, $record, $slot);
                return;
            }

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

            $this->update_progress($documentid, 'processing', 20,
                $ispastexam ? 'analysing exam structure' : 'indexing document');
            // Keep the indexing request focused on parsing and embeddings only.
            // The separate suggestion task is queued after the document is ready.
            // The role is always resent, so reindexing keeps a past paper unsearchable.
            $result = $api->index_file(
                $stored,
                $documentid,
                (int) $record->courseid,
                (int) $record->userid,
                (string) $record->visibility,
                !empty($record->retrievalenabled),
                false,
                \local_ragassistant\local\past_exam::role_of($record)
            );

            if ($ispastexam) {
                // Saves the detected structure; no suggestions are made for a past paper.
                \local_ragassistant\local\past_exam::save_index_result($api, $record, $result);
                $this->update_progress($documentid, 'ready', 100, 'complete');
                mtrace('RAG Assistant: analysed past exam paper ' . $documentid . '.');
                return;
            }

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
            } else if ($jobtype === 'suggestion_slot') {
                $slot = (int) ($customdata->slot ?? 0);
                $saved = $DB->get_record('local_ragassistant_suggestions', [
                    'documentid' => $documentid,
                    'slot' => $slot,
                ]);
                $state = $saved && trim((string) $saved->suggestion) !== '' ? 'ready' : 'failed';
                $DB->set_field('local_ragassistant_suggestions', 'status', $state, [
                    'documentid' => $documentid,
                    'slot' => $slot,
                ]);
            } else {
                $this->update_progress($documentid, 'failed', 100, 'failed');
                $reason = $this->failure_reason($exception);
                $DB->set_field('local_ragassistant_documents', 'errormessage', $reason !== '' ? $reason : null,
                    ['id' => $documentid]);
            }
            mtrace('RAG Assistant: ' . $jobtype . ' task failed for document ' . $documentid . ': ' .
                $exception->getMessage());
            // Suggestion errors do not change the ready status or indexed vectors.
        }
    }

    /**
     * Generate and persist exactly one suggestion slot.
     */
    private function generate_one_suggestion(
        \local_ragassistant\local\api_client $api,
        \stored_file $stored,
        \stdClass $record,
        int $slot
    ): void {
        global $DB;

        $existingrecords = $DB->get_records(
            'local_ragassistant_suggestions',
            ['documentid' => $record->id],
            'slot ASC'
        );
        $existingtexts = [];
        foreach ($existingrecords as $existingrecord) {
            if ((int) $existingrecord->slot !== $slot && trim((string) $existingrecord->suggestion) !== '') {
                $existingtexts[] = (string) $existingrecord->suggestion;
            }
        }

        try {
            $result = $api->generate_document_suggestion($stored, (int) $record->id, $slot, $existingtexts);
            $suggestion = trim(clean_param((string) ($result['suggestion'] ?? ''), PARAM_TEXT));
            $suggestion = \core_text::substr($suggestion, 0, 500);
            if ($suggestion === '') {
                throw new \moodle_exception('backendinvalidresponse', 'local_ragassistant');
            }

            $saved = $DB->get_record('local_ragassistant_suggestions', [
                'documentid' => $record->id,
                'slot' => $slot,
            ]);
            $values = (object) [
                'courseid' => $record->courseid,
                'documentid' => $record->id,
                'userid' => $record->userid,
                'slot' => $slot,
                'suggestion' => $suggestion,
                'status' => 'ready',
                'timemodified' => time(),
                'timecreated' => $saved->timecreated ?? time(),
            ];
            if ($saved) {
                $values->id = $saved->id;
                $DB->update_record('local_ragassistant_suggestions', $values);
            } else {
                $DB->insert_record('local_ragassistant_suggestions', $values);
            }
            mtrace('RAG Assistant: generated suggestion slot ' . $slot . ' for document ' . $record->id . '.');
        } catch (\Throwable $exception) {
            $DB->set_field('local_ragassistant_suggestions', 'status', 'failed', [
                'documentid' => $record->id,
                'slot' => $slot,
            ]);
            mtrace('RAG Assistant: suggestion slot ' . $slot . ' failed for document ' . $record->id . ': ' .
                $exception->getMessage());
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
        $title = preg_replace('/\s+/', ' ', $title);
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
        if (count($cleaned) < 3) {
            return 0;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $documentid]);
            $slot = 0;
            foreach ($cleaned as $suggestion) {
                $slot++;
                $DB->insert_record('local_ragassistant_suggestions', (object) [
                    'courseid' => $courseid,
                    'documentid' => $documentid,
                    'userid' => $userid,
                    'slot' => $slot,
                    'suggestion' => $suggestion,
                    'status' => 'ready',
                    'timecreated' => time(),
                    'timemodified' => time(),
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
