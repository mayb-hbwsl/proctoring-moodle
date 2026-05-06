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


// Optional filter by attemptid
$filter_attempt = optional_param('attemptid', 0, PARAM_INT);

$sessions = $DB->get_records_sql("
    SELECT MIN(i.id) AS id, i.userid, i.attemptid,
           COUNT(i.id)        AS total_events,
           MIN(i.timecreated) AS started_at,
           u.firstname, u.lastname
    FROM {local_timadey_incidents} i
    JOIN {user} u ON u.id = i.userid
    " . ($filter_attempt ? "WHERE i.attemptid = :aid" : "") . "
    GROUP BY i.userid, i.attemptid, u.firstname, u.lastname
    ORDER BY started_at DESC
", $filter_attempt ? ['aid' => $filter_attempt] : []);

$severity_label = [
    1 => ['Info',     '#6c757d'],
    3 => ['Low',      '#17a2b8'],
    5 => ['Medium',   '#ffc107'],
    7 => ['High',     '#fd7e14'],
    9 => ['Critical', '#dc3545'],
];

function severity_info($sev, $map) {
    // Handle string severities that may have been stored before the mapping fix
    $str_map = ['info' => 1, 'low' => 3, 'medium' => 5, 'high' => 7, 'critical' => 9];
    if (!is_numeric($sev)) {
        $sev = $str_map[strtolower(trim($sev))] ?? 1;
    }
    $sev = (int)$sev;
    if ($sev <= 0) $sev = 1;
    $best = [1 => ['Info', '#6c757d']];
    foreach ($map as $k => $v) {
        if ($sev >= $k) $best = [$k => $v];
    }
    return array_values($best)[0];
}

echo $OUTPUT->header();
echo '<script>document.addEventListener("DOMContentLoaded",function(){var e=document.querySelector(".page-header-headings");if(e)e.remove();});</script>';

echo $OUTPUT->heading('Proctoring Incidents');

echo '<style>
.ti-wrap { max-width: 1100px; }
.ti-filter { margin-bottom: 18px; display:flex; gap:10px; align-items:center; }
.ti-session { border:1px solid #dee2e6; border-radius:8px; margin-bottom:24px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.ti-header { background:#343a40; color:#fff; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; }
.ti-header strong { font-size:15px; }
.ti-header a { color:#adb5bd; font-size:12px; text-decoration:none; }
.ti-header a:hover { color:#fff; }
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

// Filter bar
$attempt_list = $DB->get_records_sql("
    SELECT MIN(i.id) AS id, i.attemptid, u.firstname, u.lastname
    FROM {local_timadey_incidents} i
    JOIN {user} u ON u.id = i.userid
    GROUP BY i.attemptid, u.firstname, u.lastname
    ORDER BY i.attemptid DESC
");

echo '<div class="ti-wrap">';
echo '<div class="ti-filter">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <label style="font-size:13px;font-weight:600">Filter by attempt:</label>
        <select name="attemptid" class="form-control form-control-sm" style="width:220px">';
echo '<option value="0"' . ($filter_attempt == 0 ? ' selected' : '') . '>All attempts</option>';
foreach ($attempt_list as $a) {
    $label = 'Attempt #' . $a->attemptid . ' — ' . htmlspecialchars($a->firstname . ' ' . $a->lastname);
    echo '<option value="' . $a->attemptid . '"' . ($filter_attempt == $a->attemptid ? ' selected' : '') . '>'
        . $label . '</option>';
}
echo '</select>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>';
if ($filter_attempt) {
    echo '<a href="incidents.php" class="btn btn-sm btn-outline-secondary">Clear</a>';
}
echo '</form></div>';

if (empty($sessions)) {
    echo $OUTPUT->notification('No incidents recorded yet.', 'info');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

foreach ($sessions as $s) {
    $events = $DB->get_records('local_timadey_incidents',
        ['userid' => $s->userid, 'attemptid' => $s->attemptid],
        'timecreated ASC'
    );

    $name = htmlspecialchars($s->firstname . ' ' . $s->lastname);
    $date = date('d M Y, H:i', $s->started_at);

    // Count by event type
    $type_counts = [];
    $sev_counts  = [];
    foreach ($events as $e) {
        $type_counts[$e->message] = ($type_counts[$e->message] ?? 0) + 1;
        $sev_counts[$e->severity] = ($sev_counts[$e->severity] ?? 0) + 1;
    }
    arsort($type_counts);

    // Severity breakdown badges
    $badge_html = '';
    krsort($sev_counts);
    foreach ($sev_counts as $sev => $cnt) {
        $info  = severity_info($sev, $severity_label);
        $badge_html .= '<span class="ti-badge" style="background:' . $info[1] . '">'
            . $info[0] . ': ' . $cnt . '</span>';
    }

    echo '<div class="ti-session">
        <div class="ti-header">
            <div>
                <strong>' . $name . '</strong>
                &nbsp;&nbsp;Attempt #' . (int)$s->attemptid . '
                &nbsp;&nbsp;<span style="opacity:.6">' . $date . '</span>
            </div>
            <div style="display:flex;gap:16px;align-items:center">
                <div class="ti-badges">' . $badge_html . '</div>
                <span style="opacity:.6;font-size:12px">' . count($events) . ' total</span>
            </div>
        </div>';

    // Top event types summary
    echo '<div class="ti-summary">';
    $shown = 0;
    foreach ($type_counts as $msg => $cnt) {
        if ($shown++ > 8) { echo '<span class="ti-pill">+more</span>'; break; }
        echo '<span class="ti-pill">' . htmlspecialchars($msg) . ' &times;' . $cnt . '</span>';
    }
    echo '</div>';

    // Event table
    if (empty($events)) {
        echo '<div class="ti-empty">No events.</div>';
    } else {
        echo '<table class="ti-table">
            <thead><tr>
                <th style="width:90px">Time</th>
                <th style="width:100px">Severity</th>
                <th>Event</th>
            </tr></thead><tbody>';
        foreach ($events as $e) {
            $info  = severity_info($e->severity, $severity_label);
            $time  = date('H:i:s', $e->timecreated);
            echo '<tr>
                <td style="color:#666">' . $time . '</td>
                <td><span class="ti-sev" style="background:' . $info[1] . '">' . $info[0] . '</span></td>
                <td>' . htmlspecialchars($e->message) . '</td>
              </tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>'; // .ti-session
}

echo '</div>'; // .ti-wrap
echo $OUTPUT->footer();
