<?php
// Main page

require_once(__DIR__ . '/../../config.php');
use local_ragassistant\local\course_policy;

// Input handling and access control setup.
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$policy = course_policy::get($courseid);

// Check user capabilities before allowing dashboard access.
$canManage = has_capability(
    'local/ragassistant:managedocuments',
    $context
);

$canUseChat = has_capability(
    'local/ragassistant:useassistant',
    $context
);

// Allow users who can manage documents OR use the assistant.
if (!$canManage && !$canUseChat) {
    require_capability('local/ragassistant:useassistant', $context);
}

// Page setup and metadata.
$PAGE->set_url(
    new moodle_url('/local/ragassistant/index.php', [
        'courseid' => $courseid,
    ])
);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('pluginname', 'local_ragassistant'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(
    new moodle_url('/local/ragassistant/styles.css')
);

// Check permissions for uploads and chat.
$canUploadPersonal = has_capability(
    'local/ragassistant:uploadpersonal',
    $context
);

// Lecturers who can manage documents can upload regardless of student policy.
$uploadAllowed = $canManage ||
    ($canUploadPersonal && !empty($policy->allowstudentupload));

// Managers can use chat regardless of student policy, provided they also
// have the useassistant capability.
$chatAllowed = $canUseChat &&
    ($canManage || !empty($policy->allowstudentchat));

// Render the dashboard.
echo $OUTPUT->header();
echo $OUTPUT->heading(
    get_string('dashboardheading', 'local_ragassistant')
);

// Start dashboard grid.
$cards = html_writer::start_div('ragassistant-grid');

// Upload materials card.
$cards .= html_writer::start_div('ragassistant-card');
$cards .= html_writer::tag(
    'h3',
    get_string('uploadCardTitle', 'local_ragassistant')
);
$cards .= html_writer::tag(
    'p',
    get_string('uploadCardDesc', 'local_ragassistant')
);

// Display the upload link or a disabled button.
if ($uploadAllowed) {
    $cards .= html_writer::link(
        new moodle_url('/local/ragassistant/documents.php', [
            'courseid' => $courseid,
        ]),
        get_string('uploadButton', 'local_ragassistant'),
        ['class' => 'btn btn-primary']
    );
} else {
    $cards .= html_writer::tag(
        'button',
        get_string('uploadButton', 'local_ragassistant'),
        [
            'class' => 'btn btn-primary',
            'disabled' => 'disabled',
            'title' => 'You do not have permission to upload documents, '
                . 'or student uploads are disabled for this course.',
        ]
    );

    $cards .= html_writer::tag(
        'p',
        'You do not have permission to upload documents, or your lecturer '
            . 'has disabled student document uploads.',
        ['class' => 'text-muted small']
    );
}
$cards .= html_writer::end_div();
    
// AI chat card.
$cards .= html_writer::start_div('ragassistant-card');
$cards .= html_writer::tag(
    'h3',
    get_string('askassistant', 'local_ragassistant')
);
$cards .= html_writer::tag(
    'p',
    get_string('chatintro', 'local_ragassistant')
);

// Display the chat link or a disabled button.
if ($chatAllowed) {
    $cards .= html_writer::link(
        new moodle_url('/local/ragassistant/chat.php', [
            'courseid' => $courseid,
        ]),
        get_string('openchat', 'local_ragassistant'),
        ['class' => 'btn btn-primary']
    );
} else {
    $cards .= html_writer::tag(
        'button',
        get_string('openchat', 'local_ragassistant'),
        [
            'class' => 'btn btn-primary',
            'disabled' => 'disabled',
            'title' => 'You do not have permission to use AI chat, '
                . 'or AI chat is disabled for students in this course.',
        ]
    );

    $cards .= html_writer::tag(
        'p',
        'You do not have permission to use AI chat, or your lecturer '
            . 'has disabled AI chat for this course.',
        ['class' => 'text-muted small']
    );
}

$cards .= html_writer::end_div();

// End dashboard grid and render page footer.
$cards .= html_writer::end_div();

// Course settings card for users who can manage documents.
if ($canManage) {
    $cards .= html_writer::start_div('ragassistant-card');
    $cards .= html_writer::tag(
        'h3',
        get_string('settingCardTitle', 'local_ragassistant')
    );
    $cards .= html_writer::tag(
        'p',
        get_string('settingCardDesc', 'local_ragassistant')
    );
    $cards .= html_writer::link(
        new moodle_url('/local/ragassistant/course_settings.php', [
            'courseid' => $courseid,
        ]),
        get_string('coursepolicytitle', 'local_ragassistant'),
        ['class' => 'btn btn-warning']
    );
    $cards .= html_writer::end_div();
}

echo $cards;
echo $OUTPUT->footer();