<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

function local_timadey_before_footer() {
    local_timadey_inject_assets();
}

function local_timadey_extend_navigation(global_navigation $nav) {
    local_timadey_inject_assets();
}

function local_timadey_inject_assets() {
    global $PAGE;

    static $already_injected = false;
    if ($already_injected) return;

    $url = $_SERVER['REQUEST_URI'] ?? '';

    $on_attempt = strpos($url, 'attempt.php') !== false;
    $on_summary = strpos($url, '/mod/quiz/summary.php') !== false;

    if (!$on_attempt && !$on_summary) return;

    $recorder_url = new moodle_url('/local/timadey/assets/recorder.js');

    if ($PAGE->state < moodle_page::STATE_PRINTING_HEADER) {
        if ($on_attempt) {
            // Full proctoring bundle (MediaPipe detection + overlay) only on the quiz page.
            // Summary page only needs the recorder — no detection needed there.
            $PAGE->requires->css(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css'));
            $PAGE->requires->js(new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js'), false);
        }
        $PAGE->requires->js($recorder_url, false);
    } else {
        if ($on_attempt) {
            echo '<link rel="stylesheet" type="text/css" href="'
                . new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css') . '">';
            echo '<script src="'
                . new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js') . '"></script>';
        }
        echo '<script src="' . $recorder_url . '"></script>';
    }

    $already_injected = true;
}
