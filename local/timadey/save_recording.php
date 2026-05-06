<?php
require_once('../../config.php');
require_login();

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

global $CFG, $USER, $DB;

$attemptid  = intval($_POST['attemptid']   ?? 0);
$chunkindex = intval($_POST['chunk_index'] ?? 0);
$append     = !empty($_POST['append']) && $_POST['append'] === '1';

$dir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
     . DIRECTORY_SEPARATOR . $USER->id
     . DIRECTORY_SEPARATOR . $attemptid;

make_writable_directory($dir);

$filename = 'chunk_' . str_pad($chunkindex, 4, '0', STR_PAD_LEFT) . '.webm';
$filepath = $dir . DIRECTORY_SEPARATOR . $filename;

if ($append && file_exists($filepath)) {
    // Append continuation WebM clusters to the existing file
    $data = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($data === false || file_put_contents($filepath, $data, FILE_APPEND) === false) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to append chunk']));
    }
    @unlink($_FILES['chunk']['tmp_name']);
} else {
    // First blob for this chunk index — create/overwrite the file
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $filepath)) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to save chunk']));
    }

    // Insert DB record only once per chunk (on first blob)
    try {
        $record = new stdClass();
        $record->userid      = $USER->id;
        $record->attemptid   = $attemptid;
        $record->chunkindex  = $chunkindex;
        $record->filepath    = 'timadey_recordings/' . $USER->id . '/' . $attemptid . '/' . $filename;
        $record->filesize    = filesize($filepath);
        $record->timecreated = time();
        $DB->insert_record('local_timadey_recordings', $record);
    } catch (Exception $e) {
        // Non-fatal
    }
}

// Always refresh the public copy so recordings.php serves the latest data
$tmp_dir     = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'tmp';
$public_name = $USER->id . '_' . $attemptid . '_' . $chunkindex . '.webm';
if (!is_dir($tmp_dir)) {
    mkdir($tmp_dir, 0755, true);
}
@copy($filepath, $tmp_dir . DIRECTORY_SEPARATOR . $public_name);

echo json_encode(['status' => 'ok', 'chunk' => $chunkindex, 'append' => $append]);
