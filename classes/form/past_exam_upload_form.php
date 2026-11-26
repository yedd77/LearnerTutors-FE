<?php
// Form to upload past exam papers, separate from the course material upload.

namespace local_ragassistant\form;

use html_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Upload card for past exam papers.
 *
 * Only the structure of a past paper is kept (sections, question types, marks), so that
 * /exam practice papers follow the lecturer's format. A past paper is never searchable in
 * the chat. This is its own form class, so Moodle tells the two upload forms on
 * documents.php apart; it reuses the course material form's file checks.
 */
class past_exam_upload_form extends document_upload_form
{
    /**
     * Form element definitions.
     */
    public function definition(): void
    {
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];

        $mform->addElement(
            'html',
            html_writer::tag(
                'div',
                html_writer::tag(
                    'h4',
                    get_string('pastexamupload', 'local_ragassistant'),
                    ['class' => 'ragassistant-card-title']
                ) .
                html_writer::tag(
                    'p',
                    get_string('pastexamuploaddesc', 'local_ragassistant'),
                    ['class' => 'ragassistant-card-description']
                ),
                [
                    'id' => 'ragassistant-exam-upload-heading',
                    'class' => 'ragassistant-card-heading',
                ]
            )
        );

        $mform->addElement(
            'filemanager',
            'exampapers',
            get_string('pastexamfile', 'local_ragassistant'),
            null,
            [
                'subdirs' => 0,
                'maxfiles' => 20,
                'maxbytes' => 20 * 1024 * 1024,
                'accepted_types' => ['.pdf', '.docx', '.pptx', '.txt', '.md'],
            ]
        );
        $mform->addHelpButton('exampapers', 'pastexamfile', 'local_ragassistant');

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('html', '
    <div class="ragassistant-actions d-flex justify-content-end gap-2 mt-3">
        <input type="submit" name="submitbutton" id="id_examsubmitbutton"
               class="btn btn-primary"
               value="' . s(get_string('pastexamuploadbutton', 'local_ragassistant')) . '"
               title="' . s(get_string('pastexamuploadbutton_tooltip', 'local_ragassistant')) . '">
    </div>
');
    }

    /**
     * Validate every file in the draft area, with the same checks as course material.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array
    {
        $errors = \moodleform::validation($data, $files);
        return $this->check_upload_files($errors, $data, 'exampapers');
    }
}
