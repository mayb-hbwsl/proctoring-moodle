<?php
require_once('../../config.php');
require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}

global $DB, $OUTPUT, $PAGE;

$PAGE->set_url('/local/timadey/incidents.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Proctoring Incidents');

// ── filters ───────────────────────────────────────────────────────────────────
$filter_course  = optional_param('courseid',  0, PARAM_INT);
$filter_quiz    = optional_param('quizid',    0, PARAM_INT);
$filter_student = optional_param('userid',    0, PARAM_INT);
$filter_attempt = optional_param('attemptid', 0, PARAM_INT);

$any_filter = $filter_course || $filter_quiz || $filter_student || $filter_attempt;

// ── build WHERE conditions for the sessions query ─────────────────────────────
$where  = ['i.message != :recstart'];
$params = ['recstart' => '__recording_start__'];

if ($filter_attempt) {
    $where[]             = 'i.attemptid = :aid';
    $params['aid']       = $filter_attempt;
}
if ($filter_student) {
    $where[]             = 'i.userid = :uid';
    $params['uid']       = $filter_student;
}
if ($filter_quiz) {
    $where[]             = 'qa.quiz = :qid';
    $params['qid']       = $filter_quiz;
}
if ($filter_course) {
    $where[]             = 'c.id = :cid';
    $params['cid']       = $filter_course;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── sessions query (join quiz_attempts → quiz → course for context) ───────────
$sessions = $DB->get_records_sql("
    SELECT MIN(i.id) AS id,
           i.userid, i.attemptid,
           COUNT(i.id)        AS total_events,
           MIN(i.timecreated) AS started_at,
           u.firstname, u.lastname,
           q.id   AS quizid,   q.name   AS quizname,
           c.id   AS courseid, c.fullname AS coursename
    FROM {local_timadey_incidents} i
    JOIN {user} u ON u.id = i.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id  = i.attemptid
    LEFT JOIN {quiz}          q  ON q.id   = qa.quiz
    LEFT JOIN {course}        c  ON c.id   = q.course
    $where_sql
    GROUP BY i.userid, i.attemptid, u.firstname, u.lastname,
             q.id, q.name, c.id, c.fullname
    ORDER BY started_at DESC
", $params);

// ── dropdown data (always unfiltered so all options stay visible) ─────────────
$course_list = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname
    FROM {local_timadey_incidents} i
    LEFT JOIN {quiz_attempts} qa ON qa.id = i.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    WHERE c.id IS NOT NULL
    ORDER BY c.fullname ASC
");

// Quizzes: filtered by selected course (if any)
$quiz_params = [];
$quiz_where  = 'WHERE q.id IS NOT NULL';
if ($filter_course) {
    $quiz_where           = 'WHERE c.id = :cid';
    $quiz_params['cid']   = $filter_course;
}
$quiz_list = $DB->get_records_sql("
    SELECT DISTINCT q.id, q.name, c.fullname AS coursename
    FROM {local_timadey_incidents} i
    LEFT JOIN {quiz_attempts} qa ON qa.id = i.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $quiz_where
    ORDER BY q.name ASC
", $quiz_params);

// Students: filtered by selected course + quiz (if any)
$student_params = [];
$student_wheres = [];
if ($filter_course) {
    $student_wheres[]          = 'c.id = :cid';
    $student_params['cid']     = $filter_course;
}
if ($filter_quiz) {
    $student_wheres[]          = 'qa.quiz = :qid';
    $student_params['qid']     = $filter_quiz;
}
$student_where = $student_wheres ? 'WHERE ' . implode(' AND ', $student_wheres) : '';
$student_list = $DB->get_records_sql("
    SELECT DISTINCT u.id, u.firstname, u.lastname
    FROM {local_timadey_incidents} i
    JOIN {user} u ON u.id = i.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id = i.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $student_where
    ORDER BY u.lastname ASC, u.firstname ASC
", $student_params);

// Attempts: filtered by all selected values
$att_wheres = [];
$att_params = [];
if ($filter_course)  { $att_wheres[] = 'c.id = :cid';    $att_params['cid']  = $filter_course; }
if ($filter_quiz)    { $att_wheres[] = 'qa.quiz = :qid';  $att_params['qid']  = $filter_quiz; }
if ($filter_student) { $att_wheres[] = 'i.userid = :uid'; $att_params['uid']  = $filter_student; }
$att_where = $att_wheres ? 'WHERE ' . implode(' AND ', $att_wheres) : '';
$attempt_list = $DB->get_records_sql("
    SELECT DISTINCT i.attemptid, u.firstname, u.lastname,
                    q.name AS quizname
    FROM {local_timadey_incidents} i
    JOIN {user} u ON u.id = i.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id = i.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $att_where
    ORDER BY i.attemptid DESC
", $att_params);

// ── severity helpers ──────────────────────────────────────────────────────────
$severity_label = [
    1 => ['Info',     '#6c757d'],
    3 => ['Low',      '#17a2b8'],
    5 => ['Medium',   '#ffc107'],
    7 => ['High',     '#fd7e14'],
    9 => ['Critical', '#dc3545'],
];

function severity_info($sev, $map) {
    $str_map = ['info' => 1, 'low' => 3, 'medium' => 5, 'high' => 7, 'critical' => 9];
    if (!is_numeric($sev)) {
        $sev = $str_map[strtolower(trim($sev))] ?? 1;
    }
    $sev  = (int)$sev;
    if ($sev <= 0) $sev = 1;
    $best = [1 => ['Info', '#6c757d']];
    foreach ($map as $k => $v) {
        if ($sev >= $k) $best = [$k => $v];
    }
    return array_values($best)[0];
}

// ── page output ───────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo '<script>document.addEventListener("DOMContentLoaded",function(){
    var e=document.querySelector(".page-header-headings");if(e)e.remove();
});</script>';
echo $OUTPUT->heading('Proctoring Incidents');

echo '<style>
.ti-wrap { max-width: 1200px; }
.ti-filter-box { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:16px 18px; margin-bottom:22px; }
.ti-filter-row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.ti-filter-group { display:flex; flex-direction:column; gap:4px; }
.ti-filter-group label { font-size:11px; font-weight:700; color:#495057; text-transform:uppercase; letter-spacing:.04em; }
.ti-filter-group select { min-width:180px; }
.ti-filter-actions { display:flex; gap:8px; align-items:flex-end; padding-bottom:1px; }
.ti-session { border:1px solid #dee2e6; border-radius:8px; margin-bottom:24px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.ti-header { background:#343a40; color:#fff; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; }
.ti-header strong { font-size:15px; }
.ti-meta { font-size:11px; opacity:.65; margin-top:2px; }
.ti-badges { display:flex; gap:8px; flex-wrap:wrap; }
.ti-badge { border-radius:12px; padding:2px 10px; font-size:12px; font-weight:600; color:#fff; }
.ti-summary { background:#f8f9fa; padding:12px 18px; display:flex; flex-wrap:wrap; gap:10px; border-bottom:1px solid #dee2e6; }
.ti-pill { font-size:12px; background:#fff; border:1px solid #dee2e6; border-radius:4px; padding:3px 10px; }
.ti-table { width:100%; border-collapse:collapse; font-size:13px; }
.ti-table th { background:#495057; color:#fff; padding:8px 12px; text-align:left; font-weight:600; }
.ti-table td { padding:7px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.ti-table tr:hover td { background:#fafafa; }
.ti-sev { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; color:#fff; }
.ti-empty { padding:16px 18px; color:#6c757d; font-size:13px; }
</style>';

echo '<div class="ti-wrap">';

// ── filter bar ────────────────────────────────────────────────────────────────
echo '<div class="ti-filter-box">
  <form method="get">
    <div class="ti-filter-row">';

// Course
echo '<div class="ti-filter-group">
  <label>Course</label>
  <select name="courseid" class="form-control form-control-sm">';
echo '<option value="0">All courses</option>';
foreach ($course_list as $c) {
    $sel = $filter_course == $c->id ? ' selected' : '';
    echo '<option value="' . $c->id . '"' . $sel . '>' . htmlspecialchars($c->fullname) . '</option>';
}
echo '</select></div>';

// Quiz
echo '<div class="ti-filter-group">
  <label>Quiz</label>
  <select name="quizid" class="form-control form-control-sm">';
echo '<option value="0">All quizzes</option>';
foreach ($quiz_list as $q) {
    $sel   = $filter_quiz == $q->id ? ' selected' : '';
    $label = htmlspecialchars($q->name);
    if (!$filter_course && !empty($q->coursename)) {
        $label .= ' (' . htmlspecialchars($q->coursename) . ')';
    }
    echo '<option value="' . $q->id . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></div>';

// Student
echo '<div class="ti-filter-group">
  <label>Student</label>
  <select name="userid" class="form-control form-control-sm">';
echo '<option value="0">All students</option>';
foreach ($student_list as $u) {
    $sel = $filter_student == $u->id ? ' selected' : '';
    echo '<option value="' . $u->id . '"' . $sel . '>'
        . htmlspecialchars($u->firstname . ' ' . $u->lastname) . '</option>';
}
echo '</select></div>';

// Attempt
echo '<div class="ti-filter-group">
  <label>Attempt</label>
  <select name="attemptid" class="form-control form-control-sm">';
echo '<option value="0">All attempts</option>';
foreach ($attempt_list as $a) {
    $sel   = $filter_attempt == $a->attemptid ? ' selected' : '';
    $label = 'Attempt #' . $a->attemptid . ' — ' . htmlspecialchars($a->firstname . ' ' . $a->lastname);
    if (!empty($a->quizname)) $label .= ' · ' . htmlspecialchars($a->quizname);
    echo '<option value="' . $a->attemptid . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></div>';

// Buttons
echo '<div class="ti-filter-actions">
  <button type="submit" class="btn btn-sm btn-primary">Apply</button>';
if ($any_filter) {
    echo '<a href="incidents.php" class="btn btn-sm btn-outline-secondary">Clear</a>';
}
echo '</div>';

echo '</div></form></div>'; // .ti-filter-row, form, .ti-filter-box

// ── sessions ──────────────────────────────────────────────────────────────────
if (empty($sessions)) {
    echo $OUTPUT->notification('No incidents found for the selected filters.', 'info');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$str_sev_map = ['info' => 1, 'low' => 3, 'medium' => 5, 'high' => 7, 'critical' => 9];

foreach ($sessions as $s) {
    $events = $DB->get_records_sql("
        SELECT id, message, severity, timecreated
        FROM {local_timadey_incidents}
        WHERE userid = :uid AND attemptid = :aid AND message != '__recording_start__'
        ORDER BY timecreated ASC
    ", ['uid' => $s->userid, 'aid' => $s->attemptid]);

    $name = htmlspecialchars($s->firstname . ' ' . $s->lastname);
    $date = date('d M Y, H:i', $s->started_at);

    // Meta line: course → quiz
    $meta_parts = [];
    if (!empty($s->coursename)) $meta_parts[] = htmlspecialchars($s->coursename);
    if (!empty($s->quizname))   $meta_parts[] = htmlspecialchars($s->quizname);
    $meta_html = $meta_parts ? '<div class="ti-meta">' . implode(' &rsaquo; ', $meta_parts) . '</div>' : '';

    // Count by type and severity
    $type_counts = [];
    $sev_counts  = [];
    foreach ($events as $e) {
        $type_counts[$e->message] = ($type_counts[$e->message] ?? 0) + 1;
        $sev_raw = $e->severity;
        $sev_int = is_numeric($sev_raw)
            ? (int)$sev_raw
            : ($str_sev_map[strtolower(trim($sev_raw))] ?? 1);
        if ($sev_int <= 0) $sev_int = 1;
        $sev_counts[$sev_int] = ($sev_counts[$sev_int] ?? 0) + 1;
    }
    arsort($type_counts);

    // Group counts by resolved label (multiple raw severity integers can map to the same label).
    $label_counts = [];
    $label_color  = [];
    $label_order  = [];
    krsort($sev_counts);
    foreach ($sev_counts as $sev => $cnt) {
        $info  = severity_info($sev, $severity_label);
        $label = $info[0];
        $label_counts[$label] = ($label_counts[$label] ?? 0) + $cnt;
        $label_color[$label]  = $info[1];
        if (!isset($label_order[$label])) $label_order[$label] = $sev;
    }
    arsort($label_order);
    $badge_html = '';
    foreach ($label_order as $label => $sev) {
        $badge_html .= '<span class="ti-badge" style="background:' . $label_color[$label] . '">'
            . $label . ': ' . $label_counts[$label] . '</span>';
    }

    echo '<div class="ti-session">
      <div class="ti-header">
        <div>
          <strong>' . $name . '</strong>
          &nbsp;&nbsp;Attempt #' . (int)$s->attemptid . '
          &nbsp;&nbsp;<span style="opacity:.6">' . $date . '</span>
          ' . $meta_html . '
        </div>
        <div style="display:flex;gap:16px;align-items:center">
          <div class="ti-badges">' . $badge_html . '</div>
          <span style="opacity:.6;font-size:12px">' . array_sum($sev_counts) . ' events</span>
        </div>
      </div>';

    // Top event types
    echo '<div class="ti-summary">';
    $shown = 0;
    foreach ($type_counts as $msg => $cnt) {
        if ($shown++ > 8) { echo '<span class="ti-pill">+more</span>'; break; }
        echo '<span class="ti-pill">' . htmlspecialchars($msg) . ' &times;' . $cnt . '</span>';
    }
    echo '</div>';

    // Event table
    echo '<table class="ti-table">
      <thead><tr>
        <th style="width:90px">Time</th>
        <th style="width:100px">Severity</th>
        <th>Event</th>
      </tr></thead><tbody>';
    foreach ($events as $e) {
        $info = severity_info($e->severity, $severity_label);
        $time = date('H:i:s', $e->timecreated);
        echo '<tr>
          <td style="color:#666">' . $time . '</td>
          <td><span class="ti-sev" style="background:' . $info[1] . '">' . $info[0] . '</span></td>
          <td>' . htmlspecialchars($e->message) . '</td>
        </tr>';
    }
    echo '</tbody></table>';

    echo '</div>'; // .ti-session
}

echo '</div>'; // .ti-wrap
echo $OUTPUT->footer();
