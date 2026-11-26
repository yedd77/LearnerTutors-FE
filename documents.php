<?php
// Upload and manage documents page

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/form/document_upload_form.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/api_client.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/course_policy.php');

/**
 * Replace the saved starter suggestions for one indexed document.
 *
 * @param int $courseid
 * @param int $documentid
 * @param int $userid
 * @param mixed $suggestions
 */
function local_ragassistant_save_document_suggestions(
    int $courseid,
    int $documentid,
    int $userid,
    $suggestions
): int {
    global $DB;
    if (!is_array($suggestions) || !$suggestions) {
        return 0;
    }
    $cleaned = [];
    foreach ($suggestions as $suggestion) {
        if (!is_string($suggestion)) {
            continue;
        }
        $suggestion = core_text::substr(trim(clean_param($suggestion, PARAM_TEXT)), 0, 500);
        $key = core_text::strtolower($suggestion);
        if ($suggestion !== '' && !isset($cleaned[$key])) {
            $cleaned[$key] = $suggestion;
        }
        if (count($cleaned) >= 3) {
            break;
        }
    }
    if (!$cleaned) {
        return 0;
    }
    // Keep the transaction limited to database writes. Never log after allow_commit()
    // inside this try block: a post-commit exception cannot be rolled back.
    $transaction = $DB->start_delegated_transaction();
    try {
        $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $documentid]);
        $slot = 0;
        foreach ($cleaned as $suggestion) {
            $slot++;
            $DB->insert_record('local_ragassistant_suggestions', (object) [
                'courseid' => $courseid,
                'documentid' => $documentid,
                'userid' => $userid,
                'slot' => $slot,
                'suggestion' => $suggestion,
                'status' => 'ready',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        $transaction->rollback($exception);
    }
    return count($cleaned);
}

/**
 * Queue a durable Moodle background task for document processing.
 *
 * @param int $documentid
 * @param string $jobtype index, reindex or suggestions
 * @return bool Whether a new task was queued.
 */
function local_ragassistant_queue_document_task(int $documentid, string $jobtype = 'index'): bool
{
    global $DB;

    if (!in_array($jobtype, ['index', 'reindex', 'suggestions'], true)) {
        throw new coding_exception('Invalid document task type.');
    }

    $document = $DB->get_record('local_ragassistant_documents', ['id' => $documentid], '*', MUST_EXIST);
    if ($jobtype === 'suggestions') {
        if (!in_array($document->status, ['ready', 'failed'], true) ||
                in_array((string) ($document->stage ?? ''), ['suggestions queued', 'generating suggestions'], true)) {
            return false;
        }
    } else if (
        $document->status === 'processing' ||
        ($document->status === 'pending' && ($document->stage ?? '') === 'queued')
    ) {
        return false;
    }

    $task = new \local_ragassistant\task\index_document();
    $task->set_component('local_ragassistant');
    $task->set_custom_data([
        'documentid' => $documentid,
        'jobtype' => $jobtype,
    ]);

    // Suggestion generation is independent from document indexing. Keep a
    // successfully indexed document ready while suggestions are generated.
    if ($jobtype === 'suggestions') {
        $DB->update_record('local_ragassistant_documents', (object) [
            'id' => $documentid,
            'stage' => 'suggestions queued',
            'jobtype' => 'suggestions',
            'timemodified' => time(),
        ]);
    } else {
        // Mark the document queued before exposing the task to a cron worker.
        $DB->update_record('local_ragassistant_documents', (object) [
            'id' => $documentid,
            'status' => 'pending',
            'progress' => 0,
            'stage' => 'queued',
            'jobtype' => $jobtype,
            'timemodified' => time(),
        ]);
    }

    // The adhoc task is persisted in Moodle's task queue and picked up by cron.
    try {
        \core\task\manager::queue_adhoc_task($task);
    } catch (\Throwable $exception) {
        if ($jobtype === 'suggestions') {
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $documentid,
                'stage' => 'suggestions failed',
                'timemodified' => time(),
            ]);
        } else {
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $documentid,
                'status' => 'failed',
                'progress' => 100,
                'stage' => 'queue failed',
                'jobtype' => '',
                'timemodified' => time(),
            ]);
        }
        throw $exception;
    }
    return true;
}


/**
 * Render a document action form.
 *
 * @param int $courseid
 * @param int $documentid
 * @param string $action
 * @param string $label
 * @param string $class
 * @param string|null $onclick
 * @param array $extra
 * @param bool $disabled
 * @param string|null $tooltip Optional hover text for the button.
 * @return string
 */
function local_ragassistant_document_action_form(
    int $courseid,
    int $documentid,
    string $action,
    string $label,
    string $class,
    ?string $onclick = null,
    array $extra = [],
    bool $disabled = false,
    ?string $tooltip = null
): string {
    $attributes = [
        'type' => 'submit',
        'class' => $class,
    ];
    if ($tooltip !== null) {
        $attributes['title'] = $tooltip;
    }
    if ($onclick !== null) {
        $attributes['onclick'] = $onclick;
    }
    if ($disabled) {
        $attributes['disabled'] = 'disabled';
        $attributes['aria-disabled'] = 'true';
    }

    $extrafields = '';
    foreach ($extra as $name => $value) {
        $extrafields .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }

    $content =
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'documentid', 'value' => $documentid]) .
        $extrafields .
        html_writer::tag('button', $label, $attributes);

    return html_writer::tag(
        'form',
        $content,
        [
            'method' => 'post',
            'action' => (new moodle_url('/local/ragassistant/documents.php'))->out(false),
            'class' => 'ragassistant-document-action',
        ]
    );
}

// Get access control and course information
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$PAGE->requires->css('/local/ragassistant/styles.css');
$context = context_course::instance($course->id);
// Course material management is for educators only. Students have nothing to upload
// (personal uploads were removed), so they must not reach this page by URL either.
// Send them back to the chat with a notice rather than throwing a permission exception,
// which on sites with developer debugging shows a full debug page with request data.
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$canreindex = has_capability('local/ragassistant:reindexdocuments', $context);
if (!$canmanage) {
    redirect(   
        new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]),
        get_string('nomanagedocuments', 'local_ragassistant'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}
$policy = \local_ragassistant\local\course_policy::get($courseid);
// Only educators who manage documents can upload. Student (personal) uploads were removed
// because the backend no longer indexes them.
$canupload = $canmanage;
$api = new \local_ragassistant\local\api_client();

// Page setup and metadata
$PAGE->set_url(new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('uploadCardTitle', 'local_ragassistant'));
$PAGE->set_heading(format_string($course->fullname));

// Handle document actions
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$actiondocumentid = optional_param('documentid', 0, PARAM_INT);

// Save the course chat policy from the settings card at the bottom of the page.
if ($action === 'savepolicy' && data_submitted()) {
    require_sesskey();
    require_capability('local/ragassistant:managedocuments', $context);
    $allowchat = (bool) optional_param('allowstudentchat', 0, PARAM_BOOL);
    // The /exam setting is only sent when it was shown (examoption). It is saved only while
    // it applies; otherwise the saved value is kept for when it applies again.
    $allowexam = null;
    if (optional_param('examoption', 0, PARAM_BOOL) && $allowchat &&
            get_config('local_ragassistant', 'enable_chat')) {
        $allowexam = (bool) optional_param('allowstudentexam', 0, PARAM_BOOL);
    }
    \local_ragassistant\local\course_policy::save($courseid, $allowchat, $allowexam);
    redirect(
        new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]),
        get_string('policysaved', 'local_ragassistant'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action !== '' && $actiondocumentid > 0 && data_submitted()) {
    require_sesskey();

    // Fetch target document
    $target = $DB->get_record(
        'local_ragassistant_documents',
        ['id' => $actiondocumentid, 'courseid' => $courseid],
        '*',
        MUST_EXIST
    );

    // Only educators who manage documents can act on them (student uploads were removed).
    require_capability('local/ragassistant:managedocuments', $context);

    // prepare redirect URL for after action
    $redirecturl = new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]);
    $targetispastexam = \local_ragassistant\local\past_exam::is_past_exam($target);

    // Perform the requested action
    try {
        // Past exam papers have no starter suggestions: they could reveal the paper's questions.
        if ($targetispastexam && in_array($action, ['regenerate_suggestions', 'generate_suggestion'], true)) {
            throw new moodle_exception('invalidrequest', 'error');
        }

        $fs = get_file_storage();

        // Get the stored file for the target document
        $targetfile = $fs->get_file(
            $target->contextid,
            'local_ragassistant',
            'documents',
            $target->fileitemid,
            $target->filepath,
            $target->filename
        );

        // Handle the action based on the requested type
        switch ($action) {

            // Queue indexing/reindexing without holding the Moodle request open.
            // Queue indexing/reindexing without holding the Moodle request open.
            case 'index':
            case 'reindex':
            case 'regenerate_suggestions':

                if (!$targetfile) {
                    throw new moodle_exception('storedfilemissing', 'local_ragassistant');
                }

                if ($action === 'index' && !in_array($target->status, ['pending', 'failed'], true)) {
                    throw new moodle_exception('invalidrequest', 'error');
                }

                // Reindexing is limited to roles above teacher.
                if ($action === 'reindex') {
                    require_capability('local/ragassistant:reindexdocuments', $context);
                }

                if ($action === 'reindex' && !in_array($target->status, ['ready', 'failed'], true)) {
                    throw new moodle_exception('invalidrequest', 'error');
                }

                if ($action === 'regenerate_suggestions' && !in_array($target->status, ['ready', 'failed'], true)) {
                    throw new moodle_exception('invalidrequest', 'error');
                }

                // Map the UI action to the background task job type.
                $jobtype = $action === 'regenerate_suggestions' ? 'suggestions' : $action;

                if (!local_ragassistant_queue_document_task((int) $target->id, $jobtype)) {
                    redirect(
                        $redirecturl,
                        'This document already has a background task queued or running.',
                        null,
                        \core\output\notification::NOTIFY_INFO
                    );
                }

                redirect(
                    $redirecturl,
                    $jobtype === 'suggestions'
                    ? 'Suggestion regeneration queued. You can continue using Moodle.'
                    : 'Document indexing queued. You can continue using Moodle.',
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
                break;

            // Queue generation for one suggestion slot without re-indexing the document.
            case 'generate_suggestion':
                require_capability('local/ragassistant:managedocuments', $context);
                if ($target->status !== 'ready' || !$targetfile ||
                        in_array((string) ($target->stage ?? ''), ['suggestions queued', 'generating suggestions'], true)) {
                    throw new moodle_exception('invalidrequest', 'error');
                }
                $slot = required_param('slot', PARAM_INT);
                if ($slot < 1 || $slot > 3) {
                    throw new moodle_exception('invalidrequest', 'error');
                }
                $existing = $DB->get_record('local_ragassistant_suggestions', [
                    'documentid' => $target->id,
                    'slot' => $slot,
                ]);
                if ($existing && $existing->status === 'generating') {
                    redirect($redirecturl, 'This suggestion is already being generated.', null,
                        \core\output\notification::NOTIFY_INFO);
                }
                $suggestionrecord = (object) [
                    'courseid' => $target->courseid,
                    'documentid' => $target->id,
                    'userid' => $target->userid,
                    'slot' => $slot,
                    'suggestion' => $existing ? $existing->suggestion : '',
                    'status' => 'generating',
                    'timecreated' => $existing ? $existing->timecreated : time(),
                    'timemodified' => time(),
                ];
                if ($existing) {
                    $suggestionrecord->id = $existing->id;
                    $DB->update_record('local_ragassistant_suggestions', $suggestionrecord);
                } else {
                    $DB->insert_record('local_ragassistant_suggestions', $suggestionrecord);
                }

                $task = new \local_ragassistant\task\index_document();
                $task->set_component('local_ragassistant');
                $task->set_custom_data([
                    'documentid' => (int) $target->id,
                    'jobtype' => 'suggestion_slot',
                    'slot' => $slot,
                ]);
                try {
                    \core\task\manager::queue_adhoc_task($task);
                } catch (\Throwable $exception) {
                    $current = $DB->get_record('local_ragassistant_suggestions', [
                        'documentid' => $target->id,
                        'slot' => $slot,
                    ]);
                    $state = $current && trim((string) $current->suggestion) !== '' ? 'ready' : 'failed';
                    $DB->set_field('local_ragassistant_suggestions', 'status', $state, [
                        'documentid' => $target->id,
                        'slot' => $slot,
                    ]);
                    throw $exception;
                }
                redirect($redirecturl, 'Suggestion ' . $slot . ' generation queued.', null,
                    \core\output\notification::NOTIFY_SUCCESS);
                break;

            // Handle 'retrival' action
            case 'retrieval':

                // Do not allow a document still being proccess to be toggled
                if ($target->status !== 'ready') {
                    throw new moodle_exception('invalidrequest', 'error');
                }

                // Use the state the button promised, not a blind toggle, so a stale page
                // or double click cannot flip the document the wrong way. The backend is
                // always updated, which also repairs any Moodle/ChromaDB mismatch.
                $newenabled = (bool) required_param('enable', PARAM_BOOL);
                $result = $api->set_document_retrieval((int) $target->id, $newenabled);
                $chunks = (int) ($result['updated_chunks'] ?? 0);

                // Update Moodle record to reflect new retrieval status
                $DB->update_record('local_ragassistant_documents', (object) [
                    'id' => $target->id,
                    'retrievalenabled' => (int) $newenabled,
                    'timemodified' => time(),
                ]);

                // A past exam paper has no indexed chunks: the toggle decides whether
                // /exam papers follow its structure.
                if ($targetispastexam) {
                    redirect(
                        $redirecturl,
                        get_string($newenabled ? 'pastexamenabled' : 'pastexamdisabled', 'local_ragassistant'),
                        null,
                        \core\output\notification::NOTIFY_SUCCESS
                    );
                }

                // Redirect with success message
                $message = ($newenabled ? 'Document retrieval enabled' : 'Document retrieval disabled') .
                    " ($chunks indexed chunks updated).";
                if ($chunks === 0) {
                    $message .= ' No indexed content was found in the search index for this document;'
                        . ' delete it and upload it again.';
                }
                redirect(
                    $redirecturl,
                    $message,
                    null,
                    $chunks > 0
                    ? \core\output\notification::NOTIFY_SUCCESS
                    : \core\output\notification::NOTIFY_WARNING
                );
                break;

            // Handle 'delete' action
            case 'delete':
                // Deletion permanently removes vectors and the Moodle file.
                // Delete backend vectors first so failures leave the local
                // document available for another attempt.    
                $api->delete_document((int) $target->id, (int) $target->courseid);
                if ($targetfile) {
                    $targetfile->delete();
                }
                $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $target->id]);
                $DB->delete_records('local_ragassistant_documents', ['id' => $target->id]);
                redirect(
                    $redirecturl,
                    'Document and indexed content deleted.',
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
                break;

            default:
                throw new moodle_exception('invalidrequest', 'error');
        }

        // Handle exeptions
    } catch (\Throwable $exception) {
        debugging('RAG Assistant document action failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        // Managers see the real reason; other users only get the generic message.
        $reason = $canmanage ? ' Reason: ' . s($exception->getMessage()) : '';
        redirect(
            $redirecturl,
            'The document action failed. Check the backend and Moodle logs.' . $reason,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// upload form
$mform = new \local_ragassistant\form\document_upload_form(
    null,
    ['courseid' => $courseid, 'contextid' => $context->id, 'canmanage' => $canmanage]
);
// Past exam papers have their own upload card. Moodle tells the two forms apart by their
// class, so only the submitted one returns data.
// The card says whether /exam currently follows any past paper (ready and switched on).
$pastexamsinuse = $DB->count_records('local_ragassistant_documents', [
    'courseid' => $courseid,
    'documentrole' => \local_ragassistant\local\past_exam::ROLE,
    'status' => 'ready',
    'retrievalenabled' => 1,
]);
$examform = new \local_ragassistant\form\past_exam_upload_form(
    null,
    [
        'courseid' => $courseid,
        'contextid' => $context->id,
        'canmanage' => $canmanage,
        'pastexamsinuse' => $pastexamsinuse,
    ],
    'post',
    '',
    ['class' => 'ragassistant-exam-upload-form']
);

$data = null;
$documentrole = \local_ragassistant\local\past_exam::COURSE_MATERIAL;
$draftitemid = 0;
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]));
} else if ($data = $mform->get_data()) {
    $draftitemid = (int) ($data->documents ?? 0);
} else if ($data = $examform->get_data()) {
    $draftitemid = (int) ($data->exampapers ?? 0);
    $documentrole = \local_ragassistant\local\past_exam::ROLE;
}

if ($data) {
    require_sesskey();
    if (!$canupload) {
        throw new required_capability_exception($context, 'local/ragassistant:managedocuments', 'nopermissions', '');
    }

    $usercontext = context_user::instance($USER->id);
    $draftfiles = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
    if (!$draftfiles) {
        throw new moodle_exception('uploadfailed', 'local_ragassistant');
    }

    $filenotes = [];
    if ($canmanage && !empty($data->filenotes)) {
        $decodednotes = json_decode((string) $data->filenotes, true);
        if (is_array($decodednotes)) {
            foreach ($decodednotes as $filename => $note) {
                if (is_string($filename) && is_string($note)) {
                    $filenotes[$filename] = clean_param($note, PARAM_TEXT);
                }
            }
        }
    }

    $fs = get_file_storage();
    $successcount = 0;
    $failedcount = 0;
    $messages = [];
    foreach ($draftfiles as $draftfile) {
        $filename = clean_param($draftfile->get_filename(), PARAM_FILE);
        if ($filename === '' || $draftfile->is_directory()) {
            continue;
        }

        // The backend indexes course documents only.
        $visibility = 'course';
        $note = $canmanage ? trim((string) ($filenotes[$draftfile->get_filename()] ?? '')) : '';
        $documentid = 0;
        try {
            $record = (object) [
                'courseid' => $courseid,
                'userid' => $USER->id,
                'contextid' => $context->id,
                'filename' => $filename,
                'filepath' => '/',
                'fileitemid' => 0,
                'status' => 'pending',
                'visibility' => $visibility,
                'documentrole' => $documentrole,
                'lecturernote' => $note,
                'retrievalenabled' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $documentid = $DB->insert_record('local_ragassistant_documents', $record);
            $storedfile = $fs->create_file_from_storedfile((object) [
                'contextid' => $context->id,
                'component' => 'local_ragassistant',
                'filearea' => 'documents',
                'itemid' => $documentid,
                'filepath' => '/',
                'filename' => $filename,
            ], $draftfile);
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $documentid,
                'filepath' => $storedfile->get_filepath(),
                'fileitemid' => $documentid,
                'timemodified' => time(),
            ]);

            if (!local_ragassistant_queue_document_task((int) $documentid, 'index')) {
                throw new moodle_exception('backendrequestfailed', 'local_ragassistant', '', 'Unable to queue indexing task.');
            }
            $successcount++;

        } catch (\Throwable $exception) {
            debugging('RAG Assistant upload failed for ' . $filename . ': ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $failedcount++;
            $messages[] = $filename . ': upload or indexing failed';
            if ($documentid && $DB->record_exists('local_ragassistant_documents', ['id' => $documentid])) {
                $DB->set_field('local_ragassistant_documents', 'status', 'failed', ['id' => $documentid]);
            }
        }
    }

    $summary = $documentrole === \local_ragassistant\local\past_exam::ROLE
        ? "$successcount past exam paper(s) uploaded and queued for structure analysis."
        : "$successcount file(s) uploaded and queued for indexing.";
    if ($failedcount) {
        $summary .= " $failedcount file(s) failed during upload or queueing.";
    }
    if ($messages) {
        $summary .= ' ' . implode('; ', $messages);
    }
    redirect(
        new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]),
        $summary,
        null,
        $failedcount ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Paging requests from the page script ask for the two paged sections only (as JSON),
// so changing page does not rebuild the Moodle header, upload form and footer. Access
// checks above still apply; everything else this script echoes is discarded below.
$fragment = optional_param('fragment', 0, PARAM_BOOL);
if ($fragment) {
    // Read-only from here on, so release the session lock for concurrent requests.
    \core\session\manager::write_close();
    ob_start();
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('uploadCardTitle', 'local_ragassistant'));
    if ($canupload) {
        $mform->display();
        // The past exam paper upload card is shown further down, above its own list.
    } else {
        echo $OUTPUT->notification(get_string('uploadnotpermitted', 'local_ragassistant'), 'warning');
    }
}
$pagedsections = [];

$documents = $DB->get_records('local_ragassistant_documents', ['courseid' => $courseid], 'timecreated DESC');

// Two lists, each in its own card with its own paging: course materials (docpage) and past
// exam papers (exampage). Every paging link keeps the other lists' page numbers.
$docperpage = 4;
$pageparams = [
    'docpage' => optional_param('docpage', 0, PARAM_INT),
    'exampage' => optional_param('exampage', 0, PARAM_INT),
    'sugpage' => optional_param('sugpage', 0, PARAM_INT),
];
$doclists = [
    [
        'sectionid' => 'ragassistant-documents-section',
        'headingid' => 'ragassistant-uploaded-heading',
        'pageparam' => 'docpage',
        'pastexam' => false,
        'title' => get_string('coursematerialslist', 'local_ragassistant'),
        'description' => get_string('manageUploaded', 'local_ragassistant'),
        'empty' => get_string('nodocuments', 'local_ragassistant'),
    ],
    [
        'sectionid' => 'ragassistant-exams-section',
        'headingid' => 'ragassistant-exams-heading',
        'pageparam' => 'exampage',
        'pastexam' => true,
        'title' => get_string('pastexamlist', 'local_ragassistant'),
        'description' => get_string('pastexamlistdesc', 'local_ragassistant'),
        'empty' => get_string('pastexamlistempty', 'local_ragassistant'),
    ],
];

// Each list card is captured here and printed below in page order: course material upload,
// course materials, document suggestions, past exam paper upload, past exam papers, chat settings.
$listcards = [];
foreach ($doclists as $doclist) {
    $listdocuments = array_filter($documents, function ($document) use ($doclist) {
        return \local_ragassistant\local\past_exam::is_past_exam($document) === $doclist['pastexam'];
    });

    ob_start();
    echo html_writer::start_div('ragassistant-uploaded-card');
    echo html_writer::tag(
        'div',
        html_writer::tag('h4', $doclist['title'], ['class' => 'ragassistant-card-title']) .
        html_writer::tag('p', $doclist['description'], ['class' => 'ragassistant-card-description']),
        [
            'id' => $doclist['headingid'],
            'class' => 'ragassistant-card-heading',
        ]
    );

    $table = new html_table();
    $table->attributes['class'] = 'generaltable ragassistant-documents';
    // Past papers have no lecturer note, and their toggle decides whether /exam uses them.
    $table->head = $doclist['pastexam']
        ? [
            get_string('documentname', 'local_ragassistant'),
            get_string('processingstatus', 'local_ragassistant'),
            get_string('pastexamusedcolumn', 'local_ragassistant'),
            get_string('actions', 'local_ragassistant'),
        ]
        : [
            get_string('documentname', 'local_ragassistant'),
            get_string('lecturernote', 'local_ragassistant'),
            get_string('processingstatus', 'local_ragassistant'),
            'Retrieval',
            get_string('actions', 'local_ragassistant'),
        ];

    // Paginate each table: 4 documents per page. Only managers reach this page, so every
    // course document is listed.
    $doctotal = count($listdocuments);
    $pageparam = $doclist['pageparam'];
    $pageparams[$pageparam] = max(0, min($pageparams[$pageparam], max(0, (int) ceil($doctotal / $docperpage) - 1)));
    $pageddocuments = array_slice($listdocuments, $pageparams[$pageparam] * $docperpage, $docperpage, true);

    foreach ($pageddocuments as $document) {
        $isowner = ((int) $document->userid === (int) $USER->id);
        if (!$canmanage && $document->visibility !== 'course' && !$isowner) {
            continue;
        }
        // Students must not see that a disabled course document exists (name or note).
        if (!$canmanage && !$isowner && empty($document->retrievalenabled)) {
            continue;
        }
        $retrievalenabled = !empty($document->retrievalenabled);
        $ispastexam = \local_ragassistant\local\past_exam::is_past_exam($document);
        $active = in_array($document->status, ['pending', 'processing'], true);
        $actions = [];

        if (($canmanage || $isowner) && !$active) {
            if (in_array($document->status, ['pending', 'failed'], true)) {
                $actions[] = local_ragassistant_document_action_form(
                    $courseid,
                    (int) $document->id,
                    'index',
                    get_string('indexdocument', 'local_ragassistant'),
                    'btn btn-outline-secondary btn-sm',
                    null,
                    [],
                    false,
                    get_string('indexdocument_tooltip', 'local_ragassistant')
                );
            } else if ($document->status === 'ready' && $canreindex) {
                $actions[] = local_ragassistant_document_action_form(
                    $courseid,
                    (int) $document->id,
                    'reindex',
                    'Reindex',
                    'btn btn-outline-primary btn-sm',
                    'return confirm("Queue reindexing for this document?");',
                    [],
                    false,
                    get_string('reindexdocument_tooltip', 'local_ragassistant')
                );
            }
            if ($document->status === 'ready' && $ispastexam) {
                // For a past paper the same toggle decides whether /exam follows its structure.
                $actions[] = local_ragassistant_document_action_form(
                    $courseid,
                    (int) $document->id,
                    'retrieval',
                    get_string($retrievalenabled ? 'pastexamdisable' : 'pastexamenable', 'local_ragassistant'),
                    $retrievalenabled ? 'btn btn-outline-warning btn-sm' : 'btn btn-success btn-sm',
                    null,
                    ['enable' => $retrievalenabled ? 0 : 1],
                    false,
                    get_string($retrievalenabled ? 'pastexamdisable_tooltip' : 'pastexamenable_tooltip', 'local_ragassistant')
                );
            } else if ($document->status === 'ready') {
                $actions[] = local_ragassistant_document_action_form(
                    $courseid,
                    (int) $document->id,
                    'retrieval',
                    $retrievalenabled ? 'Disable retrieval' : 'Enable retrieval',
                    $retrievalenabled ? 'btn btn-outline-warning btn-sm' : 'btn btn-success btn-sm',
                    null,
                    ['enable' => $retrievalenabled ? 0 : 1],
                    false,
                    get_string($retrievalenabled ? 'disableretrieval_tooltip' : 'enableretrieval_tooltip', 'local_ragassistant')
                );
            }
            $actions[] = local_ragassistant_document_action_form(
                $courseid,
                (int) $document->id,
                'delete',
                'Delete',
                'btn btn-outline-danger btn-sm',
                'return confirm("Delete this document and its indexed content permanently?");',
                [],
                false,
                get_string('deletedocument_tooltip', 'local_ragassistant')
            );
        } else if ($active) {
            $actions[] = html_writer::tag('button', 'Processing…', [
                'type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm',
                'disabled' => 'disabled',
                'title' => get_string('processingdocument_tooltip', 'local_ragassistant'),
            ]);
        }

        $progress = max(0, min(100, (int) ($document->progress ?? 0)));
        $statuscontent = html_writer::tag('span', ucfirst($document->status), [
            'class' => 'ragassistant-status-label',
        ]);
        if ($active) {
            $statuscontent .= html_writer::div(
                html_writer::div('', 'progress-bar', [
                    'role' => 'progressbar',
                    'style' => 'width:' . $progress . '%',
                    'aria-valuenow' => $progress,
                    'aria-valuemin' => 0,
                    'aria-valuemax' => 100,
                ]),
                'progress ragassistant-document-progress'
            );
            $statuscontent .= html_writer::tag(
                'small',
                s(ucfirst((string) ($document->stage ?: 'queued'))) . ' · ' . $progress . '%',
                ['class' => 'text-muted ragassistant-progress-label']
            );
        }
        if ($document->status === 'failed') {
            // Why indexing failed (set by the index task), so the lecturer can act on it.
            $reason = trim((string) ($document->errormessage ?? ''));
            $statuscontent .= html_writer::tag(
                'small',
                $reason !== '' ? get_string('indexfailedreason', 'local_ragassistant', s($reason))
                    : get_string('indexfailedgeneric', 'local_ragassistant'),
                ['class' => 'text-danger ragassistant-failure-reason']
            );
        }
        $status = html_writer::div($statuscontent, 'ragassistant-processing-status', [
            'data-document-id' => (int) $document->id,
            'data-status' => $document->status,
            'data-progress' => $progress,
            'data-stage' => (string) ($document->stage ?? ''),
        ]);

        $namecell = html_writer::span(
            s($document->filename ?: get_string('pendingfilename', 'local_ragassistant')),
            'ragassistant-document-name'
        );
        if ($ispastexam) {
            $namecell .= html_writer::div(
                html_writer::span(get_string('documentrole_past_exam', 'local_ragassistant'), 'badge badge-info bg-info'),
                'ragassistant-document-role'
            );
            // The structure /exam will follow, as detected by the backend (it cannot be fetched again later).
            $structure = trim((string) ($document->examstructure ?? ''));
            if ($document->status === 'ready' && $structure !== '') {
                // One line per section ("Section A: ...", "Total: ..."): the label is bolded so
                // the sections and totals can be scanned.
                $items = '';
                foreach (preg_split('/\R/', $structure) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('/^([^:]{1,40}):\s*(.+)$/u', $line, $parts)) {
                        $items .= html_writer::tag('li', html_writer::tag('strong', s($parts[1])) . ' ' . s($parts[2]));
                    } else {
                        $items .= html_writer::tag('li', s($line));
                    }
                }
                $namecell .= html_writer::div(
                    html_writer::tag('small', get_string('pastexamstructure', 'local_ragassistant'),
                        ['class' => 'ragassistant-exam-structure-title']) .
                    html_writer::tag('ul', $items, ['class' => 'ragassistant-exam-structure-list']),
                    'ragassistant-exam-structure'
                );
            }
        }

        if ($ispastexam) {
            // Only a ready past paper shapes /exam; a pending or failed one is not used yet.
            $retrievalbadge = $retrievalenabled && $document->status === 'ready'
                ? html_writer::span(get_string('pastexamused', 'local_ragassistant'), 'badge badge-success bg-success')
                : html_writer::span(get_string('pastexamnotused', 'local_ragassistant'), 'badge badge-secondary bg-secondary');
        } else {
            $retrievalbadge = $retrievalenabled
                ? html_writer::span('Enabled', 'badge badge-success bg-success')
                : html_writer::span('Disabled', 'badge badge-secondary bg-secondary');
        }

        $row = [$namecell];
        if (!$doclist['pastexam']) {
            $row[] = trim((string) ($document->lecturernote ?? '')) !== ''
                ? format_text($document->lecturernote, FORMAT_PLAIN, ['para' => false])
                : get_string('notavailable', 'local_ragassistant');
        }
        $row[] = $status;
        $row[] = $retrievalbadge;
        $row[] = html_writer::div(implode('', $actions), 'ragassistant-document-actions');
        $table->data[] = $row;

    }

    if (empty($table->data)) {
        $emptycell = new html_table_cell($doclist['empty']);
        $emptycell->attributes['class'] = 'ragassistant-empty-cell';
        $emptycell->colspan = count($table->head);
        $table->data[] = [$emptycell];
    }
    // The table and its paging bar share a container so the paging script can swap them
    // in place (see $polljs below) instead of reloading the whole page.
    ob_start();
    echo html_writer::start_div('ragassistant-paged-section', ['id' => $doclist['sectionid']]);
    echo html_writer::div(
        html_writer::table($table),
        'table-responsive'
    );
    if ($doctotal > $docperpage) {
        // Keep the other lists on their current page.
        $otherpages = $pageparams;
        unset($otherpages[$pageparam]);
        echo html_writer::div(
            $OUTPUT->paging_bar(
                $doctotal,
                $pageparams[$pageparam],
                $docperpage,
                new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid] + $otherpages),
                $pageparam
            ),
            'ragassistant-docs-paging'
        );
    }
    echo html_writer::end_div(); // End the paged section.
    $pagedsections[$doclist['sectionid']] = ob_get_clean();
    echo $pagedsections[$doclist['sectionid']];
    echo html_writer::end_div();
    $listcards[$doclist['sectionid']] = ob_get_clean();
}
$docpage = $pageparams['docpage'];
$exampage = $pageparams['exampage'];

echo $listcards['ragassistant-documents-section'];

if ($canmanage) {
    $suggestiontable = new html_table();
    $suggestiontable->attributes['class'] = 'generaltable ragassistant-document-suggestions';
    $suggestiontable->head = ['Document', 'Suggestion', 'Status', 'Action'];

    // Past exam papers have no starter suggestions, so they are left out of this table.
    $suggestiondocuments = array_filter($documents, function ($document) {
        return !\local_ragassistant\local\past_exam::is_past_exam($document);
    });

    // Paginate the suggestions table by document (each document has 3 suggestion rows).
    $sugperpage = 4;
    $sugtotal = count($suggestiondocuments);
    $sugpage = optional_param('sugpage', 0, PARAM_INT);
    $sugpage = max(0, min($sugpage, max(0, (int) ceil($sugtotal / $sugperpage) - 1)));

    foreach (array_slice($suggestiondocuments, $sugpage * $sugperpage, $sugperpage, true) as $document) {
        $saved = $DB->get_records('local_ragassistant_suggestions', ['documentid' => $document->id], 'slot ASC');
        $byslot = [];
        foreach ($saved as $saveditem) {
            $byslot[(int) $saveditem->slot] = $saveditem;
        }

        for ($slot = 1; $slot <= 3; $slot++) {
            $item = $byslot[$slot] ?? null;
            $row = [];
            if ($slot === 1) {
                $documentcell = new html_table_cell(s($document->filename));
                $documentcell->rowspan = 3;
                $row[] = $documentcell;
            }
            $hassuggestion = $item && trim((string) $item->suggestion) !== '';
            $suggestiontext = $hassuggestion
                ? html_writer::div(s($item->suggestion), 'ragassistant-suggestion-text')
                : html_writer::span('No suggestion generated yet', 'text-muted');
            $row[] = html_writer::div($suggestiontext, 'ragassistant-suggestion-cell', [
                'data-document-id' => (int) $document->id,
                'data-slot' => $slot,
            ]);

            $status = $item->status ?? 'empty';
            $statuslabels = [
                'empty' => 'Not generated',
                'generating' => 'Generating',
                'ready' => 'Generated',
                'failed' => 'Failed',
            ];
            $statusclass = $status === 'ready' ? 'badge badge-success bg-success'
                : ($status === 'failed' ? 'badge badge-danger bg-danger'
                : ($status === 'generating' ? 'text-info' : 'text-muted'));
            $row[] = html_writer::div(
                html_writer::span($statuslabels[$status] ?? ucfirst($status), $statusclass),
                'ragassistant-suggestion-status',
                ['data-document-id' => (int) $document->id, 'data-slot' => $slot]
            );

            $actioncontent = '';
            if ($document->status === 'ready' && $status !== 'generating' &&
                    !in_array((string) ($document->stage ?? ''), ['suggestions queued', 'generating suggestions'], true)) {
                $actioncontent = local_ragassistant_document_action_form(
                    $courseid,
                    (int) $document->id,
                    'generate_suggestion',
                    $hassuggestion ? 'Regenerate' : 'Generate',
                    'btn btn-outline-primary btn-sm',
                    $hassuggestion ? 'return confirm("Regenerate only this suggestion?");' : null,
                    ['slot' => $slot],
                    false,
                    get_string($hassuggestion ? 'regeneratesuggestion_tooltip' : 'generatesuggestion_tooltip', 'local_ragassistant')
                );
            } else if ($status === 'generating') {
                $actioncontent = html_writer::tag('button', 'Generating…', [
                    'type' => 'button',
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'disabled' => 'disabled',
                    'title' => get_string('generatingsuggestion_tooltip', 'local_ragassistant'),
                ]);
            } else {
                $actioncontent = html_writer::span('Available after indexing', 'text-muted');
            }
            $row[] = html_writer::div($actioncontent, 'ragassistant-suggestion-action', [
                'data-document-id' => (int) $document->id,
                'data-slot' => $slot,
                'data-course-id' => $courseid,
                'data-sesskey' => sesskey(),
                'data-action-url' => (new moodle_url('/local/ragassistant/documents.php', [
                    'courseid' => $courseid,
                ]))->out(false),
                'data-document-ready' => $document->status === 'ready' ? '1' : '0',
            ]);
            $suggestiontable->data[] = $row;
        }
    }

    if (empty($suggestiontable->data)) {
        $emptycell = new html_table_cell(get_string('nogenerateddocuments', 'local_ragassistant'));
        $emptycell->attributes['class'] = 'ragassistant-empty-cell';
        $emptycell->colspan = count($suggestiontable->head);
        $suggestiontable->data[] = [$emptycell];
    }

    echo html_writer::start_div('ragassistant-uploaded-card');
    echo html_writer::tag(
        'div',
        html_writer::tag(
            'h4',
            'Document Suggestions',
            ['class' => 'ragassistant-card-title']
        ) .
        html_writer::tag(
            'p',
            'Generate or regenerate each suggestion independently. Suggestion generation does not re-index documents.',
            ['class' => 'ragassistant-card-description']
        ),
        [
            'id' => 'ragassistant-suggestions-heading',
            'class' => 'ragassistant-card-heading',
        ]
    );
    ob_start();
    echo html_writer::start_div('ragassistant-paged-section', ['id' => 'ragassistant-suggestions-section']);
    echo html_writer::div(
        html_writer::table($suggestiontable),
        'table-responsive'
    );
    if ($sugtotal > $sugperpage) {
        echo html_writer::div(
            $OUTPUT->paging_bar(
                $sugtotal,
                $sugpage,
                $sugperpage,
                new moodle_url('/local/ragassistant/documents.php', [
                    'courseid' => $courseid,
                    'docpage' => $docpage,
                    'exampage' => $exampage,
                ]),
                'sugpage'
            ),
            'ragassistant-docs-paging'
        );
    }
    echo html_writer::end_div(); // End ragassistant-suggestions-section.
    $pagedsections['ragassistant-suggestions-section'] = ob_get_clean();
    echo $pagedsections['ragassistant-suggestions-section'];
    echo html_writer::end_div();
}

// Past exam papers: their upload card, then their list.
if (!$fragment && $canupload) {
    $examform->display();
}
echo $listcards['ragassistant-exams-section'];

if ($canmanage) {
    // Course chat settings card (moved here from the former course settings page).
    echo html_writer::start_div('ragassistant-uploaded-card');
    echo html_writer::tag(
        'div',
        html_writer::tag(
            'h4',
            get_string('chatsettingstitle', 'local_ragassistant'),
            ['class' => 'ragassistant-card-title']
        ) .
        html_writer::tag(
            'p',
            get_string('chatsettingsdesc', 'local_ragassistant'),
            ['class' => 'ragassistant-card-description']
        ),
        [
            'id' => 'ragassistant-chatsettings-heading',
            'class' => 'ragassistant-card-heading',
        ]
    );
    // "Allow students to use /exam": shown while the AI chat is on site-wide, with or
    // without past exam papers (/exam generates questions either way). While student chat
    // is unticked it is hidden (the script below shows it again when the box is ticked),
    // and the saved value is kept.
    $examoption = '';
    if (get_config('local_ragassistant', 'enable_chat')) {
        $examoption = html_writer::div(
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'examoption', 'value' => '1']) .
            html_writer::tag(
                'label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'allowstudentexam',
                    'value' => '1',
                    'checked' => !isset($policy->allowstudentexam) || !empty($policy->allowstudentexam)
                        ? 'checked' : null,
                ]) . ' ' . get_string('allowstudentexam', 'local_ragassistant'),
                ['class' => 'ragassistant-policy-option']
            ) .
            html_writer::tag('p', get_string('allowstudentexam_desc', 'local_ragassistant'), ['class' => 'text-muted']),
            'ragassistant-exam-option',
            ['id' => 'ragassistant-exam-option', 'hidden' => empty($policy->allowstudentchat) ? 'hidden' : null]
        ) .
        html_writer::script('(function() {
            var chat = document.querySelector(".ragassistant-policy-form input[name=\'allowstudentchat\']");
            var option = document.getElementById("ragassistant-exam-option");
            if (!chat || !option) return;
            chat.addEventListener("change", function() { option.hidden = !chat.checked; });
        })();');
    }

    echo html_writer::tag(
        'form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'savepolicy']) .
        html_writer::tag(
            'label',
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'allowstudentchat',
                'value' => '1',
                'checked' => !empty($policy->allowstudentchat) ? 'checked' : null,
            ]) . ' ' . get_string('allowstudentchat', 'local_ragassistant'),
            ['class' => 'ragassistant-policy-option']
        ) .
        html_writer::tag('p', get_string('allowstudentchat_desc', 'local_ragassistant'), ['class' => 'text-muted']) .
        $examoption .
        html_writer::tag('button', get_string('savechanges'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'title' => get_string('savepolicy_tooltip', 'local_ragassistant'),
        ]),
        [
            'method' => 'post',
            'action' => (new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]))->out(false),
            'class' => 'ragassistant-policy-form',
        ]
    );
    echo html_writer::end_div();
}

if ($fragment) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['sections' => $pagedsections]);
    exit;
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]),
        get_string('backtodashboard', 'local_ragassistant')
    ),
    'mt-3'
);
// Poll document and suggestion jobs so both tables update without a manual reload.
$statusurl = (new moodle_url('/local/ragassistant/document_status.php', [
    'courseid' => $courseid,
    'sesskey' => sesskey(),
]))->out(false);
$polljs = '(function() {
    const url = ' . json_encode($statusurl) . ';
    const tooltips = ' . json_encode([
        'generate' => get_string('generatesuggestion_tooltip', 'local_ragassistant'),
        'regenerate' => get_string('regeneratesuggestion_tooltip', 'local_ragassistant'),
        'generating' => get_string('generatingsuggestion_tooltip', 'local_ragassistant'),
    ]) . ';
    const labels = {
        empty: "Not generated",
        generating: "Generating",
        ready: "Generated",
        failed: "Failed"
    };

    function updateSuggestion(suggestion) {
        const selector = "[data-document-id=\\"" + suggestion.documentid +
            "\\"][data-slot=\\"" + suggestion.slot + "\\"]";
        const textbox = document.querySelector(".ragassistant-suggestion-cell" + selector);
        const statusbox = document.querySelector(".ragassistant-suggestion-status" + selector);
        const actionbox = document.querySelector(".ragassistant-suggestion-action" + selector);
        if (!textbox || !statusbox || !actionbox) return;

        const hasText = !!suggestion.suggestion;
        textbox.replaceChildren();
        const text = document.createElement(hasText ? "div" : "span");
        text.className = hasText ? "ragassistant-suggestion-text" : "text-muted";
        text.textContent = hasText ? suggestion.suggestion : "No suggestion generated yet";
        textbox.appendChild(text);

        statusbox.replaceChildren();
        const badge = document.createElement("span");
        badge.textContent = labels[suggestion.status] || suggestion.status;
        if (suggestion.status === "ready") badge.className = "badge badge-success bg-success";
        else if (suggestion.status === "failed") badge.className = "badge badge-danger bg-danger";
        else if (suggestion.status === "generating") badge.className = "text-info";
        else badge.className = "text-muted";
        statusbox.appendChild(badge);

        actionbox.replaceChildren();
        if (suggestion.status === "generating") {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-outline-secondary btn-sm";
            button.disabled = true;
            button.textContent = "Generating…";
            button.title = tooltips.generating;
            actionbox.appendChild(button);
        } else if (actionbox.dataset.documentReady === "1") {
            const form = document.createElement("form");
            form.method = "post";
            form.action = actionbox.dataset.actionUrl;
            form.className = "ragassistant-document-action";
            [["sesskey", actionbox.dataset.sesskey],
             ["courseid", actionbox.dataset.courseId],
             ["action", "generate_suggestion"],
             ["documentid", suggestion.documentid],
             ["slot", suggestion.slot]].forEach(function(pair) {
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = pair[0];
                input.value = pair[1];
                form.appendChild(input);
            });
            const button = document.createElement("button");
            button.type = "submit";
            button.className = "btn btn-outline-primary btn-sm";
            button.textContent = hasText ? "Regenerate" : "Generate";
            button.title = hasText ? tooltips.regenerate : tooltips.generate;
            if (hasText) {
                form.addEventListener("submit", function(event) {
                    if (!window.confirm("Regenerate only this suggestion?")) event.preventDefault();
                });
            }
            form.appendChild(button);
            actionbox.appendChild(form);
        } else {
            const note = document.createElement("span");
            note.className = "text-muted";
            note.textContent = "Available after indexing";
            actionbox.appendChild(note);
        }
    }

    async function poll() {
        try {
            const response = await fetch(url, {
                credentials: "same-origin",
                headers: {"Accept": "application/json"}
            });
            if (!response.ok) {
                window.setTimeout(poll, 5000);
                return;
            }
            const payload = await response.json();
            let active = false;
            let completed = false;
            (payload.documents || []).forEach(function(doc) {
                const box = document.querySelector(
                    ".ragassistant-processing-status[data-document-id=\\"" + doc.id + "\\"]"
                );
                if (!box) return;
                const previous = box.dataset.status;
                box.dataset.status = doc.status;
                box.dataset.progress = doc.progress;
                box.dataset.stage = doc.stage || "";
                box.replaceChildren();

                const label = document.createElement("span");
                label.className = "ragassistant-status-label";
                label.textContent = doc.status.charAt(0).toUpperCase() + doc.status.slice(1);
                box.appendChild(label);

                if (doc.status === "pending" || doc.status === "processing") {
                    active = true;
                    const wrap = document.createElement("div");
                    wrap.className = "progress ragassistant-document-progress";
                    const bar = document.createElement("div");
                    bar.className = "progress-bar";
                    bar.style.width = doc.progress + "%";
                    bar.setAttribute("role", "progressbar");
                    bar.setAttribute("aria-valuenow", doc.progress);
                    bar.setAttribute("aria-valuemin", "0");
                    bar.setAttribute("aria-valuemax", "100");
                    wrap.appendChild(bar);
                    box.appendChild(wrap);

                    const detail = document.createElement("small");
                    detail.className = "text-muted ragassistant-progress-label";
                    detail.textContent = (doc.stage || "queued") + " · " + doc.progress + "%";
                    box.appendChild(detail);
                } else if (previous === "pending" || previous === "processing") {
                    completed = true;
                }
            });

            let activeSuggestions = false;
            (payload.suggestions || []).forEach(function(suggestion) {
                updateSuggestion(suggestion);
                if (suggestion.status === "generating") activeSuggestions = true;
            });

            if (completed) {
                window.location.reload();
                return;
            }
            if (active || activeSuggestions) {
                window.setTimeout(poll, 2500);
            } else {
                polling = false;
            }
        } catch (error) {
            window.setTimeout(poll, 5000);
        }
    }

    // Start polling once; poll() keeps itself going while jobs are active.
    let polling = false;
    function startPollingIfActive() {
        const hasActiveDocuments = document.querySelector(
            ".ragassistant-processing-status[data-status=\\"pending\\"], " +
            ".ragassistant-processing-status[data-status=\\"processing\\"]"
        );
        const hasGeneratingSuggestions = document.querySelector(
            ".ragassistant-suggestion-status .text-info"
        );
        if (!polling && (hasActiveDocuments || hasGeneratingSuggestions)) {
            polling = true;
            poll();
        }
    }
    startPollingIfActive();

    // Paging without a full reload: request only the paged sections (fragment=1,
    // JSON) and swap them in place. All are refreshed together because each section\'s
    // links carry the other section\'s page number. Falls back to normal navigation.
    const sectionIds = ["ragassistant-documents-section", "ragassistant-exams-section",
        "ragassistant-suggestions-section"];
    let pageRequest = 0;
    // Short-lived prefetch started on hover, so most clicks find the response ready.
    const prefetched = new Map();
    const PREFETCH_TTL = 10000;

    function fetchSections(href) {
        const cached = prefetched.get(href);
        if (cached && Date.now() - cached.time < PREFETCH_TTL) {
            prefetched.delete(href);
            return cached.promise;
        }
        const fragmentUrl = new URL(href, window.location.href);
        fragmentUrl.searchParams.set("fragment", "1");
        return fetch(fragmentUrl.toString(), {
            credentials: "same-origin",
            headers: {"Accept": "application/json"}
        }).then(function(response) {
            if (!response.ok) throw new Error("HTTP " + response.status);
            return response.json();
        });
    }

    function prefetch(href) {
        const cached = prefetched.get(href);
        if (cached && Date.now() - cached.time < PREFETCH_TTL) return;
        const promise = fetchSections(href);
        promise.catch(function() { prefetched.delete(href); });
        prefetched.set(href, {promise: promise, time: Date.now()});
    }

    async function loadPage(href, push) {
        const requestId = ++pageRequest;
        const sections = sectionIds
            .map(function(id) { return document.getElementById(id); })
            .filter(Boolean);
        sections.forEach(function(section) { section.setAttribute("aria-busy", "true"); });
        try {
            const payload = await fetchSections(href);
            // A newer click has started; drop this response.
            if (requestId !== pageRequest) return;
            const fresh = (payload && payload.sections) || {};
            const missing = sections.some(function(section) { return !fresh[section.id]; });
            if (missing) throw new Error("Paged section missing from response");
            sections.forEach(function(section) {
                const template = document.createElement("template");
                template.innerHTML = fresh[section.id].trim();
                section.replaceWith(template.content.firstElementChild);
            });
            // Cached prefetches belong to the old page numbers; drop them.
            prefetched.clear();
            if (push) window.history.pushState({ragassistantPage: true}, "", href);
            startPollingIfActive();
        } catch (error) {
            if (requestId === pageRequest) window.location.href = href;
        }
    }

    document.addEventListener("pointerover", function(event) {
        const link = event.target.closest(".ragassistant-paged-section .ragassistant-docs-paging a[href]");
        if (link) prefetch(link.href);
    });

    document.addEventListener("click", function(event) {
        const link = event.target.closest(".ragassistant-paged-section .ragassistant-docs-paging a[href]");
        if (!link || event.defaultPrevented || event.button !== 0 ||
                event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        loadPage(link.href, true);
    });

    // Back/forward between paged views swaps the sections the same way.
    window.addEventListener("popstate", function() {
        loadPage(window.location.href, false);
    });
})();';
echo html_writer::script($polljs);
echo $OUTPUT->footer();