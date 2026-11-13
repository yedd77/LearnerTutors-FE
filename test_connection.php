<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/api_client.php');

require_login();
$context = context_system::instance();
admin_externalpage_setup('local_ragassistant_testconnection');
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/ragassistant/test_connection.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('testconnection', 'local_ragassistant'));
$PAGE->set_heading(get_string('testconnectionheading', 'local_ragassistant'));

$testrequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$success = false;
$error = '';

if ($testrequested) {
    require_sesskey();

    try {
        $client = new \local_ragassistant\local\api_client();
        $result = $client->test_connection();
        $success = !empty($result['authenticated']) && ($result['status'] ?? '') === 'ok';

        if (!$success) {
            $error = get_string('backendinvalidresponse', 'local_ragassistant');
        }
    } catch (\Throwable $exception) {
        // Do not display request headers, credentials, or raw transport details.
        debugging('FastAPI connection test failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        $error = get_string('backendconnectionfailed', 'local_ragassistant');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testconnectionheading', 'local_ragassistant'));
echo html_writer::tag('p', get_string('testconnectionintro', 'local_ragassistant'));

if ($success) {
    echo $OUTPUT->notification(
        get_string('testconnectionsuccess', 'local_ragassistant'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else if ($testrequested) {
    echo $OUTPUT->notification(
        $error,
        \core\output\notification::NOTIFY_ERROR
    );
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::tag('button', get_string('testconnection', 'local_ragassistant'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
