<?php
/**
 * Timadey AI Proctoring - Recording Merge & Serve
 *
 * Merges all 30-second WebM chunks for a given attempt into one
 * full-session video using FFmpeg, then streams it to the browser.
 */
require_once('../../config.php');
require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}

global $CFG, $DB;

$attemptid = required_param('attemptid', PARAM_INT);
$action    = optional_param('action', 'watch', PARAM_ALPHA);

// Fetch chunks in order
$chunks = $DB->get_records('local_timadey_recordings',
    ['attemptid' => $attemptid],
    'chunkindex ASC'
);

if (empty($chunks)) {
    throw new moodle_exception('No recordings found for attempt ' . $attemptid);
}

$first   = reset($chunks);
$userid  = $first->userid;
$dir     = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
         . DIRECTORY_SEPARATOR . $userid
         . DIRECTORY_SEPARATOR . $attemptid;
$merged  = $dir . DIRECTORY_SEPARATOR . 'full_session.webm';

// --- Merge if needed (or if re-merge requested) ---
if (!file_exists($merged) || $action === 'merge') {

    $ffmpeg = timadey_find_ffmpeg();

    if (!$ffmpeg) {
        throw new moodle_exception('FFmpeg not found. Tried: ' . implode(', ', timadey_ffmpeg_candidates()));
    }

    // Write concat list to a temp file
    $listfile = $dir . DIRECTORY_SEPARATOR . 'filelist.txt';
    $lines    = '';
    foreach ($chunks as $chunk) {
        $path  = $CFG->dataroot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $chunk->filepath);
        $lines .= "file '" . str_replace("'", "'\\''", $path) . "'\n";
    }
    file_put_contents($listfile, $lines);

    // Run FFmpeg: copy streams, no re-encoding (fast)
    // Wrap $ffmpeg in quotes to handle Windows paths with backslashes
    $cmd = '"' . $ffmpeg . '"'
         . ' -y'
         . ' -f concat -safe 0'
         . ' -i ' . escapeshellarg($listfile)
         . ' -c copy '
         . escapeshellarg($merged)
         . ' 2>&1';

    exec($cmd, $output, $exit_code);
    @unlink($listfile);

    if ($exit_code !== 0 || !file_exists($merged)) {
        throw new moodle_exception('FFmpeg merge failed: ' . implode(' | ', $output));
    }
}

// --- Stream the merged video to the browser ---
$filename = 'session_attempt_' . $attemptid . '.webm';
$filesize = filesize($merged);

header('Content-Type: video/webm');
header('Content-Length: ' . $filesize);

// "inline" opens in browser tab; "attachment" forces download
if ($action === 'watch') {
    header('Content-Disposition: inline; filename="' . $filename . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

header('Accept-Ranges: bytes');
readfile($merged);
exit;


function timadey_ffmpeg_candidates() {
    return [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        'C:\Users\msacc\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-8.1-full_build\bin\ffmpeg.exe',
        'ffmpeg',
    ];
}

function timadey_find_ffmpeg() {
    foreach (timadey_ffmpeg_candidates() as $path) {
        $out = [];
        $ret = -1;
        exec('"' . $path . '" -version 2>&1', $out, $ret);
        if ($ret === 0) {
            return $path;
        }
    }
    return null;
}
