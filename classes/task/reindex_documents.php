<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Rebuild every indexed document after the site AI configuration changes.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Re-embed documents and regenerate starter suggestions using the selected models.
 */
class reindex_documents extends \core\task\adhoc_task {
    /**
     * Execute the rebuild.
     */
    public function execute(): void {
        global $DB;

        $api = new \local_ragassistant\local\api_client();
        $fs = get_file_storage();
        $records = $DB->get_records('local_ragassistant_documents', null, 'id ASC');
        $success = 0;
        $failed = 0;

        mtrace('RAG Assistant: rebuilding ' . count($records) . ' stored document(s) after AI configuration change.');

        try {
            foreach ($records as $record) {
                $stored = $fs->get_file(
                    (int) $record->contextid,
                    'local_ragassistant',
                    'documents',
                    (int) $record->fileitemid,
                    $record->filepath,
                    $record->filename
                );

                if (!$stored || $stored->is_directory()) {
                    $DB->update_record('local_ragassistant_documents', (object) [
                        'id' => $record->id,
                        'status' => 'failed',
                        'progress' => 100,
                        'stage' => 'failed',
                        'jobtype' => '',
                        'timemodified' => time(),
                    ]);
                    mtrace('RAG Assistant: source file missing for document ' . $record->id . '; marked failed.');
                    $failed++;
                    continue;
                }

                $DB->update_record('local_ragassistant_documents', (object) [
                    'id' => $record->id,
                    'status' => 'processing',
                    'progress' => 20,
                    'stage' => 'reindexing after configuration change',
                    'jobtype' => 'reindex',
                    'timemodified' => time(),
                ]);

                try {
                    $result = $api->index_file(
                        $stored,
                        (int) $record->id,
                        (int) $record->courseid,
                        (int) $record->userid,
                        (string) $record->visibility,
                        !empty($record->retrievalenabled)
                    );

                    $suggestions = $result['suggestions'] ?? [];
                    $cleaned = [];
                    if (is_array($suggestions)) {
                        foreach ($suggestions as $suggestion) {
                            if (!is_string($suggestion)) {
                                continue;
                            }
                            $suggestion = core_text::substr(trim(clean_param($suggestion, PARAM_TEXT)), 0, 500);
                            $key = core_text::strtolower($suggestion);
                            if ($suggestion !== '' && !isset($cleaned[$key])) {
                                $cleaned[$key] = $suggestion;
                            }
                            if (count($cleaned) >= 3) {
                                break;
                            }
                        }
                    }

                    // Never erase saved suggestions when generation returns no valid items.
                    if (empty($cleaned)) {
                        mtrace('RAG Assistant: no valid suggestions for document ' . $record->id .
                            '; existing suggestions were preserved.');
                    } else {
                        $transaction = $DB->start_delegated_transaction();
                        try {
                            $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $record->id]);
                            foreach ($cleaned as $suggestion) {
                                $DB->insert_record('local_ragassistant_suggestions', (object) [
                                    'courseid' => (int) $record->courseid,
                                    'documentid' => (int) $record->id,
                                    'userid' => (int) $record->userid,
                                    'suggestion' => $suggestion,
                                    'timecreated' => time(),
                                ]);
                            }
                            $transaction->allow_commit();
                            mtrace('RAG Assistant: saved ' . count($cleaned) .
                                ' suggestion(s) for document ' . $record->id . '.');
                        } catch (\Throwable $exception) {
                            $transaction->rollback($exception);
                            throw $exception;
                        }
                    }

                    $DB->update_record('local_ragassistant_documents', (object) [
                        'id' => $record->id,
                        'status' => 'ready',
                        'progress' => 100,
                        'stage' => 'complete',
                        'jobtype' => '',
                        'timemodified' => time(),
                    ]);

                    // Keep at least three starter questions available while the
                    // asynchronous LLM refresh runs. Existing suggestions are kept.
                    if (!$DB->record_exists('local_ragassistant_suggestions', ['documentid' => $record->id])) {
                        $this->save_fallback_suggestions($record);
                    }
                    try {
                        $suggestiontask = new index_document();
                        $suggestiontask->set_component('local_ragassistant');
                        $suggestiontask->set_custom_data([
                            'documentid' => (int) $record->id,
                            'jobtype' => 'suggestions',
                        ]);
                        $DB->set_field('local_ragassistant_documents', 'stage', 'suggestions queued', ['id' => $record->id]);
                        $DB->set_field('local_ragassistant_documents', 'jobtype', 'suggestions', ['id' => $record->id]);
                        \core\task\manager::queue_adhoc_task($suggestiontask);
                    } catch (\Throwable $suggestionexception) {
                        // A suggestion queue failure must not mark valid vectors failed.
                        $DB->set_field('local_ragassistant_documents', 'stage', 'complete (local fallback)', ['id' => $record->id]);
                        mtrace('RAG Assistant: could not queue suggestion refresh for document ' .
                            $record->id . ': ' . $suggestionexception->getMessage());
                    }

                    $success++;
                    mtrace('RAG Assistant: rebuilt document ' . $record->id . ' (' . $record->filename . ').');
                } catch (\Throwable $exception) {
                    $DB->update_record('local_ragassistant_documents', (object) [
                        'id' => $record->id,
                        'status' => 'failed',
                        'timemodified' => time(),
                    ]);
                    $failed++;
                    mtrace('RAG Assistant: rebuild failed for document ' . $record->id . ': ' . $exception->getMessage());
                }
            }
        } finally {
            set_config('reindex_pending', 0, 'local_ragassistant');
        }

        mtrace('RAG Assistant rebuild complete: ' . $success . ' succeeded, ' . $failed . ' failed.');
    }    
    /**
     * Save deterministic starter questions if this document has none yet.
     *
     * @param object $record
     */
    private function save_fallback_suggestions($record): void {
        global $DB;
        $title = trim(pathinfo((string) $record->filename, PATHINFO_FILENAME));
        $title = $title !== '' ? $title : 'this document';
        $questions = [
            'What are the main concepts covered in ' . $title . '?',
            'What important details and examples should I understand from ' . $title . '?',
            'How can I apply the knowledge from ' . $title . ' to a practical situation?',
        ];
        foreach ($questions as $question) {
            $DB->insert_record('local_ragassistant_suggestions', (object) [
                'courseid' => (int) $record->courseid,
                'documentid' => (int) $record->id,
                'userid' => (int) $record->userid,
                'suggestion' => $question,
                'timecreated' => time(),
            ]);
        }
    }

}
