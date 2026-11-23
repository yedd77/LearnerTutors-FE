<?php
// This file is part of Moodle - https://moodle.org/
//
// Capabilities for local_ragassistant.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/ragassistant:useassistant' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ragassistant:managedocuments' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Reindexing a document is reserved for roles above teacher (manager; site admins
    // have every capability). Teachers keep the other document actions.
    'local/ragassistant:reindexdocuments' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
