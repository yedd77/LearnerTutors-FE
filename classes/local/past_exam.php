<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Past exam papers: documents whose structure (not their questions) shapes /exam papers.
 *
 * The backend reads a past paper's sections, question types and marks, and never makes
 * the paper searchable. Moodle keeps the role on the document record and sends it with
 * every index request, including reindexing.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers shared by the upload page and the indexing tasks.
 */
class past_exam {
    /** Role of ordinary course documents (the column default). */
    public const COURSE_MATERIAL = 'course_material';

    /** Role of a past exam paper. */
    public const ROLE = 'past_exam';

    /**
     * Whether a document record is a past exam paper.
     *
     * @param \stdClass $record local_ragassistant_documents record
     * @return bool
     */
    public static function is_past_exam(\stdClass $record): bool {
        return ($record->documentrole ?? self::COURSE_MATERIAL) === self::ROLE;
    }

    /**
     * The role to send to the backend for a document record.
     *
     * @param \stdClass $record
     * @return string
     */
    public static function role_of(\stdClass $record): string {
        return self::is_past_exam($record) ? self::ROLE : self::COURSE_MATERIAL;
    }

    /**
     * Store the outcome of indexing a past exam paper.
     *
     * Checks that the backend really treated the file as a past paper. A backend without
     * past paper support ignores document_role and indexes the paper as course material,
     * which would make its questions searchable in chat: the document is then removed from
     * the backend again and the job fails. Otherwise the detected structure is saved for
     * the lecturer, and any starter suggestions are removed (past papers have none, as they
     * could reveal its questions).
     *
     * @param api_client $api
     * @param \stdClass $record local_ragassistant_documents record
     * @param array $result Backend response of POST /api/v1/documents/index
     * @throws \moodle_exception when the backend did not store the paper as a past paper
     */
    public static function save_index_result(api_client $api, \stdClass $record, array $result): void {
        global $DB;

        if (($result['document_role'] ?? '') !== self::ROLE) {
            try {
                $api->delete_document((int) $record->id, (int) $record->courseid);
            } catch (\Throwable $exception) {
                mtrace('RAG Assistant: could not remove past paper ' . $record->id .
                    ' that was indexed as course material: ' . $exception->getMessage());
            }
            throw new \moodle_exception(
                'backendrequestfailed',
                'local_ragassistant',
                '',
                get_string('pastexamunsupported', 'local_ragassistant')
            );
        }

        $description = '';
        if (is_array($result['exam_blueprint'] ?? null) && is_string($result['exam_blueprint']['description'] ?? null)) {
            // Plain text with one line per section; shown with s() and line breaks.
            $description = \core_text::substr(trim(clean_param($result['exam_blueprint']['description'], PARAM_TEXT)), 0, 5000);
        }

        $DB->set_field(
            'local_ragassistant_documents',
            'examstructure',
            $description !== '' ? $description : null,
            ['id' => $record->id]
        );
        $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $record->id]);
    }
}
