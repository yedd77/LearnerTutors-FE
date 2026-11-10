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

    // A missing, invalid, or empty response must never erase existing suggestions.
    if (!is_array($suggestions) || !$suggestions) {
        debugging('RAG Assistant: no suggestion array returned for document ' . $documentid, DEBUG_DEVELOPER);
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
        debugging('RAG Assistant: no valid suggestions for document ' . $documentid, DEBUG_DEVELOPER);
        return 0;
    }

    // Replace only after valid replacements exist; rollback also restores deleted rows.
    $transaction = $DB->start_delegated_transaction();
    try {
        $DB->delete_records('local_ragassistant_suggestions', ['documentid' => $documentid]);
        foreach ($cleaned as $suggestion) {
            $DB->insert_record('local_ragassistant_suggestions', (object) [
                'courseid' => $courseid,
                'documentid' => $documentid,
                'userid' => $userid,
                'suggestion' => $suggestion,
                'timecreated' => time(),
            ]);
        }
        $transaction->allow_commit();
        debugging('RAG Assistant: saved ' . count($cleaned) . ' suggestion(s) for document ' . $documentid . '.', DEBUG_DEVELOPER);
        return count($cleaned);
    } catch (\Throwable $exception) {
        $transaction->rollback($exception);
        throw $exception;
    }
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
 * @return string
 */
function local_ragassistant_document_action_form(
    int $courseid,
    int $documentid,
    string $action,
    string $label,
    string $class,
    ?string $onclick = null,
    array $extra = []
): string {
    $attributes = [
        'type' => 'submit',
        'class' => $class,
    ];
    if ($onclick !== null) {
        $attributes['onclick'] = $onclick;
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
require_capability('local/ragassistant:useassistant', $context);
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$policy = \local_ragassistant\local\course_policy::get($courseid);
$canupload = $canmanage || ((bool) $policy->allowstudentupload && has_capability('local/ragassistant:uploadpersonal', $context));
$api = new \local_ragassistant\local\api_client();

// Page setup and metadata
$PAGE->set_url(new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('uploadCardTitle', 'local_ragassistant'));
$PAGE->set_heading(format_string($course->fullname));

// Handle document actions
$action = optional_param('action', '', PARAM_ALPHA);
$actiondocumentid = optional_param('documentid', 0, PARAM_INT);

// Rebuild every stored course document in the currently configured embedding space.
// Original files remain in Moodle file storage; only their backend vectors are replaced.
if ($action === 'reindexall' && data_submitted()) {
    require_sesskey();
    require_capability('local/ragassistant:managedocuments', $context);
    $records = $DB->get_records('local_ragassistant_documents', ['courseid' => $courseid], 'id ASC');
    $success = 0;
    $failed = 0;
    $suggestionssaved = 0;
    $fs = get_file_storage();
    foreach ($records as $record) {
        if (!in_array($record->status, ['ready', 'pending', 'failed'], true)) {
            continue;
        }
        $stored = $fs->get_file($record->contextid, 'local_ragassistant', 'documents',
            $record->fileitemid, $record->filepath, $record->filename);
        if (!$stored) {
            $failed++;
            debugging('RAG Assistant reindex: missing Moodle file for document ' . $record->id, DEBUG_DEVELOPER);
            continue;
        }
        $DB->set_field('local_ragassistant_documents', 'status', 'processing', ['id' => $record->id]);
        try {
            $result = $api->index_file($stored, (int) $record->id, $courseid,
                (int) $record->userid, $record->visibility, !empty($record->retrievalenabled));
            $suggestionssaved += local_ragassistant_save_document_suggestions(
                $courseid,
                (int) $record->id,
                (int) $record->userid,
                $result['suggestions'] ?? []
            );
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $record->id, 'status' => 'ready', 'timemodified' => time(),
            ]);
            $success++;
        } catch (\Throwable $exception) {
            $DB->update_record('local_ragassistant_documents', (object) [
                'id' => $record->id, 'status' => 'failed', 'timemodified' => time(),
            ]);
            debugging('RAG Assistant reindex failed for document ' . $record->id . ': ' .
                $exception->getMessage(), DEBUG_DEVELOPER);
            $failed++;
        }
    }
    $message = $success . ' document(s) reindexed; ' . $failed .
        ' failed; ' . $suggestionssaved . ' starter suggestion(s) saved.';
    redirect(new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]),
        $message, null, $failed ? \core\output\notification::NOTIFY_WARNING :
        \core\output\notification::NOTIFY_SUCCESS);
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

    // Check if the user can manage the document
    $isowner = (int) $target->userid === (int) $USER->id;
    $canmanagepersonal = $isowner
        && $target->visibility === 'personal'
        && has_capability('local/ragassistant:uploadpersonal', $context);

    // require capability if the user cannot manage the document
    if (!$canmanage && !$canmanagepersonal) {
        require_capability('local/ragassistant:managedocuments', $context);
    }

    // prepare redirect URL for after action
    $redirecturl = new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]);

    // Perform the requested action
    try {
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

            // handle 'index' action
            case 'index':
            case 'reindex':

                // Reindex is reserved for existing ready documents; initial index is pending/failed only.
                if (($action === 'index' && !in_array($target->status, ['pending', 'failed'], true)) ||
                    ($action === 'reindex' && $target->status !== 'ready')) {
                    throw new moodle_exception('invalidrequest', 'error');
                }
                // validate that the stored file exist
                if (!$targetfile) {
                    throw new moodle_exception('storedfilemissing', 'local_ragassistant');
                }

                // Update document status to 'processing' before indexing
                $DB->set_field('local_ragassistant_documents', 'status', 'processing', ['id' => $target->id]);

                // call API client to index the file
                try {
                    $result = $api->index_file(
                        $targetfile,
                        (int) $target->id,
                        (int) $target->courseid,
                        (int) $target->userid,
                        $target->visibility,
                        !empty($target->retrievalenabled)
                    );
                } catch (\Throwable $indexexception) {
                    // Do not leave the document stuck in 'processing'.
                    $DB->update_record('local_ragassistant_documents', (object) [
                        'id' => $target->id,
                        'status' => 'failed',
                        'timemodified' => time(),
                    ]);
                    throw $indexexception;
                }

                // Save returned suggestions; empty results preserve any existing rows.
                $savedcount = local_ragassistant_save_document_suggestions(
                    (int) $target->courseid,
                    (int) $target->id,
                    (int) $target->userid,
                    $result['suggestions'] ?? []
                );

                // Update document status to 'ready' after the successful indexing and redirect with a success message
                $DB->update_record('local_ragassistant_documents', (object) [
                    'id' => $target->id,
                    'status' => 'ready',
                    'timemodified' => time(),
                ]);

                // Redirect with success message
                redirect(
                    $redirecturl,
                    get_string('indexsuccess', 'local_ragassistant', (int) ($result['indexed_chunks'] ?? 0)) .
                        ' ' . $savedcount . ' starter suggestion(s) saved.' .
                        ($savedcount === 0 ? ' Backend returned no valid suggestions; check FastAPI logs.' : ''),
                    null,
                    $savedcount > 0 ? \core\output\\notification::NOTIFY_SUCCESS :
                        \core\output\\notification::NOTIFY_WARNING
                );
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

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]));
} else if ($data = $mform->get_data()) {
    require_sesskey();
    if (!$canupload) {
        throw new required_capability_exception($context, 'local/ragassistant:uploadpersonal', 'nopermissions', '');
    }

    $draftitemid = (int) ($data->documents ?? 0);
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

        $visibility = $canmanage ? 'course' : 'personal';
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

            try {
                $DB->set_field('local_ragassistant_documents', 'status', 'processing', ['id' => $documentid]);
                $result = $api->index_file(
                    $storedfile,
                    $documentid,
                    $courseid,
                    (int) $USER->id,
                    $visibility
                );
                $uploadsuggestions = local_ragassistant_save_document_suggestions(
                    $courseid,
                    $documentid,
                    (int) $USER->id,
                    $result['suggestions'] ?? []
                );
                if ($uploadsuggestions === 0) {
                    $messages[] = $filename . ': indexed, but backend returned no valid starter suggestions';
                }
                $DB->update_record('local_ragassistant_documents', (object) [
                    'id' => $documentid,
                    'status' => 'ready',
                    'timemodified' => time(),
                ]);
                $successcount++;
            } catch (\Throwable $exception) {
                $DB->update_record('local_ragassistant_documents', (object) [
                    'id' => $documentid,
                    'status' => 'failed',
                    'timemodified' => time(),
                ]);
                debugging('RAG Assistant indexing failed for ' . $filename . ': ' . $exception->getMessage(), DEBUG_DEVELOPER);
                $failedcount++;
                $messages[] = $filename . ': saved, but indexing failed';
            }
        } catch (\Throwable $exception) {
            debugging('RAG Assistant upload failed for ' . $filename . ': ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $failedcount++;
            $messages[] = $filename . ': upload failed';
            if ($documentid && $DB->record_exists('local_ragassistant_documents', ['id' => $documentid])) {
                $DB->set_field('local_ragassistant_documents', 'status', 'failed', ['id' => $documentid]);
            }
        }
    }

    $summary = "$successcount file(s) indexed successfully.";
    if ($failedcount) {
        $summary .= " $failedcount file(s) failed or need re-indexing.";
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadCardTitle', 'local_ragassistant'));
if ($canmanage) {
    echo html_writer::tag('form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reindexall']) .
        html_writer::tag('button', 'Reindex all course documents', [
            'type' => 'submit',
            'class' => 'btn btn-outline-primary mb-3',
            'onclick' => 'return confirm("Reindex all course documents using the currently configured embedding model?");',
        ]),
        ['method' => 'post', 'action' => (new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]))->out(false)]
    );
}

if ($canupload) {
    $mform->display();
} else {
    echo $OUTPUT->notification(get_string('uploadnotpermitted', 'local_ragassistant'), 'warning');
}

$documents = $DB->get_records('local_ragassistant_documents', ['courseid' => $courseid], 'timecreated DESC');
echo html_writer::start_div('ragassistant-uploaded-card');
echo html_writer::tag(
    'div',
    html_writer::tag(
        'h4',
        get_string('uploadedDocuments', 'local_ragassistant'),
        ['class' => 'ragassistant-card-title']
    ) .
    html_writer::tag(
        'p',
        get_string('manageUploaded', 'local_ragassistant'),
        ['class' => 'ragassistant-card-description']
    ),
    [
        'id' => 'ragassistant-uploaded-heading',
        'class' => 'ragassistant-card-heading',
    ]
);

$table = new html_table();
$table->attributes['class'] = 'generaltable ragassistant-documents';
$table->head = [
    get_string('documentname', 'local_ragassistant'),
    get_string('lecturernote', 'local_ragassistant'),
    get_string('dateuploaded', 'local_ragassistant'),
    get_string('visibility', 'local_ragassistant'),
    get_string('processingstatus', 'local_ragassistant'),
    'Retrieval',
    get_string('actions', 'local_ragassistant'),
];

foreach ($documents as $document) {
    $isowner = ((int) $document->userid === (int) $USER->id);
    if (!$canmanage && $document->visibility !== 'course' && !$isowner) {
        continue;
    }
    // Students must not see that a disabled course document exists (name or note).
    if (!$canmanage && !$isowner && empty($document->retrievalenabled)) {
        continue;
    }
    $retrievalenabled = !empty($document->retrievalenabled);

    $actions = [];
    if (in_array($document->status, ['pending', 'failed'], true) && ($canmanage || $isowner)) {
        $actions[] = local_ragassistant_document_action_form(
            $courseid,
            (int) $document->id,
            'index',
            get_string('indexdocument', 'local_ragassistant'),
            'btn btn-outline-secondary btn-sm'
        );
    }
    if ($document->status === 'ready' && ($canmanage || $isowner)) {
        $actions[] = local_ragassistant_document_action_form(
            $courseid,
            (int) $document->id,
            'reindex',
            'Reindex + regenerate suggestions',
            'btn btn-outline-primary btn-sm',
            'return confirm("Reindex this document using the current embedding model and regenerate its suggestions?");'
        );
        $actions[] = local_ragassistant_document_action_form(
            $courseid,
            (int) $document->id,
            'retrieval',
            $retrievalenabled ? 'Disable retrieval' : 'Enable retrieval',
            $retrievalenabled ? 'btn btn-outline-warning btn-sm' : 'btn btn-success btn-sm',
            null,
            ['enable' => $retrievalenabled ? 0 : 1]
        );
    }
    if ($canmanage || $isowner) {
        $actions[] = local_ragassistant_document_action_form(
            $courseid,
            (int) $document->id,
            'delete',
            'Delete',
            'btn btn-outline-danger btn-sm',
            'return confirm("Delete this document and its indexed content permanently?");'
        );
    }

    $table->data[] = [
        html_writer::span(
            s($document->filename ?: get_string('pendingfilename', 'local_ragassistant')),
            'ragassistant-document-name'
        ),
        trim((string) ($document->lecturernote ?? '')) !== '' ? format_text($document->lecturernote, FORMAT_PLAIN, ['para' => false]) : get_string('notavailable', 'local_ragassistant'),
        html_writer::span(
            userdate($document->timecreated, '%d/%m/%y, %H%M'),
            'ragassistant-document-date'
        ),
        get_string($document->visibility, 'local_ragassistant'),
        get_string('status_' . $document->status, 'local_ragassistant'),
        $document->status !== 'ready'
        ? html_writer::span('Not indexed', 'text-muted')
        : ($retrievalenabled
            ? html_writer::span('Enabled', 'badge badge-success bg-success')
            : html_writer::span('Disabled', 'badge badge-secondary bg-secondary')),
        html_writer::div(implode('', $actions), 'ragassistant-document-actions'),
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('nodocuments', 'local_ragassistant'), 'info');
} else {
    echo html_writer::div(
        html_writer::table($table),
        'table-responsive'
    );
}
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]),
        get_string('backtodashboard', 'local_ragassistant')
    ),
    'mt-3'
);
echo $OUTPUT->footer();