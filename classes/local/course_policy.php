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

    /**
     * Whether the current user may use the AI chat in this course: chat must be enabled
     * site-wide, the user needs useassistant, and students also need the course's
     * "allow students to use the AI chat" setting (managers are always allowed).
     *
     * Shared by the chat page and every endpoint that serves chat output (for example
     * the study sheet download proxy), so they all apply the same rule.
     */
    public static function can_use_chat(int $courseid, \context $context): bool {
        if (!get_config('local_ragassistant', 'enable_chat')) {
            return false;
        }
        if (!has_capability('local/ragassistant:useassistant', $context)) {
            return false;
        }
        if (has_capability('local/ragassistant:managedocuments', $context)) {
            return true;
        }
        return (bool) self::get($courseid)->allowstudentchat;
    }

    /**
     * Save a policy row for a course.
     *
     * Student (personal) uploads were removed because the backend no longer indexes them,
     * so allowstudentupload is always stored as 0. The column is kept for existing rows.
     */
    public static function save(int $courseid, bool $allowstudentchat): void {
        global $DB;
        $record = $DB->get_record('local_ragassistant_coursecfg', ['courseid' => $courseid]);
        $data = (object)[
            'courseid' => $courseid,
            'allowstudentchat' => (int)$allowstudentchat,
            'allowstudentupload' => 0,
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
