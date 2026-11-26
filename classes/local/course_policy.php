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
            return (object)[
                'courseid' => $courseid,
                'allowstudentchat' => 1,
                'allowstudentexam' => 1,
                'allowstudentupload' => 0,
            ];
        }
        return $settings;
    }

    /**
     * Whether the "Allow students to use /exam" setting applies to this course, and so is
     * shown on the course materials page.
     *
     * /exam generates exam questions with or without past exam papers (past papers only
     * give it the lecturer's structure), so this does not depend on them. It is hidden only
     * when the AI chat is off, site-wide or for students in this course: students cannot
     * use any command then. The saved value is kept while it is hidden.
     *
     * @param int $courseid
     * @param object|null $policy the course policy, when already loaded
     * @return bool
     */
    public static function exam_option_available(int $courseid, ?object $policy = null): bool {
        if (!get_config('local_ragassistant', 'enable_chat')) {
            return false;
        }
        $policy = $policy ?? self::get($courseid);
        return !empty($policy->allowstudentchat);
    }

    /**
     * Whether the current user may use the /exam command in this course.
     *
     * Requires chat access (can_use_chat). Managers can always use it, for example to
     * preview the questions or practice papers. For students the course's
     * "Allow students to use /exam" setting decides, with or without past exam papers.
     *
     * @param int $courseid
     * @param \context $context course context
     * @return bool
     */
    public static function can_use_exam(int $courseid, \context $context): bool {
        if (!self::can_use_chat($courseid, $context)) {
            return false;
        }
        if (has_capability('local/ragassistant:managedocuments', $context)) {
            return true;
        }
        $policy = self::get($courseid);
        return !isset($policy->allowstudentexam) || (bool) $policy->allowstudentexam;
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
     *
     * @param int $courseid
     * @param bool $allowstudentchat
     * @param bool|null $allowstudentexam null keeps the saved value (the /exam setting was
     *        not shown, so the form did not send it)
     */
    public static function save(int $courseid, bool $allowstudentchat, ?bool $allowstudentexam = null): void {
        global $DB;
        $record = $DB->get_record('local_ragassistant_coursecfg', ['courseid' => $courseid]);
        if ($allowstudentexam === null) {
            $allowstudentexam = !$record || !isset($record->allowstudentexam) || (bool) $record->allowstudentexam;
        }
        $data = (object)[
            'courseid' => $courseid,
            'allowstudentchat' => (int)$allowstudentchat,
            'allowstudentexam' => (int)$allowstudentexam,
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
