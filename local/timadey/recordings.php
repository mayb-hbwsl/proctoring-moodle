<?php
require_once('../../config.php');
require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}

global $DB, $CFG, $OUTPUT, $PAGE;

$PAGE->set_url('/local/timadey/recordings.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Proctoring Recordings');

$tmp_web = '/local/timadey/assets/tmp';
$tmp_dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0755, true);

// ── helpers ──────────────────────────────────────────────────────────────────

function timadey_find_ffmpeg() {
    // Admin-configured path takes priority.
    $configured = get_config('local_timadey', 'ffmpeg_path');
    if (!empty($configured) && file_exists($configured)) return $configured;

    $candidates = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/ffmpeg/bin/ffmpeg',
        'C:/ffmpeg/bin/ffmpeg.exe',
        'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
    ];
    foreach ($candidates as $p) {
        if (file_exists($p)) return $p;
    }
    $out = []; $ret = 1;
    @exec('which ffmpeg 2>/dev/null', $out, $ret);
    if ($ret === 0 && !empty($out[0]) && file_exists(trim($out[0]))) return trim($out[0]);
    return null;
}

// Returns true if file starts with WebM EBML magic bytes
function timadey_is_webm($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $h = fread($fh, 4);
    fclose($fh);
    return $h === "\x1a\x45\xdf\xa3";
}

function timadey_ffmpeg_cmd($ffmpeg, $args) {
    $ff = str_replace('\\', '/', $ffmpeg);
    exec(escapeshellarg($ff) . ' ' . $args . ' 2>&1', $out, $ret);
    return $ret === 0;
}

function timadey_write_filelist($path, array $files) {
    // Must be written as UTF-8 without BOM — FFmpeg rejects BOM as invalid keyword.
    $lines = '';
    foreach ($files as $f) {
        $lines .= "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $f)) . "'\n";
    }
    file_put_contents($path, $lines);
}

// Merge chunks into a single seekable WebM
function timadey_merge($ffmpeg, $chunk_paths, $output) {
    $total_src = array_sum(array_map('filesize', $chunk_paths));

    if (count($chunk_paths) === 1) {
        copy($chunk_paths[0], $output);
    } elseif (count($chunk_paths) > 1 && timadey_is_webm($chunk_paths[1])) {
        // New-style: every chunk is standalone WebM → FFmpeg concat demuxer
        $listfile = $output . '.txt';
        timadey_write_filelist($listfile, $chunk_paths);
        $ok = timadey_ffmpeg_cmd($ffmpeg,
            '-y -f concat -safe 0'
            . ' -i ' . escapeshellarg(str_replace('\\', '/', $listfile))
            . ' -c copy -cues_to_front 1 '
            . escapeshellarg(str_replace('\\', '/', $output))
        );
        @unlink($listfile);
        if (!$ok) return false;
        // Sanity: merged file should not be dramatically larger than source (indicates bad concat)
        if (file_exists($output) && filesize($output) > $total_src * 1.3) {
            @unlink($output);
            return false;
        }
    } else {
        // Old-style: chunk 0 has WebM header, rest are raw clusters → raw cat then re-mux
        $tmp_cat = $output . '.cat.webm';
        $fh_out  = fopen($tmp_cat, 'wb');
        foreach ($chunk_paths as $p) {
            $fh_in = fopen($p, 'rb');
            if ($fh_in) { stream_copy_to_stream($fh_in, $fh_out); fclose($fh_in); }
        }
        fclose($fh_out);
        // Re-mux through FFmpeg to add cues so seeking works in browser
        $ok = timadey_ffmpeg_cmd($ffmpeg,
            '-y -i ' . escapeshellarg(str_replace('\\', '/', $tmp_cat))
            . ' -c copy -cues_to_front 1 '
            . escapeshellarg(str_replace('\\', '/', $output))
        );
        @unlink($tmp_cat);
        if (!$ok) return false;
    }
    return file_exists($output) && filesize($output) > 0;
}

// Get video duration in seconds via FFmpeg decode. Result cached in a .dur sidecar file.
function timadey_get_duration($ffmpeg, $path) {
    if (!$ffmpeg || !file_exists($path)) return 0;
    $cache = $path . '.dur';
    if (file_exists($cache) && filemtime($cache) >= filemtime($path)) {
        $cached = (int)file_get_contents($cache);
        // Discard obviously-wrong cached values (0 or 1 second for a real recording file).
        // filesize > 50KB but duration <= 1s means FFmpeg failed previously — re-probe.
        if ($cached > 1 || filesize($path) < 51200) return $cached;
        @unlink($cache);
    }
    $null = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $ff   = str_replace('\\', '/', $ffmpeg);
    $cmd  = escapeshellarg($ff)
          . ' -i ' . escapeshellarg(str_replace('\\', '/', $path))
          . ' -f null -c copy ' . $null . ' 2>&1';
    exec($cmd, $out);
    $dur = 0;
    foreach (array_reverse($out) as $line) {
        if (preg_match('/time=(\d+):(\d+):(\d+\.?\d*)/', $line, $m)) {
            $dur = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)floor((float)$m[3]);
            break;
        }
    }
    if ($dur > 0) file_put_contents($cache, $dur);
    return $dur;
}

function timadey_fmt($secs) {
    if ($secs < 0) $secs = 0;
    $h = floor($secs / 3600);
    $m = floor(($secs % 3600) / 60);
    $s = $secs % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

// ── filters ───────────────────────────────────────────────────────────────────

$filter_course  = optional_param('courseid',  0, PARAM_INT);
$filter_quiz    = optional_param('quizid',    0, PARAM_INT);
$filter_student = optional_param('userid',    0, PARAM_INT);
$filter_attempt = optional_param('attemptid', 0, PARAM_INT);
$any_filter     = $filter_course || $filter_quiz || $filter_student || $filter_attempt;

// ── data ─────────────────────────────────────────────────────────────────────

$ffmpeg = timadey_find_ffmpeg();

// Build WHERE for the main sessions query.
$where  = [];
$params = [];
if ($filter_attempt) { $where[] = 'r.attemptid = :aid'; $params['aid'] = $filter_attempt; }
if ($filter_student) { $where[] = 'r.userid = :uid';    $params['uid'] = $filter_student; }
if ($filter_quiz)    { $where[] = 'qa.quiz = :qid';     $params['qid'] = $filter_quiz; }
if ($filter_course)  { $where[] = 'c.id = :cid';        $params['cid'] = $filter_course; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$db_sessions = $DB->get_records_sql("
    SELECT MIN(r.id) AS id, r.userid, r.attemptid,
           COUNT(r.id)        AS chunk_count,
           SUM(r.filesize)    AS total_size,
           MIN(r.timecreated) AS rec_start,
           MAX(r.timecreated) AS last_chunk_at,
           u.firstname, u.lastname,
           q.id   AS quizid,   q.name   AS quizname,
           c.id   AS courseid, c.fullname AS coursename
    FROM {local_timadey_recordings} r
    JOIN {user} u ON u.id = r.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id = r.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $where_sql
    GROUP BY r.userid, r.attemptid, u.firstname, u.lastname,
             q.id, q.name, c.id, c.fullname
    ORDER BY rec_start DESC
", $params);

// Build lookup set of DB-covered (userid, attemptid) pairs.
$db_keys = [];
foreach ($db_sessions as $s) {
    $db_keys[$s->userid . '_' . $s->attemptid] = true;
}

// Scan filesystem for recordings with no DB record.
// Skip filesystem scan when course/quiz filter is active (no metadata available).
$fs_extra = [];
if (!$filter_course && !$filter_quiz) {
    $rec_root = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings';
    if (is_dir($rec_root)) {
        foreach (glob($rec_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $udir) {
            $uid = (int)basename($udir);
            if ($uid <= 0) continue;
            if ($filter_student && $uid !== $filter_student) continue;
            foreach (glob($udir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $adir) {
                $aid = (int)basename($adir);
                if ($filter_attempt && $aid !== $filter_attempt) continue;
                $key = $uid . '_' . $aid;
                if (isset($db_keys[$key])) continue;
                $chunks = glob($adir . DIRECTORY_SEPARATOR . 'chunk_*.webm');
                if (empty($chunks)) continue;
                $mtimes   = array_map('filemtime', $chunks);
                $fsizes   = array_map('filesize',  $chunks);
                $user_obj = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname');
                $s               = new stdClass();
                $s->id           = 0;
                $s->userid       = $uid;
                $s->attemptid    = $aid;
                $s->chunk_count  = count($chunks);
                $s->total_size   = array_sum($fsizes);
                $s->rec_start    = min($mtimes);
                $s->last_chunk_at = max($mtimes);
                $s->firstname    = $user_obj ? $user_obj->firstname : 'User';
                $s->lastname     = $user_obj ? $user_obj->lastname  : $uid;
                $s->quizid       = null;
                $s->quizname     = null;
                $s->courseid     = null;
                $s->coursename   = null;
                $fs_extra[$key]  = $s;
            }
        }
    }
}

$sessions = array_values($db_sessions);
foreach ($fs_extra as $s) { $sessions[] = $s; }
usort($sessions, function($a, $b) { return $b->rec_start - $a->rec_start; });

// ── dropdown data ─────────────────────────────────────────────────────────────

$course_list = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname
    FROM {local_timadey_recordings} r
    LEFT JOIN {quiz_attempts} qa ON qa.id = r.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    WHERE c.id IS NOT NULL
    ORDER BY c.fullname ASC
");

$quiz_params = [];
$quiz_where  = 'WHERE q.id IS NOT NULL';
if ($filter_course) { $quiz_where = 'WHERE c.id = :cid'; $quiz_params['cid'] = $filter_course; }
$quiz_list = $DB->get_records_sql("
    SELECT DISTINCT q.id, q.name, c.fullname AS coursename
    FROM {local_timadey_recordings} r
    LEFT JOIN {quiz_attempts} qa ON qa.id = r.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $quiz_where
    ORDER BY q.name ASC
", $quiz_params);

$student_wheres = []; $student_params = [];
if ($filter_course) { $student_wheres[] = 'c.id = :cid';    $student_params['cid'] = $filter_course; }
if ($filter_quiz)   { $student_wheres[] = 'qa.quiz = :qid'; $student_params['qid'] = $filter_quiz; }
$student_where = $student_wheres ? 'WHERE ' . implode(' AND ', $student_wheres) : '';
$student_list = $DB->get_records_sql("
    SELECT DISTINCT u.id, u.firstname, u.lastname
    FROM {local_timadey_recordings} r
    JOIN {user} u ON u.id = r.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id = r.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $student_where
    ORDER BY u.lastname ASC, u.firstname ASC
", $student_params);

$att_wheres = []; $att_params = [];
if ($filter_course)  { $att_wheres[] = 'c.id = :cid';    $att_params['cid'] = $filter_course; }
if ($filter_quiz)    { $att_wheres[] = 'qa.quiz = :qid';  $att_params['qid'] = $filter_quiz; }
if ($filter_student) { $att_wheres[] = 'r.userid = :uid'; $att_params['uid'] = $filter_student; }
$att_where = $att_wheres ? 'WHERE ' . implode(' AND ', $att_wheres) : '';
$attempt_list = $DB->get_records_sql("
    SELECT DISTINCT r.attemptid, u.firstname, u.lastname, q.name AS quizname
    FROM {local_timadey_recordings} r
    JOIN {user} u ON u.id = r.userid
    LEFT JOIN {quiz_attempts} qa ON qa.id = r.attemptid
    LEFT JOIN {quiz}          q  ON q.id  = qa.quiz
    LEFT JOIN {course}        c  ON c.id  = q.course
    $att_where
    ORDER BY r.attemptid DESC
", $att_params);

// ── output ───────────────────────────────────────────────────────────────────

echo $OUTPUT->header();
echo '<script>document.addEventListener("DOMContentLoaded",function(){
    var e=document.querySelector(".page-header-headings");if(e)e.remove();
});</script>';
echo $OUTPUT->heading('Proctoring Recordings');

echo '<style>
.tr-wrap{max-width:1200px}
.tr-filter-box{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:16px 18px;margin-bottom:22px}
.tr-filter-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.tr-filter-group{display:flex;flex-direction:column;gap:4px}
.tr-filter-group label{font-size:11px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.04em}
.tr-filter-group select{min-width:180px}
.tr-filter-actions{display:flex;gap:8px;align-items:flex-end;padding-bottom:1px}
.tr-session{border:1px solid #dee2e6;border-radius:8px;margin-bottom:24px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.tr-header{background:#1a1d23;color:#fff;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;font-size:14px}
.tr-header strong{font-size:15px}
.tr-meta{font-size:11px;opacity:.6;margin-top:2px}
.tr-badge{background:rgba(255,255,255,.12);border-radius:12px;padding:2px 10px;font-size:12px}
.tr-body{display:flex;background:#111;gap:0}
.tr-video-side{flex:0 0 65%;min-width:0;background:#000}
.tr-video-side video{display:block;width:100%;max-height:480px;object-fit:contain}
.tr-log-side{flex:0 0 35%;background:#1a1d23;overflow-y:scroll;border-left:1px solid #2d3139;height:480px}
.tr-log-header{padding:10px 14px;font-size:12px;font-weight:700;color:#adb5bd;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #2d3139;position:sticky;top:0;background:#1a1d23;z-index:1}
.tr-log-item{display:flex;align-items:flex-start;gap:8px;padding:7px 12px 7px 10px;border-bottom:1px solid #23272f;cursor:pointer;transition:background .12s;border-left:3px solid #2d3139}
.tr-log-item:hover{background:rgba(255,255,255,.04)}
.tr-log-item.active{background:rgba(52,152,219,.15) !important;border-left-color:#3498db !important}
.tr-log-time{flex-shrink:0;font-size:11px;font-weight:700;color:#3498db;min-width:36px;font-family:monospace;padding-top:1px}
.tr-log-sev{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;color:#fff;white-space:nowrap}
.tr-log-msg{font-size:12px;color:#cdd3db;line-height:1.35;word-break:break-all}
.tr-actions{background:#111;padding:8px 12px;display:flex;gap:8px;border-top:1px solid #2d3139}
.tr-actions a{font-size:12px}
.tr-notice{padding:16px;font-size:13px;color:#adb5bd}
.tr-no-video{display:flex;align-items:center;justify-content:center;height:480px;color:#6c757d;font-size:13px}
</style>';

echo '<div class="tr-wrap">';

// ── filter bar ────────────────────────────────────────────────────────────────
echo '<div class="tr-filter-box">
  <form method="get">
    <div class="tr-filter-row">';

// Course
echo '<div class="tr-filter-group">
  <label>Course</label>
  <select name="courseid" class="form-control form-control-sm">';
echo '<option value="0">All courses</option>';
foreach ($course_list as $c) {
    $sel = $filter_course == $c->id ? ' selected' : '';
    echo '<option value="' . $c->id . '"' . $sel . '>' . htmlspecialchars($c->fullname) . '</option>';
}
echo '</select></div>';

// Quiz
echo '<div class="tr-filter-group">
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
echo '<div class="tr-filter-group">
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
echo '<div class="tr-filter-group">
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
echo '<div class="tr-filter-actions">
  <button type="submit" class="btn btn-sm btn-primary">Apply</button>';
if ($any_filter) {
    echo '<a href="recordings.php" class="btn btn-sm btn-outline-secondary">Clear</a>';
}
echo '</div>';

echo '</div></form></div>'; // .tr-filter-row, form, .tr-filter-box

if (empty($sessions)) {
    echo $OUTPUT->notification('No recordings found for the selected filters.', 'info');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$sev_map = [
    1 => ['Info',     '#6c757d'],
    3 => ['Low',      '#17a2b8'],
    5 => ['Medium',   '#ffc107'],
    7 => ['High',     '#fd7e14'],
    9 => ['Critical', '#dc3545'],
];
function timadey_sev_info($sev, $map) {
    $r = ['Info', '#6c757d'];
    foreach ($map as $k => $v) { if ($sev >= $k) $r = $v; }
    return $r;
}


foreach ($sessions as $s) {
    $all_chunk_rows = $DB->get_records('local_timadey_recordings',
        ['userid' => $s->userid, 'attemptid' => $s->attemptid], 'chunkindex ASC, id DESC');

    // Deduplicate by chunkindex — keep only the record with the largest filesize per index
    // (guards against duplicate inserts that would cause FFmpeg to double-concat a chunk).
    $seen_idx = [];
    $chunks   = [];
    foreach ($all_chunk_rows as $row) {
        $idx = (int)$row->chunkindex;
        if (!isset($seen_idx[$idx]) || $row->filesize > $seen_idx[$idx]->filesize) {
            $seen_idx[$idx] = $row;
        }
    }
    ksort($seen_idx);
    $chunks = array_values($seen_idx);

    // For filesystem-only sessions (no DB records), synthesise chunk objects from disk.
    if (empty($chunks)) {
        $adir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
              . DIRECTORY_SEPARATOR . $s->userid . DIRECTORY_SEPARATOR . $s->attemptid;
        $disk_files = glob($adir . DIRECTORY_SEPARATOR . 'chunk_*.webm');
        if ($disk_files) {
            natsort($disk_files);
            foreach ($disk_files as $df) {
                if (preg_match('/chunk_(\d{4})\.webm$/', basename($df), $m)) {
                    $c              = new stdClass();
                    $c->userid      = $s->userid;
                    $c->attemptid   = $s->attemptid;
                    $c->chunkindex  = (int)$m[1];
                    $rel            = 'timadey_recordings/' . $s->userid . '/' . $s->attemptid . '/' . basename($df);
                    $c->filepath    = $rel;
                    $c->filesize    = filesize($df);
                    $chunks[]       = $c;
                }
            }
        }
    }

    $incidents = $DB->get_records_sql("
        SELECT id, message, severity, eventtime, timecreated
        FROM {local_timadey_incidents}
        WHERE userid = :uid AND attemptid = :aid
        ORDER BY timecreated ASC
    ", ['uid' => $s->userid, 'aid' => $s->attemptid]);

    $total_mb  = round($s->total_size / 1024 / 1024, 1);
    $name      = htmlspecialchars($s->firstname . ' ' . $s->lastname);
    $vid_id    = 'vid_' . (int)$s->attemptid;

    // ── build / merge the video FIRST so we can get its duration ─────────────

    $chunk_paths = [];
    foreach ($chunks as $chunk) {
        $pub = $chunk->userid . '_' . $chunk->attemptid . '_' . $chunk->chunkindex . '.webm';
        $pub_path = $tmp_dir . DIRECTORY_SEPARATOR . $pub;
        if (!file_exists($pub_path)) {
            $src = $CFG->dataroot . DIRECTORY_SEPARATOR
                 . str_replace('/', DIRECTORY_SEPARATOR, $chunk->filepath);
            if (file_exists($src)) @copy($src, $pub_path);
        }
        if (file_exists($pub_path)) $chunk_paths[] = $pub_path;
    }

    $merged_name = $s->userid . '_' . $s->attemptid . '_merged.webm';
    $merged_path = $tmp_dir . DIRECTORY_SEPARATOR . $merged_name;
    $merged_url  = $tmp_web . '/' . $merged_name;
    $total_chunk_size = array_sum(array_map(function($p){ return file_exists($p) ? filesize($p) : 0; }, $chunk_paths));
    $need_merge  = !file_exists($merged_path)
        || filemtime($merged_path) < $s->last_chunk_at
        || (count($chunk_paths) > 1 && file_exists($merged_path) && filesize($merged_path) > $total_chunk_size * 1.3);

    $video_ok = false;
    if (count($chunk_paths) === 0) {
        // no files — will show placeholder later
    } elseif ($need_merge) {
        if ($ffmpeg) {
            $video_ok = timadey_merge($ffmpeg, $chunk_paths, $merged_path);
        }
        if (!$video_ok && file_exists($chunk_paths[0])) {
            $merged_name = basename($chunk_paths[0]);
            $merged_path = $tmp_dir . DIRECTORY_SEPARATOR . $merged_name;
            $merged_url  = $tmp_web . '/' . $merged_name;
            $video_ok    = true;
        }
    } else {
        $video_ok = true;
    }

    // ── compute duration & the true video-start reference ───────────────────
    $duration = $video_ok ? timadey_get_duration($ffmpeg, $merged_path) : 0;

    // Prefer the __recording_start__ marker sent by recorder.js the instant
    // MediaRecorder.start() is called — same JS clock as every eventtime, so
    // offsets are frame-accurate.  Fall back to rec_start-10s for old recordings.
    $rec_start_row = $DB->get_record_sql("
        SELECT eventtime
        FROM {local_timadey_incidents}
        WHERE userid = :uid AND attemptid = :aid AND message = '__recording_start__'
        ORDER BY timecreated ASC
        LIMIT 1
    ", ['uid' => $s->userid, 'aid' => $s->attemptid]);

    // video_start_ms: JS epoch ms when the camera actually started recording.
    if ($rec_start_row && $rec_start_row->eventtime > 0) {
        $video_start_ms = (float)$rec_start_row->eventtime;
    } else {
        // Fallback: first chunk was uploaded ~10 s after recording began (TIMESLICE).
        $video_start_ms = ((float)$s->rec_start - 10.0) * 1000.0;
    }

    $date = date('d M Y, H:i', (int)($video_start_ms / 1000));

    // Meta: Course › Quiz breadcrumb
    $meta_parts = [];
    if (!empty($s->coursename)) $meta_parts[] = htmlspecialchars($s->coursename);
    if (!empty($s->quizname))   $meta_parts[] = htmlspecialchars($s->quizname);
    $meta_html = $meta_parts
        ? '<div class="tr-meta">' . implode(' &rsaquo; ', $meta_parts) . '</div>'
        : '';

    // ── render header ────────────────────────────────────────────────────────

    echo '<div class="tr-session">
        <div class="tr-header">
            <div>
                <strong>' . $name . '</strong>
                &nbsp;&nbsp;Attempt #' . (int)$s->attemptid . '
                &nbsp;&nbsp;<span style="opacity:.55">' . $date . '</span>
                ' . $meta_html . '
            </div>
            <span class="tr-badge">' . count($chunks) . ' clip(s) &middot; ' . $total_mb . ' MB'
                . ($duration > 0 ? ' &middot; ' . timadey_fmt($duration) : '') . '</span>
        </div>
        <div class="tr-body">';

    // ── video side ────────────────────────────────────────────────────────────

    if (count($chunk_paths) === 0) {
        echo '<div class="tr-video-side"><div class="tr-no-video">Recording files deleted (24h retention)</div></div>';
    } elseif ($video_ok) {
        echo '<div class="tr-video-side">
            <video id="' . $vid_id . '" controls preload="auto" style="width:100%;max-height:480px;background:#000;display:block">
                <source src="' . $merged_url . '" type="video/webm">
            </video>
          </div>';
    } else {
        echo '<div class="tr-video-side"><div class="tr-no-video">Merge failed — FFmpeg not available</div></div>';
    }

    // ── log side ──────────────────────────────────────────────────────────────

    echo '<div class="tr-log-side" id="log_' . (int)$s->attemptid . '">';
    // Count displayable events (exclude the internal recording-start marker)
    $display_count = 0;
    foreach ($incidents as $inc) {
        if ($inc->message !== '__recording_start__') $display_count++;
    }
    echo '<div class="tr-log-header">Events (' . $display_count . ')</div>';

    if ($display_count === 0) {
        echo '<div class="tr-notice">No incidents recorded for this session.</div>';
    } else {
        foreach ($incidents as $inc) {
            if ($inc->message === '__recording_start__') continue;

            // Use eventtime (JS epoch ms, same clock as the recording) for the offset.
            // Fall back to timecreated*1000 for incidents from before the eventtime fix.
            $inc_ms  = ($inc->eventtime > 0) ? (float)$inc->eventtime : (float)$inc->timecreated * 1000.0;
            $raw_ms  = $inc_ms - $video_start_ms;
            $offset  = max(0, (int)($raw_ms / 1000));
            if ($duration > 0) {
                $offset = min($offset, $duration);
            }
            $ts      = timadey_fmt($offset);
            $sevinfo = timadey_sev_info((int)$inc->severity, $sev_map);
            $msg     = htmlspecialchars($inc->message);
            echo '<div class="tr-log-item" data-t="' . $offset . '" data-vid="' . $vid_id . '"
                      onclick="tdSeek(this)">'
                . '<span class="tr-log-time">' . $ts . '</span>'
                . '<span class="tr-log-sev" style="background:' . $sevinfo[1] . '">' . $sevinfo[0] . '</span>'
                . '<span class="tr-log-msg">' . $msg . '</span>'
              . '</div>';
        }
    }
    echo '</div>'; // .tr-log-side

    echo '</div>'; // .tr-body

    // ── actions ───────────────────────────────────────────────────────────────
    if ($video_ok) {
        echo '<div class="tr-actions">
            <a href="' . $merged_url . '" target="_blank" class="btn btn-sm btn-outline-light">&#9654; Open</a>
            <a href="' . $merged_url . '" download="' . $merged_name . '" class="btn btn-sm btn-outline-light">&#11015; Download</a>
          </div>';
    }

    echo '</div>'; // .tr-session
}

// ── JavaScript ────────────────────────────────────────────────────────────────
?>
<script>
function tdSeek(item) {
    var vid = document.getElementById(item.dataset.vid);
    if (!vid) return;
    var t = parseFloat(item.dataset.t);
    vid.currentTime = t;
    vid.play();
}

// Highlight log entry matching current video time, auto-scroll unless user is scrolling
(function() {
    var lastTick = {}, userScrolling = {}, scrollTimer = {};

    document.querySelectorAll('video[id^="vid_"]').forEach(function(vid) {
        var logId = 'log_' + vid.id.replace('vid_', '');
        var log   = document.getElementById(logId);
        if (!log) return;

        // Track manual scrolling — pause auto-scroll for 2.5 s after user touches scroll
        log.addEventListener('scroll', function() {
            userScrolling[logId] = true;
            clearTimeout(scrollTimer[logId]);
            scrollTimer[logId] = setTimeout(function() { userScrolling[logId] = false; }, 2500);
        }, {passive: true});

        vid.addEventListener('timeupdate', function() {
            // Throttle to once per second
            var now = Math.floor(vid.currentTime);
            if (lastTick[vid.id] === now) return;
            lastTick[vid.id] = now;

            var items = log.querySelectorAll('.tr-log-item');
            var best = null, bestDiff = Infinity;
            items.forEach(function(el) {
                var diff = now - parseInt(el.dataset.t, 10);
                if (diff >= 0 && diff < bestDiff) { bestDiff = diff; best = el; }
            });
            items.forEach(function(el) { el.classList.remove('active'); });
            if (!best) return;
            best.classList.add('active');

            if (!userScrolling[logId]) {
                var logRect  = log.getBoundingClientRect();
                var itemRect = best.getBoundingClientRect();
                if (itemRect.top < logRect.top || itemRect.bottom > logRect.bottom) {
                    best.scrollIntoView({block: 'nearest', behavior: 'smooth'});
                }
            }
        });
    });
}());

</script>
<?php
echo '</div>'; // .tr-wrap
echo $OUTPUT->footer();
