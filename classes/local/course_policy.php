<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/** Course-level controls for student access. */
class course_policy {
    /** Return safe defaults when no course policy has been saved yet. */
    public static function get(int $courseid): object {
        global $DB;
        $settings = $DB->get_record('local_ragassistant_coursecfg', ['courseid' => $courseid]);
        if (!$settings) {
            return (object)['courseid' => $courseid, 'allowstudentchat' => 1, 'allowstudentupload' => 0];
        }
        return $settings;
    }

    /** Save a policy row for a course. */
    public static function save(int $courseid, bool $allowstudentchat, bool $allowstudentupload): void {
        global $DB;
        $record = $DB->get_record('local_ragassistant_coursecfg', ['courseid' => $courseid]);
        $data = (object)[
            'courseid' => $courseid,
            'allowstudentchat' => (int)$allowstudentchat,
            'allowstudentupload' => (int)$allowstudentupload,
            'timemodified' => time(),
        ];
        if ($record) {
            $data->id = $record->id;
            $DB->update_record('local_ragassistant_coursecfg', $data);
        } else {
            $DB->insert_record('local_ragassistant_coursecfg', $data);
        }
    }
}
