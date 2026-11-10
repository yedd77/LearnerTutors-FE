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
                    mtrace('RAG Assistant: source file missing for document ' . $record->id . '; skipped.');
                    $failed++;
                    continue;
                }

                $DB->set_field('local_ragassistant_documents', 'status', 'processing', ['id' => $record->id]);

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
                    // Remove old-model starter questions even if the new model
                    // cannot produce valid suggestions for this document.
                    $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $record->id]);
                    if (is_array($suggestions) && !empty($suggestions)) {
                        $cleaned = [];
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

                        if ($cleaned) {
                            foreach ($cleaned as $suggestion) {
                                $DB->insert_record('local_ragassistant_suggestions', (object) [
                                    'courseid' => (int) $record->courseid,
                                    'documentid' => (int) $record->id,
                                    'userid' => (int) $record->userid,
                                    'suggestion' => $suggestion,
                                    'timecreated' => time(),
                                ]);
                            }
                        }
                    }

                    $DB->update_record('local_ragassistant_documents', (object) [
                        'id' => $record->id,
                        'status' => 'ready',
                        'timemodified' => time(),
                    ]);
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
}
