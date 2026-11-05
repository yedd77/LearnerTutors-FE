<?php
// This file is part of Moodle - https://moodle.org/
//
// Navigation callbacks for local_ragassistant.

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the navigation for a course with the RAG Assistant link.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_ragassistant_extend_navigation_course($navigation, $course, $context): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!has_capability('local/ragassistant:useassistant', $context)) {
        return;
    }

    $url = new moodle_url('/local/ragassistant/index.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'local_ragassistant'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_ragassistant_' . $course->id,
        new pix_icon('i/learning', '')
    );
}
