<?php
/**
 * Timadey AI Proctoring - Attempt Details View
 */

require_once('../../config.php');
require_once($CFG->libdir . '/tablelib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$attemptid = required_param('attempt', PARAM_INT);

// Page Setup
$url = new moodle_url('/local/timadey/report_details.php', ['attempt' => $attemptid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('incident_log', 'local_timadey', $attemptid));
$PAGE->set_heading(get_string('incident_log', 'local_timadey', $attemptid));
$PAGE->navbar->add(get_string('reports', 'local_timadey'), new moodle_url('/local/timadey/report.php'));

echo $OUTPUT->header();

// Back button
echo html_writer::div(
    html_writer::link(new moodle_url('/local/timadey/report.php'), "&larr; Back to Dashboard", ['class' => 'btn btn-outline-primary mb-3'])
);

// Setup Table
$table = new flexible_table('local_timadey_details');
$table->define_columns(['time', 'message', 'severity', 'score']);
$table->define_headers([
    get_string('event_time', 'local_timadey'),
    get_string('message', 'local_timadey'),
    get_string('severity', 'local_timadey'),
    get_string('score_at_time', 'local_timadey')
]);
$table->set_attribute('class', 'generaltable detailtable');
$table->setup();

// Fetch Incidents
$incidents = $DB->get_records('local_timadey_incidents', ['attemptid' => $attemptid], 'eventtime ASC');

if (!$incidents) {
    echo $OUTPUT->notification("No incidents logged for this attempt.", 'info');
} else {
    foreach ($incidents as $incident) {
        $severity_badge = "<span class='badge badge-secondary'>{$incident->severity}</span>";
        if ($incident->severity >= 8) $severity_badge = "<span class='badge badge-danger'>{$incident->severity}</span>";
        else if ($incident->severity >= 5) $severity_badge = "<span class='badge badge-warning'>{$incident->severity}</span>";

        $table->add_data([
            userdate($incident->timecreated, '%H:%M:%S'),
            format_text($incident->message, FORMAT_PLAIN),
            $severity_badge,
            "<strong>{$incident->current_score}</strong>"
        ]);
    }
    $table->finish_output();
}

echo $OUTPUT->footer();
