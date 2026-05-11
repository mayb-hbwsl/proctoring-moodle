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
    global $PAGE, $DB, $USER;

    static $already_injected = false;
    if ($already_injected) return;
    $already_injected = true;

    $url = $_SERVER['REQUEST_URI'] ?? '';

    $on_attempt  = strpos($url, 'attempt.php')          !== false;
    $on_summary  = strpos($url, '/mod/quiz/summary.php') !== false;

    if (!$on_attempt && !$on_summary) return;

    // ── Resolve attempt ID & resume status ───────────────────────────────────
    //
    // Standard quiz  → ?attempt=X is in the URL already.
    // Adaptive quiz  → only ?cmid=X; query DB for the in-progress attempt's
    //                  uniqueid (= question-usage ID used as the recording key).
    //
    // Correct ID → server returns is_resume=true on question 2+ → recorder skips
    // sending __recording_start__ again and continues from the right chunk index.

    $timadey_attempt_id = 0;
    $is_resume          = false;

    $quiz_attempt_id = optional_param('attempt', 0, PARAM_INT);
    if ($quiz_attempt_id > 0) {
        $timadey_attempt_id = $quiz_attempt_id;
    }

    if (!$timadey_attempt_id) {
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if ($cmid > 0) {
            try {
                $cm = get_coursemodule_from_id('adaptivequiz', $cmid);
                if ($cm) {
                    $attempt = $DB->get_record_select(
                        'adaptivequiz_attempt',
                        'instance = :instance AND userid = :userid AND attemptstate = :state',
                        ['instance' => $cm->instance, 'userid' => $USER->id, 'state' => 'inprogress'],
                        'id, uniqueid',
                        IGNORE_MULTIPLE
                    );
                    if ($attempt && !empty($attempt->uniqueid)) {
                        $timadey_attempt_id = (int) $attempt->uniqueid;
                    }
                }
            } catch (Exception $e) {}
        }
    }

    if ($timadey_attempt_id > 0) {
        $is_resume = $DB->record_exists('local_timadey_recordings', [
            'userid'    => $USER->id,
            'attemptid' => $timadey_attempt_id,
        ]);
    }

    // ── Inline globals — must appear before the bundle ────────────────────────
    $inline  = '<script>';
    $inline .= 'window.timadeyAttemptId=' . (int)$timadey_attempt_id . ';';
    $inline .= 'window.timadeyIsResume='  . ($is_resume ? 'true' : 'false') . ';';
    $inline .= '</script>';

    // On a resume: hide the overlay instantly so there is no loading flash while
    // the camera reconnects between questions (belt-and-suspenders alongside the
    // AJAX navigation below which removes the reload entirely).
    if ($is_resume) {
        $inline .= '<style>'
            . '#timadey-loading-overlay,.timadey-loading-overlay{'
            . 'display:none!important;opacity:0!important;visibility:hidden!important}'
            . 'body.timadey-locked{overflow:visible!important;pointer-events:auto!important}'
            . '</style>';
    }

    // ── AJAX question navigation (adaptive quiz only) ─────────────────────────
    //
    // The adaptive quiz reloads the full page on every question, which kills the
    // camera stream and re-triggers the loading overlay.  This script intercepts
    // the #responseform submit, POSTs it via fetch, and swaps only the question
    // area — no page reload, camera stays alive continuously.
    //
    // Works inside SEB because it uses no popups or restricted APIs.
    $ajax_nav = <<<'JS'
<script>
(function () {
    'use strict';

    function swapScripts(newNode) {
        // Re-execute inline <script> blocks from the new question so Moodle's
        // question engine initialises correctly (e.g. radio-button state, mathjax).
        newNode.querySelectorAll('script:not([src])').forEach(function (old) {
            var s = document.createElement('script');
            s.textContent = old.textContent;
            document.head.appendChild(s);
            document.head.removeChild(s);
        });
    }

    function attachHandler() {
        var form = document.getElementById('responseform');
        if (!form || form.dataset.ajaxReady) return;
        form.dataset.ajaxReady = '1';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var btn = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;

            fetch(form.action || window.location.href, {
                method:   'POST',
                body:     new FormData(form),
                redirect: 'follow',
            })
            .then(function (r) {
                // Server finished the quiz — follow the redirect normally.
                if (r.url && (r.url.includes('attemptfinished.php') ||
                              r.url.includes('summary.php'))) {
                    window.location.href = r.url;
                    return null;
                }
                return r.text();
            })
            .then(function (html) {
                if (!html) return;

                var doc = new DOMParser().parseFromString(html, 'text/html');

                // Swap the question form.
                var newForm = doc.getElementById('responseform');
                var curForm = document.getElementById('responseform');
                if (newForm && curForm) {
                    curForm.parentNode.replaceChild(newForm, curForm);
                    swapScripts(newForm);
                    attachHandler();          // re-attach to the fresh form
                }

                // Swap the attempt-progress bar if present.
                var newProg = doc.querySelector('.attempt-progress-container');
                var curProg = document.querySelector('.attempt-progress-container');
                if (newProg && curProg) {
                    curProg.innerHTML = newProg.innerHTML;
                }
            })
            .catch(function () {
                // Network failure — fall back to a normal POST so the student
                // is not stuck.
                form.dataset.ajaxReady = '';
                if (btn) btn.disabled = false;
                form.submit();
            });
        }, true); // capture phase runs before other handlers
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachHandler);
    } else {
        attachHandler();
    }
})();
</script>
JS;

    // ── Inject assets in correct order ────────────────────────────────────────
    // Order: globals → overlay CSS → bundle → recorder → ajax-nav
    // The bundle and recorder must see window.timadeyAttemptId at startup.

    $recorder_url = new moodle_url('/local/timadey/assets/recorder.js');
    $bundle_css   = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css');
    $bundle_js    = new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js');

    if ($PAGE->state < moodle_page::STATE_PRINTING_HEADER) {
        if ($on_attempt) {
            $PAGE->requires->css($bundle_css);
            $PAGE->requires->js($bundle_js, false);
        }
        $PAGE->requires->js($recorder_url, false);
        // Inline blocks go to footer output via the before_footer hook.
        echo $inline;
        if ($on_attempt) {
            echo $ajax_nav;
        }
    } else {
        echo $inline;
        if ($on_attempt) {
            echo '<link rel="stylesheet" href="' . $bundle_css . '">';
            echo '<script src="' . $bundle_js . '"></script>';
        }
        echo '<script src="' . $recorder_url . '"></script>';
        if ($on_attempt) {
            echo $ajax_nav;
        }
    }
}
