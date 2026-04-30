<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

/**
 * We use two different Moodle hooks to ensure the script is injected 
 * even if one hook is ignored by a custom theme or module.
 */

// Hook 1: Standard Footer Hook
function local_timadey_before_footer() {
    local_timadey_inject_assets();
}

// Hook 2: Navigation Hook (Runs on almost every page)
function local_timadey_extend_navigation(global_navigation $nav) {
    global $PAGE;
    
    // Add Report link for admins in the site administration or site menu
    if (has_capability('moodle/site:config', context_system::instance())) {
        $url = new moodle_url('/local/timadey/report.php');
        $nav->add(get_string('reports', 'local_timadey'), $url, navigation_node::TYPE_SETTING, null, 'timadey_reports');
    }

    local_timadey_inject_assets();
}

/**
 * Core injection logic
 */
function local_timadey_inject_assets() {
    global $PAGE;
    
    // Use a static variable to ensure we only inject once per page
    static $already_injected = false;
    if ($already_injected) {
        return;
    }

    // Check the URL directly from the server to be 100% sure
    $url = $_SERVER['REQUEST_URI'] ?? '';
    
    // We look for 'attempt.php' anywhere in the URL (works for standard and adaptive quiz)
    if (strpos($url, 'attempt.php') !== false) {
        
        // Inject CSS
        $PAGE->requires->css(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css'));
        
        // Inject JS (the 'true' makes it load in the footer for better performance)
        $PAGE->requires->js(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js'), true);
        
        $already_injected = true;
    }
}
