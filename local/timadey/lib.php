<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

/**
 * Inject the Timadey proctoring script into quiz attempt pages.
 * This hook is called by Moodle before rendering the page head.
 */
function local_timadey_before_standard_html_head() {
    global $PAGE;

    // Only inject on quiz attempt pages
    $path = $PAGE->url->get_path();
    if (strpos($path, '/mod/quiz/attempt.php') === false) {
        return '';
    }

    // Load the proctoring CSS and JS bundle
    $cssurl = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css');
    $jsurl = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js');

    // Return the HTML to inject into <head>
    return '<link rel="stylesheet" href="' . $cssurl->out() . '">' .
           '<script src="' . $jsurl->out() . '" defer></script>';
}
