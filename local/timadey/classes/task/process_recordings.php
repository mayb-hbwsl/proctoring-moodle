<?php
namespace local_timadey\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task — runs every 5 minutes.
 * Deletes all video files for sessions older than 24 hours.
 * DB rows are kept for audit purposes.
 */
class process_recordings extends \core\task\scheduled_task {

    public function get_name() {
        return 'Timadey: Clean Up Old Proctoring Recordings';
    }

    public function execute() {
        global $DB, $CFG;

        $cutoff = time() - 86400; // 24 hours ago

        $old_sessions = $DB->get_records_sql("
            SELECT userid, attemptid, MIN(timecreated) AS started_at
            FROM {local_timadey_recordings}
            GROUP BY userid, attemptid
            HAVING MIN(timecreated) < :cutoff
        ", ['cutoff' => $cutoff]);

        foreach ($old_sessions as $s) {
            $this->delete_session($s->userid, $s->attemptid);
        }
    }

    private function delete_session($userid, $attemptid) {
        global $CFG, $DB;

        $chunks = $DB->get_records('local_timadey_recordings',
            ['userid' => $userid, 'attemptid' => $attemptid]
        );

        $tmp_dir = __DIR__ . '/../../assets/tmp';

        foreach ($chunks as $chunk) {
            // Delete from moodledata
            $src = $CFG->dataroot . DIRECTORY_SEPARATOR
                 . str_replace('/', DIRECTORY_SEPARATOR, $chunk->filepath);
            @unlink($src);

            // Delete public copy from assets/tmp
            $pub = $tmp_dir . DIRECTORY_SEPARATOR
                 . $userid . '_' . $attemptid . '_' . $chunk->chunkindex . '.webm';
            @unlink($pub);
        }

        // Remove empty moodledata directory
        $dir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
             . DIRECTORY_SEPARATOR . $userid
             . DIRECTORY_SEPARATOR . $attemptid;
        @rmdir($dir);

        // DB rows are kept intentionally for audit trail
        mtrace("[Timadey] Deleted recordings for attempt {$attemptid} (>24h old).");
    }
}
