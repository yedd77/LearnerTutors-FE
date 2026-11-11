<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Index one Moodle document in the background and generate its starter suggestions.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Process one uploaded or manually re-indexed document.
 */
class index_document extends \core\task\adhoc_task {
    /**
     * Execute background indexing.
     */
    public function execute(): void {
        global $DB;

        $customdata = $this->get_custom_data();
        $documentid = (int) ($customdata->documentid ?? 0);
        if ($documentid <= 0) {
            mtrace('RAG Assistant: indexing task has no valid document ID.');
            return;
        }

        $record = $DB->get_record('local_ragassistant_documents', ['id' => $documentid]);
        if (!$record) {
            mtrace('RAG Assistant: document ' . $documentid . ' no longer exists; skipping task.');
            return;
        }

        $fs = get_file_storage();
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
                'id' => $documentid, 'status' => 'failed', 'timemodified' => time(),
            ]);
            mtrace('RAG Assistant: source file missing for document ' . $documentid . '.');
            return;
        }

        $DB->update_record('local_ragassistant_documents', (object) [
            'id' => $documentid, 'status' => 'processing', 'timemodified' => time(),
        ]);

        try {
            $api = new \local_ragassistant\local\api_client();
            $result = $api->index_file(
                $stored,
                $documentid,
                (int) $record->courseid,
                (int) $record->userid,
                (string) $record->visibility,
                !empty($record->retrievalenabled)
            );
            $saved = $this->save_suggestions(
                $documentid,
                (int) $record->courseid,
                (int) $record->userid,
                $result['suggestions'] ?? []
            );
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $documentid, 'status' => 'ready', 'timemodified' => time(),
            ]);
            mtrace('RAG Assistant: indexed document ' . $documentid . ' (' .
                (int) ($result['indexed_chunks'] ?? 0) . ' chunks); saved ' . $saved . ' suggestion(s).');
        } catch (\Throwable $exception) {
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $documentid, 'status' => 'failed', 'timemodified' => time(),
            ]);
            mtrace('RAG Assistant: indexing failed for document ' . $documentid . ': ' .
                $exception->getMessage());
            // Mark failed and allow a lecturer to queue it again; avoid automatic retry storms.
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
            $suggestion = \core_text::substr(trim(clean_param($suggestion, PARAM_TEXT)), 0, 500);
            $key = \core_text::strtolower($suggestion);
            if ($suggestion !== '' && !isset($cleaned[$key])) {
                $cleaned[$key] = $suggestion;
            }
            if (count($cleaned) >= 3) {
                break;
            }
        }
        // Preserve previously saved suggestions if generation returned no valid items.
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
        }
        return count($cleaned);
    }
}
