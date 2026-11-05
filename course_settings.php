<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/course_policy.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/ragassistant:managedocuments', $context);

$PAGE->set_url(new moodle_url('/local/ragassistant/course_settings.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('coursepolicytitle', 'local_ragassistant'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/ragassistant/styles.css'));

if (data_submitted() && confirm_sesskey()) {
    $allowchat = (bool)optional_param('allowstudentchat', 0, PARAM_BOOL);
    $allowupload = (bool)optional_param('allowstudentupload', 0, PARAM_BOOL);
    \local_ragassistant\local\course_policy::save($courseid, $allowchat, $allowupload);
    redirect($PAGE->url, get_string('policysaved', 'local_ragassistant'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$policy = \local_ragassistant\local\course_policy::get($courseid);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursepolicytitle', 'local_ragassistant'));
echo $OUTPUT->notification(get_string('coursepolicyhelp', 'local_ragassistant'), 'info');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'ragassistant-policy-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'allowstudentchat', 'value' => '1', 'checked' => $policy->allowstudentchat ? 'checked' : null]) . ' ' . get_string('allowstudentchat', 'local_ragassistant'), ['class' => 'ragassistant-policy-option']);
echo html_writer::tag('p', get_string('allowstudentchat_desc', 'local_ragassistant'), ['class' => 'text-muted']);
echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'allowstudentupload', 'value' => '1', 'checked' => $policy->allowstudentupload ? 'checked' : null]) . ' ' . get_string('allowstudentupload', 'local_ragassistant'), ['class' => 'ragassistant-policy-option']);
echo html_writer::tag('p', get_string('allowstudentupload_desc', 'local_ragassistant'), ['class' => 'text-muted']);
echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::div(html_writer::link(new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]), get_string('backtodashboard', 'local_ragassistant')), 'mt-3');
echo $OUTPUT->footer();
