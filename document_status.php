<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Lightweight polling endpoint for document background-job progress.
 *
 * @package local_ragassistant
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($courseid);
require_capability('local/ragassistant:useassistant', $context);

global $DB, $USER;
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$documents = $DB->get_records('local_ragassistant_documents', ['courseid' => $courseid], 'id ASC');
$result = [];
$documentids = [];

foreach ($documents as $document) {
    $isowner = (int) $document->userid === (int) $USER->id;
    if (!$canmanage && $document->visibility !== 'course' && !$isowner) {
        continue;
    }
    if (!$canmanage && !$isowner && empty($document->retrievalenabled)) {
        continue;
    }
    $documentids[] = (int) $document->id;
    $result[] = [
        'id' => (int) $document->id,
        'status' => (string) $document->status,
        'progress' => max(0, min(100, (int) ($document->progress ?? 0))),
        'stage' => (string) ($document->stage ?? ''),
        'retrievalenabled' => !empty($document->retrievalenabled),
    ];
}

// Suggestions are exposed only to lecturers who can manage course documents.
$suggestions = [];
if ($canmanage && $documentids) {
    [$insql, $params] = $DB->get_in_or_equal($documentids, SQL_PARAMS_NAMED, 'docid');
    $records = $DB->get_records_select(
        'local_ragassistant_suggestions',
        'documentid ' . $insql,
        $params,
        'documentid ASC, slot ASC'
    );
    foreach ($records as $record) {
        $suggestions[] = [
            'documentid' => (int) $record->documentid,
            'slot' => (int) $record->slot,
            'suggestion' => (string) $record->suggestion,
            'status' => (string) $record->status,
            'timemodified' => (int) ($record->timemodified ?? 0),
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode([
    'documents' => $result,
    'suggestions' => $suggestions,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
