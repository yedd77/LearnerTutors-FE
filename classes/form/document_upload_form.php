<?php
// form to upload document components

namespace local_ragassistant\form;

use html_writer;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class document_upload_form extends \moodleform
{
    // Form element definitions
    public function definition(): void
    {
        // Retrieve data
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];
        $contextid = (int) $this->_customdata['contextid'];
        $canmanage = !empty($this->_customdata['canmanage']);

        // Upload card introduction, title and description
        $mform->addElement(
            'html',
            html_writer::tag(
                'div',
                html_writer::tag(
                    'h4',
                    get_string('submitdocument', 'local_ragassistant'),
                    ['class' => 'ragassistant-card-title']
                ) .
                html_writer::tag(
                    'p',
                    get_string('submitdocumentdesc', 'local_ragassistant'),
                    ['class' => 'ragassistant-card-description']
                ),
                [
                    'id' => 'ragassistant-upload-heading',
                    'class' => 'ragassistant-card-heading',
                ]
            )
        );

        // Add the Moodle file manager
        $mform->addElement(
            'filemanager',
            'documents',
            get_string('documentfile', 'local_ragassistant'),
            null,
            [
                'subdirs' => 0,
                'maxfiles' => 20,
                'maxbytes' => 20 * 1024 * 1024,
                'accepted_types' => ['.pdf', '.docx', '.pptx', '.txt', '.md'],
            ]
        );
        $mform->addHelpButton('documents', 'documentfile', 'local_ragassistant');

        // Display the review section only after files are processed.
        if ($canmanage) {
            $mform->addElement(
                'html',
                html_writer::tag(
                    'div',
                    html_writer::tag(
                        'h4',
                        get_string('documentreviewtitle', 'local_ragassistant'),
                        ['class' => 'ragassistant-card-title']
                    ) .
                    html_writer::tag(
                        'p',
                        get_string('documentreviewdesc', 'local_ragassistant'),
                        ['class' => 'ragassistant-card-description']
                    ),
                    [
                        'id' => 'ragassistant-review-heading',
                        'class' => 'ragassistant-card-heading',
                        'style' => 'display:none;',
                    ]
                )
            );

            // Each successfully processed file will appear as an individual card.
            $mform->addElement(
                'html',
                '<div id="ragassistant-file-review" class="ragassistant-review-grid" style="display:none;"></div>'
            );

            // Store the individual notes for processing in documents.php.
            $mform->addElement('hidden', 'filenotes', '{}');
            $mform->setType('filenotes', PARAM_RAW_TRIMMED);
        }

        // Add hidden fields and action buttons
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('html', ' 
    <div class="ragassistant-actions d-flex justify-content-end gap-2 mt-3">
        <input type="submit" name="submitbutton" id="id_submitbutton"
               class="btn btn-primary"
               value="' . s(get_string('uploadfilesbutton', 'local_ragassistant')) . '"
               title="' . s(get_string('uploadfilesbutton_tooltip', 'local_ragassistant')) . '">
        <input type="submit" name="cancel" id="id_cancel"
               class="btn btn-secondary" formnovalidate
               value="' . s(get_string('cancel')) . '"
               title="' . s(get_string('cancelupload_tooltip', 'local_ragassistant')) . '"
               data-skip-validation="1" data-cancel="1" onclick="skipClientValidation = true; return true;">
    </div>
');

        // Add JavaScript for per-file notes if user can manage documents.
        if ($canmanage) {
            $this->add_review_javascript([
                'field' => 'documents',
                'uploadHeadingId' => 'ragassistant-upload-heading',
                'reviewHeadingId' => 'ragassistant-review-heading',
                'reviewId' => 'ragassistant-file-review',
                'notesInputName' => 'filenotes',
                'itemLabel' => 'Document',
            ]);
        }
    }

    /**
     * Client-side JavaScript for the upload-to-review transition: once files are picked,
     * the file manager is replaced by one card per file, with an optional note for each.
     *
     * Shared with past_exam_upload_form, which passes its own element ids and no notes.
     *
     * @param array $config field (file manager element name), uploadHeadingId, reviewHeadingId,
     *        reviewId, notesInputName (hidden field for per-file notes, or null for no notes)
     *        and itemLabel (shown as "<itemLabel> 1", "<itemLabel> 2", ...).
     */
    protected function add_review_javascript(array $config): void
    {
        $js = <<<'JS'
(function(config) {
    function initialiseReview() {
        const reviewContainer = document.getElementById(config.reviewId);
        const reviewHeading = document.getElementById(config.reviewHeadingId);
        const uploadHeading = document.getElementById(config.uploadHeadingId);
        // Per-file notes are optional (past exam papers have none).
        const notesInput = config.notesInputName
            ? document.querySelector('input[name="' + config.notesInputName + '"]')
            : null;
        const manager = document.querySelector('#fitem_id_' + config.field + ' .filemanager');

        const managerItem = document.getElementById('fitem_id_' + config.field);

        if (!reviewContainer || !reviewHeading || !uploadHeading ||
            (config.notesInputName && !notesInput) || !manager || !managerItem) {
            return;
        }

        // Write the notes to the hidden form field, when this form has notes.
        function syncNotes() {
            if (notesInput) {
                notesInput.value = JSON.stringify(notes);
            }
        }

        // Keep the notes when the review cards are re-rendered.
        const notes = {};
        let lastSignature = '';
        let pendingSignature = '';
        let transitionTimer = null;

        // Get the list of file names from Moodle's file manager.
        function getFileNames() {
            const names = new Set();

            // When the last file is deleted, Moodle only marks the file manager "fm-noitems"
            // and hides the old file entries; it does not remove them. Treat it as empty.
            if (manager.classList.contains('fm-noitems')) {
                return [];
            }

            manager.querySelectorAll('.fp-file, [data-filename]').forEach(function(node) {
                const value = node.getAttribute('data-filename') ||
                    (node.querySelector('.fp-filename') &&
                        node.querySelector('.fp-filename').textContent) ||
                    (node.querySelector('[title]') &&
                        node.querySelector('[title]').getAttribute('title')) || '';

                const name = value.trim();

                if (name && name !== '..' && !name.endsWith('/')) {
                    names.add(name);
                }
            });

            return Array.from(names).sort((a, b) => a.localeCompare(b));
        }

        function findFileNode(name) {
            return Array.from(manager.querySelectorAll('.fp-content a, .fp-content [data-filename]')).find(function(node) {
                const filename = node.querySelector('.fp-filename');
                const value = node.getAttribute('data-filename') ||
                    (filename && filename.textContent) ||
                    node.getAttribute('title') || '';

                return value.trim() === name;
            });
        }

        // Check whether Moodle still shows an upload in progress.
        function isUploadInProgress() {
            const progressSelectors = [
                '.dndupload-progress',
                '.fp-uploading',
                '.filemanager .progress',
                '.filemanager [aria-busy="true"]'
            ];

            return progressSelectors.some(function(selector) {
                return Array.from(manager.querySelectorAll(selector)).some(function(element) {
                    if (element.hidden || element.getAttribute('aria-hidden') === 'true') {
                        return false;
                    }

                    const style = window.getComputedStyle(element);

                    return style.display !== 'none' && style.visibility !== 'hidden';
                });
            });
        }

        // Show the review cards below the file manager. The upload heading and the file
        // manager stay visible, so more files can be dragged in or added at any time while
        // reviewing (the cards update as files are added or removed).
        function showReviewSection() {
            uploadHeading.style.display = '';
            managerItem.style.display = '';
            reviewHeading.style.display = '';
            reviewContainer.style.display = 'grid';
        }

        // Render each uploaded file as an individual card.
        function render(names) {
            Object.keys(notes).forEach(function(name) {
                if (!names.includes(name)) {
                    delete notes[name];
                }
            });

            reviewContainer.replaceChildren();

            names.forEach(function(name, index) {
                // Create the file card.
                const card = document.createElement('article');
                card.className = notesInput ? 'ragassistant-file-card' : 'ragassistant-file-card ragassistant-file-card-compact';

                // Card header.
                const header = document.createElement('div');
                header.className = 'ragassistant-file-card-header';

                const extension = name.includes('.') ? name.split('.').pop() : 'FILE';

                const fileDetails = document.createElement('div');
                fileDetails.className = 'ragassistant-file-details';

                const filename = document.createElement('strong');
                filename.className = 'ragassistant-filename';
                filename.textContent = name;
                filename.title = name;

                const fileNumber = document.createElement('span');
                fileNumber.className = 'ragassistant-file-number';
                fileNumber.textContent = config.itemLabel + ' ' + (index + 1);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn-outline-danger btn-sm ragassistant-remove-file';
                removeButton.textContent = 'Remove';
                removeButton.setAttribute('aria-label', 'Remove ' + name);
                removeButton.title = 'Remove this file from the upload.';
                removeButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();

                    const fileNode = findFileNode(name);

                    if (!fileNode) {
                        return;
                    }

                    fileNode.click();

                    const waitForDeleteButton = function(attempts) {
                        // Each file manager on the page has its own file panel, and the
                        // hidden ones are only hidden through their dialog wrapper. Use the
                        // panel that is actually on screen: the one just opened for this
                        // file. (The first .fp-select may belong to the other upload form.)
                        const selectPanel = Array.from(document.querySelectorAll('.fp-select'))
                            .find(function(panel) {
                                return panel.getClientRects().length > 0;
                            });
                        const deleteButton = selectPanel &&
                            selectPanel.querySelector('.fp-file-delete');
                        const panelStyle = selectPanel && window.getComputedStyle(selectPanel);

                        if (deleteButton && !deleteButton.disabled && panelStyle &&
                            panelStyle.display !== 'none' && panelStyle.visibility !== 'hidden') {
                            deleteButton.dispatchEvent(new MouseEvent('click', {
                                bubbles: true,
                                cancelable: true,
                                view: window
                            }));
                            return;
                        }

                        if (attempts > 0) {
                            window.setTimeout(function() {
                                waitForDeleteButton(attempts - 1);
                            }, 50);
                        }
                    };

                    waitForDeleteButton(40);
                });

                fileDetails.appendChild(filename);
                fileDetails.appendChild(fileNumber);
                header.appendChild(fileDetails);
                header.appendChild(removeButton);

                card.appendChild(header);

                // Lecturer note field, when this form has notes.
                if (notesInput) {
                    const noteLabel = document.createElement('label');
                    noteLabel.className = 'ragassistant-note-label';
                    noteLabel.textContent = 'Lecturer note';

                    const textarea = document.createElement('textarea');
                    textarea.className = 'form-control ragassistant-note-input';
                    textarea.rows = 3;
                    textarea.maxLength = 2000;
                    textarea.addEventListener('input', function() {
                        notes[name] = textarea.value;
                        syncNotes();
                    });

                    card.appendChild(noteLabel);
                    card.appendChild(textarea);
                }

                reviewContainer.appendChild(card);
            });

            // Synchronise notes with the hidden form field.
            syncNotes();
        }

        // Wait until the list of files stays stable before switching sections.
        function checkFiles() {
            const names = getFileNames();
            const signature = JSON.stringify(names);

            if (!names.length) {
                pendingSignature = '';
                lastSignature = '';
                window.clearTimeout(transitionTimer);

                // No files: return to the upload step.
                uploadHeading.style.display = '';
                managerItem.style.display = '';
                reviewHeading.style.display = 'none';
                reviewContainer.style.display = 'none';

                syncNotes();
                return;
            }

            if (isUploadInProgress()) {
                window.clearTimeout(transitionTimer);
                pendingSignature = signature;
                return;
            }

            // Wait briefly for Moodle's asynchronous file manager updates to settle.
            if (signature !== pendingSignature) {
                pendingSignature = signature;
                window.clearTimeout(transitionTimer);

                transitionTimer = window.setTimeout(checkFiles, 700);
                return;
            }

            if (signature !== lastSignature) {
                lastSignature = signature;
                render(names);
            }

            // Only now switch from upload to review.
            showReviewSection();
        }

        // Observe file manager changes when files are added or removed.
        const observer = new MutationObserver(function() {
            window.clearTimeout(transitionTimer);
            transitionTimer = window.setTimeout(checkFiles, 200);
        });

        observer.observe(manager, {
            childList: true,
            subtree: true,
            attributes: true
        });

        manager.addEventListener('click', function() {
            window.setTimeout(checkFiles, 300);
        });

        manager.addEventListener('change', function() {
            window.setTimeout(checkFiles, 300);
        });

        // Check the initial file manager state.
        checkFiles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiseReview);
    } else {
        initialiseReview();
    }
})(__RAGASSISTANT_REVIEW_CONFIG__);
JS;

        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $js = str_replace('__RAGASSISTANT_REVIEW_CONFIG__', $json, $js);
        $this->_form->addElement('html', html_writer::script($js));
    }

    /** Validate every file in the draft area. */
    public function validation($data, $files): array
    {
        $errors = parent::validation($data, $files);
        return $this->check_upload_files($errors, $data, 'documents');
    }

    /**
     * Validate every file in a file manager's draft area: type, size, PDF page limit and
     * duplicates (in this upload or already in the course, whatever their type).
     *
     * Shared with past_exam_upload_form, which uses its own file manager field.
     *
     * @param array $errors errors found so far
     * @param array $data submitted form data
     * @param string $field name of the file manager element
     * @return array
     */
    protected function check_upload_files(array $errors, array $data, string $field): array
    {
        $draftitemid = (int) ($data[$field] ?? 0);
        $usercontext = \context_user::instance($GLOBALS['USER']->id);

        $draftfiles = get_file_storage()->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $allowed = ['pdf', 'docx', 'pptx', 'txt', 'md'];

        if (!$draftfiles) {
            $errors[$field] = get_string('required');
            return $errors;
        }

        if (count($draftfiles) > 20) {
            $errors[$field] = 'You can upload a maximum of 20 files at once.';
            return $errors;
        }

        // Fingerprint of each file: Moodle's SHA-1 content hash. The same document
        // (byte for byte, whatever its name) can be in a course only once.
        $coursecontextid = (int) $this->_customdata['contextid'];
        $seen = [];

        foreach ($draftfiles as $file) {
            $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

            if (!in_array($extension, $allowed, true)) {
                $errors[$field] = 'Unsupported file type: ' . s($file->get_filename());
                break;
            }

            if ($file->get_filesize() > 20 * 1024 * 1024) {
                $errors[$field] = 'File exceeds the 20 MB limit: ' . s($file->get_filename());
                break;
            }

            // The backend refuses PDFs over its page limit; say so now rather than after
            // a failed indexing task. Unreadable counts are left to the backend.
            if ($extension === 'pdf') {
                $pages = \local_ragassistant\local\pdf_page_counter::count($file);
                if ($pages !== null && $pages > \local_ragassistant\local\pdf_page_counter::MAX_PAGES) {
                    $errors[$field] = get_string('pdftoomanypages', 'local_ragassistant', (object) [
                        'filename' => s($file->get_filename()),
                        'pages' => $pages,
                        'limit' => \local_ragassistant\local\pdf_page_counter::MAX_PAGES,
                    ]);
                    break;
                }
            }

            $hash = $file->get_contenthash();
            if (isset($seen[$hash])) {
                $errors[$field] = get_string('documentduplicateupload', 'local_ragassistant', (object) [
                    'first' => s($seen[$hash]),
                    'second' => s($file->get_filename()),
                ]);
                break;
            }
            $seen[$hash] = $file->get_filename();

            $existing = $this->existing_document($coursecontextid, $hash);
            if ($existing !== null) {
                $errors[$field] = get_string('documentduplicate', 'local_ragassistant', (object) [
                    'filename' => s($file->get_filename()),
                    'existing' => s($existing),
                ]);
                break;
            }
        }

        return $errors;
    }

    /**
     * Name of the course document with this content hash, or null if there is none.
     *
     * @param int $contextid course context
     * @param string $contenthash
     * @return string|null
     */
    protected function existing_document(int $contextid, string $contenthash): ?string
    {
        global $DB;
        $sql = "SELECT d.id, d.filename
                  FROM {files} f
                  JOIN {local_ragassistant_documents} d ON d.id = f.itemid AND d.contextid = f.contextid
                 WHERE f.contextid = :contextid
                   AND f.component = 'local_ragassistant'
                   AND f.filearea = 'documents'
                   AND f.contenthash = :contenthash
                   AND f.filename <> '.'";
        $records = $DB->get_records_sql($sql, ['contextid' => $contextid, 'contenthash' => $contenthash], 0, 1);
        $record = reset($records);
        return $record ? (string) $record->filename : null;
    }
}
