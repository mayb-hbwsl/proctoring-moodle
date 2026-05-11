<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Serves a Safe Exam Browser (.seb) configuration file for an adaptive quiz.
 *
 * When the student clicks "Launch Safe Exam Browser", their browser follows a
 * sebs:// link that points here.  SEB fetches this URL (translating sebs → https),
 * reads the plist config, and then navigates to the startURL embedded in it.
 *
 * @package   mod_adaptivequiz
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

if (!$cm = get_coursemodule_from_id('adaptivequiz', $cmid)) {
    throw new moodle_exception('invalidcoursemodule');
}

$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/adaptivequiz:attempt', $context);

$adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $cm->instance], '*', MUST_EXIST);

if (empty($adaptivequiz->requireseb)) {
    throw new moodle_exception('sebnotrequired', 'adaptivequiz',
        new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cmid]));
}

// The URL SEB will open after applying this config.
// Pointing straight at attempt.php means SEB lands on the quiz immediately.
$starturl = (new moodle_url('/mod/adaptivequiz/attempt.php', ['cmid' => $cmid]))->out(false);

// Build a minimal Apple Property List (plist) config understood by SEB 3.x+.
$xml = implode("\n", [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"'
        . ' "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
    '<plist version="1.0">',
    '<dict>',
    '    <key>startURL</key>',
    '    <string>' . htmlspecialchars($starturl, ENT_XML1, 'UTF-8') . '</string>',
    // Send the Browser Exam Key hash so attempt.php can validate the request.
    '    <key>sendBrowserExamKey</key>',
    '    <true/>',
    // Keep the SEB task bar visible so students can see the clock.
    '    <key>showTaskBar</key>',
    '    <true/>',
    // Allow page navigation — needed for login redirect and question submission.
    // (This only controls the manual reload button, not JS navigation.)
    '    <key>browserWindowAllowReload</key>',
    '    <true/>',
    // Allow quitting SEB after the exam.
    '    <key>allowQuit</key>',
    '    <true/>',
    // Do NOT open new windows — popups are not needed and confuse SEB.
    '    <key>newBrowserWindowByLinkPolicy</key>',
    '    <integer>2</integer>',
    '</dict>',
    '</plist>',
    '',
]);

// Send the file with correct MIME type so SEB recognises it as a config file.
header('Cache-Control: private, max-age=1, no-transform');
header('Expires: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
header('Pragma: no-cache');
header('Content-Disposition: attachment; filename=adaptivequiz_' . (int)$cmid . '.seb');
header('Content-Type: application/seb');

echo $xml;
