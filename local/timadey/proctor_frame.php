<?php
/**
 * Timadey persistent proctoring popup.
 *
 * This page runs inside a small popup window that stays open for the entire
 * exam session.  Because it never navigates away, the camera stream and
 * recording are continuous even when the main quiz page reloads between
 * questions.
 *
 * The main quiz page pings this window via BroadcastChannel to check it is
 * alive before deciding whether to (re)open it.
 */
require_once(__DIR__ . '/../../config.php');
require_login();

global $DB, $USER, $CFG;

$attemptid = required_param('attemptid', PARAM_INT);

// Security: verify this attempt belongs to the current user.
// Accept both adaptive quiz attempts (by uniqueid) and standard quiz attempts (by id).
$valid = false;
try {
    $valid = $DB->record_exists_select(
        'adaptivequiz_attempt',
        'uniqueid = :uid AND userid = :userid',
        ['uid' => $attemptid, 'userid' => $USER->id]
    );
} catch (Exception $e) {}

if (!$valid) {
    try {
        $valid = $DB->record_exists_select(
            'quiz_attempts',
            'id = :id AND userid = :userid',
            ['id' => $attemptid, 'userid' => $USER->id]
        );
    } catch (Exception $e) {}
}

if (!$valid) {
    http_response_code(403);
    die('Access denied');
}

$bundle_css = (new moodle_url('/local/timadey/assets/moodle-proctor-bundle.css'))->out(false);
$bundle_js  = (new moodle_url('/local/timadey/assets/moodle-proctor-bundle.iife.js'))->out(false);
$recorder   = (new moodle_url('/local/timadey/assets/recorder.js'))->out(false);
$sesskey    = sesskey();

// Output a minimal standalone HTML page — no Moodle chrome, no navigation.
// Keep it lightweight so the popup opens fast.
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exam Proctoring</title>
<link rel="stylesheet" href="<?= htmlspecialchars($bundle_css) ?>">
<style>
  html, body { margin: 0; padding: 0; overflow: hidden;
               background: #111; width: 100%; height: 100%; }
  #timadey-status {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: rgba(0,0,0,.7); color: #0f0;
    font: 11px/1.6 monospace; text-align: center;
    padding: 3px 6px; z-index: 9999; }
</style>
</head>
<body>

<!-- JS globals must appear before the bundle so the bundle picks them up -->
<script>
  window.timadeyAttemptId  = <?= (int)$attemptid ?>;
  window.timadeyIsResume   = false;   // popup always records continuously
  window.timadeyPopupMode  = true;    // tells recorder.js to skip URL detection

  // Expose Moodle sesskey so recorder.js can sign its requests.
  window.M = window.M || {};
  window.M.cfg = window.M.cfg || {};
  window.M.cfg.sesskey = <?= json_encode($sesskey) ?>;
</script>

<script src="<?= htmlspecialchars($bundle_js) ?>"></script>
<script src="<?= htmlspecialchars($recorder) ?>"></script>

<script>
(function () {
  var attemptId = window.timadeyAttemptId;

  // ── Status bar ────────────────────────────────────────────────────────────
  var bar = document.getElementById('timadey-status');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'timadey-status';
    document.body.appendChild(bar);
  }
  bar.textContent = '● Recording — exam in progress';

  // ── BroadcastChannel: respond to pings from the main quiz page ────────────
  // The main page pings this channel on every question load. If we respond,
  // the main page knows the popup is alive and does NOT reopen it (which would
  // reload and kill the camera).
  if (window.BroadcastChannel) {
    var bc = new BroadcastChannel('timadey_popup_' + attemptId);
    bc.onmessage = function (e) {
      if (e.data === 'ping') {
        bc.postMessage('alive');
      }
      if (e.data === 'quiz_done') {
        bar.textContent = '✓ Exam finished — you may close this window';
        bar.style.color = '#ff0';
        // Give the last recording chunk time to upload before closing.
        setTimeout(function () { window.close(); }, 4000);
      }
    };
  }

  // ── Guard: prevent popup from being navigated away ────────────────────────
  window.addEventListener('beforeunload', function (e) {
    e.preventDefault();
    e.returnValue = 'Closing this window will stop exam recording.';
  });
})();
</script>

</body>
</html>
<?php exit;
