<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/api_client.php');

$courseid = required_param('courseid', PARAM_INT);
$requestid = required_param('request_id', PARAM_ALPHANUMEXT);
$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($course->id);
require_capability('local/ragassistant:useassistant', $context);

if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $requestid)) {
    throw new invalid_parameter_exception('Invalid chat request ID.');
}

header('Content-Type: application/json; charset=utf-8');
try {
    $client = new \local_ragassistant\local\api_client();
    $result = $client->cancel_chat($requestid);
    echo json_encode([
        'success' => true,
        'status' => $result['status'] ?? 'unknown',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $exception) {
    debugging('RAG Assistant cancellation failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'The server could not confirm cancellation.']);
}
