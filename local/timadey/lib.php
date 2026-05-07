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
    
    // Activate on quiz attempt pages AND the summary/review page shown after "Finish attempt".
    // The summary page is included so recording continues right up until final submission.
    $on_attempt = strpos($url, 'attempt.php') !== false;
    $on_summary = strpos($url, '/mod/quiz/summary.php') !== false;
    if ($on_attempt || $on_summary) {
        
        $css_url = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css');
        $js_url = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js');
        $recorder_url = new moodle_url('/local/timadey/assets/recorder.js');

        if ($PAGE->state < moodle_page::STATE_PRINTING_HEADER) {
            // Inject CSS and JS cleanly into head/footer via Moodle API
            $PAGE->requires->css($css_url);
            // Inject JS (false means don't put in head, so it goes to footer)
            $PAGE->requires->js($js_url, false);
            $PAGE->requires->js($recorder_url, false);
        } else {
            // Fallback for late injection (e.g. during before_footer hook)
            echo '<link rel="stylesheet" type="text/css" href="' . $css_url . '">';
            echo '<script src="' . $js_url . '"></script>';
            echo '<script src="' . $recorder_url . '"></script>';
        }
        
        $already_injected = true;
    }
}
