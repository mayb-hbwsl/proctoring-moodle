<?php
require_once('../../config.php');
require_login();

global $CFG, $USER, $DB;

// GET ?mode=status — recorder.js calls this before starting to find the next chunk index,
// so a "Return to quiz" session never overwrites chunks from the previous session.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_GET['sesskey']) || $_GET['sesskey'] !== sesskey()) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid session key']));
    }
    $attemptid = intval($_GET['attemptid'] ?? 0);

    // Check DB for highest recorded chunk index.
    $db_max = $DB->get_field_sql(
        "SELECT MAX(chunkindex) FROM {local_timadey_recordings}
          WHERE userid = :uid AND attemptid = :aid",
        ['uid' => $USER->id, 'aid' => $attemptid]
    );
    $db_next = ($db_max !== null && $db_max !== false) ? (int)$db_max + 1 : 0;

    // Also scan the filesystem — guards against silent DB insert failures so
    // "Return to quiz" can never overwrite a chunk that is already on disk.
    $rec_dir  = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
              . DIRECTORY_SEPARATOR . $USER->id . DIRECTORY_SEPARATOR . $attemptid;
    $fs_max   = -1;
    if (is_dir($rec_dir)) {
        foreach (glob($rec_dir . DIRECTORY_SEPARATOR . 'chunk_*.webm') as $f) {
            if (preg_match('/chunk_(\d{4})\.webm$/', basename($f), $m)) {
                $fs_max = max($fs_max, (int)$m[1]);
            }
        }
    }
    $fs_next = $fs_max >= 0 ? $fs_max + 1 : 0;

    $next = max($db_next, $fs_next);
    echo json_encode(['next_chunk' => $next, 'is_resume' => $next > 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

if (empty($_POST['sesskey']) || $_POST['sesskey'] !== sesskey()) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid session key']));
}

if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die(json_encode(['error' => 'No chunk received']));
}

$attemptid  = intval($_POST['attemptid']   ?? 0);
$chunkindex = intval($_POST['chunk_index'] ?? 0);
$append     = !empty($_POST['append']) && $_POST['append'] === '1';

$dir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
     . DIRECTORY_SEPARATOR . $USER->id
     . DIRECTORY_SEPARATOR . $attemptid;

make_writable_directory($dir);

$filename = 'chunk_' . str_pad($chunkindex, 4, '0', STR_PAD_LEFT) . '.webm';
$filepath = $dir . DIRECTORY_SEPARATOR . $filename;

// Safety: if the file already has substantial content and this upload claims to be
// the first blob (append=false), treat it as an append instead of overwriting.
// This guards against the client sending a wrong chunk index on page resume.
if (!$append && file_exists($filepath) && filesize($filepath) > 10240) {
    $append = true;
}

if ($append && file_exists($filepath)) {
    // Append continuation WebM clusters to the existing file.
    $data = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($data === false || file_put_contents($filepath, $data, FILE_APPEND) === false) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to append chunk']));
    }
    @unlink($_FILES['chunk']['tmp_name']);

    // Keep DB filesize accurate so the admin view shows the real size.
    clearstatcache(true, $filepath);
    $DB->execute(
        "UPDATE {local_timadey_recordings} SET filesize = :sz
          WHERE userid = :uid AND attemptid = :aid AND chunkindex = :ci",
        ['sz' => filesize($filepath), 'uid' => $USER->id, 'aid' => $attemptid, 'ci' => $chunkindex]
    );
} else {
    // First blob for this chunk index — create/overwrite the file.
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $filepath)) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to save chunk']));
    }

    // Upsert a DB record: insert if not already present (handles re-uploads gracefully).
    clearstatcache(true, $filepath);
    $fsize = filesize($filepath);
    $fp    = 'timadey_recordings/' . $USER->id . '/' . $attemptid . '/' . $filename;
    $exists = $DB->get_field('local_timadey_recordings', 'id',
        ['userid' => $USER->id, 'attemptid' => $attemptid, 'chunkindex' => $chunkindex]);
    if ($exists) {
        $DB->execute(
            "UPDATE {local_timadey_recordings} SET filesize = :sz, filepath = :fp, timecreated = :tc
              WHERE id = :id",
            ['sz' => $fsize, 'fp' => $fp, 'tc' => time(), 'id' => $exists]
        );
    } else {
        $record              = new stdClass();
        $record->userid      = $USER->id;
        $record->attemptid   = $attemptid;
        $record->chunkindex  = $chunkindex;
        $record->filepath    = $fp;
        $record->filesize    = $fsize;
        $record->timecreated = time();
        try {
            $DB->insert_record('local_timadey_recordings', $record);
        } catch (Exception $e) {
            error_log('timadey save_recording DB insert failed: ' . $e->getMessage());
        }
    }
}

// Refresh the public copy so recordings.php always serves up-to-date data.
$tmp_dir     = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'tmp';
$public_name = $USER->id . '_' . $attemptid . '_' . $chunkindex . '.webm';
if (!is_dir($tmp_dir)) {
    mkdir($tmp_dir, 0755, true);
}
@copy($filepath, $tmp_dir . DIRECTORY_SEPARATOR . $public_name);

echo json_encode(['status' => 'ok', 'chunk' => $chunkindex, 'append' => $append]);
