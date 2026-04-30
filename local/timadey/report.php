<?php
/**
 * Timadey AI Proctoring - Admin Dashboard
 */

require_once('../../config.php');
require_once($CFG->libdir . '/tablelib.php');

// Security: Only admins or users with site config capability
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Page Setup
$url = new moodle_url('/local/timadey/report.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report_title', 'local_timadey'));
$PAGE->set_heading(get_string('report_title', 'local_timadey'));

echo $OUTPUT->header();

// Setup the Table
$table = new flexible_table('local_timadey_reports');
$table->define_columns(['student', 'attemptid', 'final_score', 'max_score', 'status', 'timemodified', 'actions']);
$table->define_headers([
    get_string('student', 'local_timadey'),
    get_string('attempt_id', 'local_timadey'),
    get_string('final_score', 'local_timadey'),
    get_string('max_score', 'local_timadey'),
    get_string('status', 'local_timadey'),
    get_string('event_time', 'local_timadey'),
    ''
]);

$table->sortable(true, 'timemodified', SORT_DESC);
$table->no_sorting('actions');
$table->set_attribute('class', 'generaltable reporttable');
$table->setup();

// Fetch Data
$sql = "SELECT s.*, u.firstname, u.lastname 
        FROM {local_timadey_scores} s
        JOIN {user} u ON s.userid = u.id";

$records = $DB->get_records_sql($sql);

if (!$records) {
    echo $OUTPUT->notification(get_string('no_data', 'local_timadey'), 'info');
} else {
    foreach ($records as $record) {
        $studentname = fullname($record);
        
        // Color code scores
        $score_display = $record->final_score;
        if ($record->final_score > 500) $score_display = "<span style='color:red; font-weight:bold'>{$record->final_score}</span>";
        else if ($record->final_score > 200) $score_display = "<span style='color:orange; font-weight:bold'>{$record->final_score}</span>";

        // Status labels
        $status_label = get_string('status_safe', 'local_timadey');
        if ($record->final_score > 500) $status_label = "<span class='badge badge-danger'>".get_string('status_flagged', 'local_timadey')."</span>";
        else if ($record->final_score > 200) $status_label = "<span class='badge badge-warning'>".get_string('status_review', 'local_timadey')."</span>";
        else $status_label = "<span class='badge badge-success'>".get_string('status_safe', 'local_timadey')."</span>";

        $details_url = new moodle_url('/local/timadey/report_details.php', ['attempt' => $record->attemptid]);
        $actions = html_writer::link($details_url, get_string('view_details', 'local_timadey'), ['class' => 'btn btn-secondary btn-sm']);

        $table->add_data([
            $studentname,
            $record->attemptid,
            $score_display,
            $record->max_score,
            $status_label,
            userdate($record->timemodified),
            $actions
        ]);
    }
    $table->finish_output();
}

echo $OUTPUT->footer();
