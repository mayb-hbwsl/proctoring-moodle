<?php
/**
 * Timadey AI Proctoring - Incident Logging Endpoint
 *
 * Receives proctoring events from the browser-side JS engine
 * and stores them in the database for admin review.
 */
require_once('../../config.php');
require_login();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Parse JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['message'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid data']));
}

// Verify the Moodle session key to prevent CSRF
// Note: require_sesskey() doesn't work for JSON payloads, so we verify manually
if (!empty($data['sesskey'])) {
    if ($data['sesskey'] !== sesskey()) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid session key']));
    }
}

global $DB, $USER;

// Build the incident record
$record = new stdClass();
$record->userid = $USER->id;
$record->attemptid = intval($data['attemptid'] ?? 0);
$record->message = clean_param($data['message'], PARAM_TEXT);
$record->severity = intval($data['severity'] ?? 0);
$record->eventtime = intval($data['timestamp'] ?? time() * 1000);
$record->timecreated = time();

// Store in the incidents table
try {
    $DB->insert_record('local_timadey_incidents', $record);
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    // If the table doesn't exist yet (plugin not upgraded), log to Moodle's standard log
    error_log('[Timadey] Incident: ' . $data['message'] . ' (severity: ' . $data['severity'] . ') user: ' . $USER->id);
    echo json_encode(['status' => 'ok', 'fallback' => true]);
}
