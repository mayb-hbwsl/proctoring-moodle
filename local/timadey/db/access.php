<?php
// Capability definitions for the Timadey AI Proctoring plugin
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Capability to view proctoring incident reports
    'local/timadey:viewreports' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
