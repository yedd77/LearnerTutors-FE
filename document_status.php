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

foreach ($documents as $document) {
    $isowner = (int) $document->userid === (int) $USER->id;
    if (!$canmanage && $document->visibility !== 'course' && !$isowner) {
        continue;
    }
    if (!$canmanage && !$isowner && empty($document->retrievalenabled)) {
        continue;
    }
    $result[] = [
        'id' => (int) $document->id,
        'status' => (string) $document->status,
        'progress' => max(0, min(100, (int) ($document->progress ?? 0))),
        'stage' => (string) ($document->stage ?? ''),
        'retrievalenabled' => !empty($document->retrievalenabled),
    ];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode(['documents' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
