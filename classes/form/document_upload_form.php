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
               value="' . s(get_string('uploadfilesbutton', 'local_ragassistant')) . '">
        <input type="submit" name="cancel" id="id_cancel"
               class="btn btn-secondary" formnovalidate
               value="' . s(get_string('cancel')) . '"
               data-skip-validation="1" data-cancel="1" onclick="skipClientValidation = true; return true;">
    </div>
');

        // Add JavaScript for per-file notes if user can manage documents.
        if ($canmanage) {
            $this->add_per_file_notes_javascript();
        }
    }

    // Client-side JavaScript to handle per-file notes and the upload-to-review transition.
    private function add_per_file_notes_javascript(): void
    {
        $js = <<<'JS'
(function() {
    function initialiseReview() {
        const reviewContainer = document.getElementById('ragassistant-file-review');
        const reviewHeading = document.getElementById('ragassistant-review-heading');
        const uploadHeading = document.getElementById('ragassistant-upload-heading');
        const notesInput = document.querySelector('input[name="filenotes"]');
        const manager = document.querySelector('#fitem_id_documents .filemanager');

        const managerItem = document.getElementById('fitem_id_documents');

        if (!reviewContainer || !reviewHeading || !uploadHeading ||
            !notesInput || !manager || !managerItem) {
            return;
        }

        // Keep the notes when the review cards are re-rendered.
        const notes = {};
        let lastSignature = '';
        let pendingSignature = '';
        let transitionTimer = null;

        // Get the list of file names from Moodle's file manager.
        function getFileNames() {
            const names = new Set();

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

        // Switch from the upload step to the review step.
        function showReviewSection() {
            uploadHeading.style.display = 'none';
            managerItem.style.display = 'none';
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
                card.className = 'ragassistant-file-card';

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
                fileNumber.textContent = 'Document ' + (index + 1);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn-outline-danger btn-sm ragassistant-remove-file';
                removeButton.textContent = 'Remove';
                removeButton.setAttribute('aria-label', 'Remove ' + name);
                removeButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();

                    const fileNode = findFileNode(name);

                    if (!fileNode) {
                        return;
                    }

                    fileNode.click();

                    const waitForDeleteButton = function(attempts) {
                        const selectPanel = document.querySelector('.fp-select');
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

                // Lecturer note field.
                const noteLabel = document.createElement('label');
                noteLabel.className = 'ragassistant-note-label';
                noteLabel.textContent = 'Lecturer note';

                const textarea = document.createElement('textarea');
                textarea.className = 'form-control ragassistant-note-input';
                textarea.rows = 3;
                textarea.maxLength = 2000;
                textarea.addEventListener('input', function() {
                    notes[name] = textarea.value;
                    notesInput.value = JSON.stringify(notes);
                });

                // Assemble the card.
                card.appendChild(header);
                card.appendChild(noteLabel);
                card.appendChild(textarea);
                reviewContainer.appendChild(card);
            });

            // Synchronise notes with the hidden form field.
            notesInput.value = JSON.stringify(notes);
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

                notesInput.value = JSON.stringify(notes);
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
})();
JS;

        $this->_form->addElement('html', html_writer::script($js));
    }

    /** Validate every file in the draft area. */
    public function validation($data, $files): array
    {
        $errors = parent::validation($data, $files);
        $draftitemid = (int) ($data['documents'] ?? 0);
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
            $errors['documents'] = get_string('required');
            return $errors;
        }

        if (count($draftfiles) > 20) {
            $errors['documents'] = 'You can upload a maximum of 20 files at once.';
            return $errors;
        }

        foreach ($draftfiles as $file) {
            $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

            if (!in_array($extension, $allowed, true)) {
                $errors['documents'] = 'Unsupported file type: ' . s($file->get_filename());
                break;
            }

            if ($file->get_filesize() > 20 * 1024 * 1024) {
                $errors['documents'] = 'File exceeds the 20 MB limit: ' . s($file->get_filename());
                break;
            }
        }

        return $errors;
    }
}
