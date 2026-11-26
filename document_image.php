<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Image proxy for figures extracted from course documents and shown in chat answers.
 *
 * The browser loads figures from this page; it fetches the PNG from the backend with the
 * plugin's API key. The backend URL and key never reach the browser, and the backend URL
 * is built here from the settings, never from a URL or path sent by the browser. Access
 * follows the chat page: the user must be logged in and allowed to chat in the course, and
 * the document must belong to the course, be ready, have retrieval enabled and be visible
 * to the user. The image is streamed straight through and never stored in Moodle.
 *
 * Responses: 200 PNG bytes; otherwise an empty 404 (the chat shows "no longer available").
 *
 * @package local_ragassistant
 */

require_once(__DIR__ . '/../../config.php');

use local_ragassistant\local\course_policy;

/**
 * Send an empty "not found" response, and stop. Never includes backend error text.
 */
function local_ragassistant_image_not_found(): void {
    http_response_code(404);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);

// Same rule as the chat page and the study sheet proxy.
if (!course_policy::can_use_chat($courseid, $context)) {
    local_ragassistant_image_not_found();
}

$imageid = required_param('id', PARAM_ALPHANUM);
$documentid = required_param('documentid', PARAM_INT);
if (!preg_match('/^[0-9a-f]{32}$/', $imageid) || $documentid <= 0) {
    local_ragassistant_image_not_found();
}

// The document must belong to this course and be searchable: the same conditions the chat
// page uses for suggestions (ready, retrieval enabled, visible to this user). The backend
// checks the course and document again; this check does not rely on it.
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$document = $DB->get_record('local_ragassistant_documents', ['id' => $documentid, 'courseid' => $courseid]);
// A past exam paper is never shown to students (only its structure is used, for /exam).
if (!$document || $document->status !== 'ready' || empty($document->retrievalenabled) ||
        \local_ragassistant\local\past_exam::is_past_exam($document) ||
        ($document->visibility !== 'course' && (int) $document->userid !== (int) $USER->id && !$canmanage)) {
    local_ragassistant_image_not_found();
}

// Release the session lock while waiting on the backend.
\core\session\manager::write_close();

try {
    $image = (new \local_ragassistant\local\api_client())
        ->get_document_image($imageid, (int) $course->id, (int) $document->id);
} catch (\Throwable $exception) {
    // Log only the reason, never the backend URL, key or image bytes.
    // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
    error_log('local_ragassistant: document image request failed: ' . get_class($exception));
    local_ragassistant_image_not_found();
}

// Only a real PNG is passed on: content type and file signature.
if ($image['status'] !== 200 || $image['contenttype'] !== 'image/png' ||
        strncmp($image['body'], "\x89PNG\r\n\x1a\n", 8) !== 0) {
    if ($image['status'] !== 404) {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('local_ragassistant: document image returned HTTP ' . $image['status']);
    }
    local_ragassistant_image_not_found();
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($image['body']));
// Not cached, like the backend's own response: a figure must disappear as soon as its
// document is disabled for retrieval or deleted, even on a quick reload.
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="figure.png"');
echo $image['body'];
exit;
