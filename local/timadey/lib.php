<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

/**
 * Inject the Timadey proctoring script into quiz attempt pages.
 * This standard Moodle hook is called on every page before the footer is rendered.
 */
function local_timadey_before_footer() {
    global $PAGE;

    // Only inject on quiz attempt pages (standard + adaptive)
    $path = $PAGE->url->get_path();
    if (strpos($path, '/mod/quiz/attempt.php') === false &&
        strpos($path, '/mod/adaptivequiz/attempt.php') === false) {
        return;
    }

    // Load the proctoring CSS and JS bundle using standard Moodle requirements
    $PAGE->requires->css(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css'));
    $PAGE->requires->js(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js'), true);
}
