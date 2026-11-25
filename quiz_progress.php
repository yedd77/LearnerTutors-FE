<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Save the progress of a /quiz attempt so a page reload continues where the student was.
 *
 * chat.js posts the whole attempt state after every step. It is stored in the user's own
 * chat session history, next to the quiz it belongs to (found by the quiz id index.php gave
 * it). Nothing is sent to the backend and nothing is stored in the database.
 *
 * POST: courseid, sesskey, quizid, progress (JSON). Responds with JSON {success: bool}.
 *
 * @package local_ragassistant
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_ragassistant\local\course_policy;
use local_ragassistant\local\quiz_progress;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($course->id);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

/**
 * Send the JSON result and stop.
 *
 * @param bool $success
 */
function local_ragassistant_quiz_progress_done(bool $success): void {
    if (!$success) {
        http_response_code(400);
    }
    echo json_encode(['success' => $success]);
    exit;
}

// Same rule as the chat page.
if (!course_policy::can_use_chat($courseid, $context)) {
    local_ragassistant_quiz_progress_done(false);
}

$quizid = optional_param('quizid', '', PARAM_ALPHANUM);
$raw = optional_param('progress', '', PARAM_RAW);
if (!quiz_progress::valid_id($quizid) || $raw === '' || strlen($raw) > 8192) {
    local_ragassistant_quiz_progress_done(false);
}

$history =& $SESSION->local_ragassistant_history[$courseid];
if (!is_array($history)) {
    local_ragassistant_quiz_progress_done(false);
}

foreach ($history as $index => $turn) {
    $quiz = $turn['quiz'] ?? null;
    if (!is_array($quiz) || ($quiz['id'] ?? '') !== $quizid) {
        continue;
    }
    $progress = quiz_progress::clean(json_decode($raw, true), count($quiz['questions'] ?? []));
    if ($progress === null) {
        local_ragassistant_quiz_progress_done(false);
    }
    $history[$index]['quiz_progress'] = $progress;
    local_ragassistant_quiz_progress_done(true);
}

// The quiz is no longer in the history (cleared, or trimmed to the last 20 turns).
local_ragassistant_quiz_progress_done(false);
