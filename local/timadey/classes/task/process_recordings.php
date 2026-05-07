<?php
namespace local_timadey\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task — runs every 5 minutes.
 *
 * 1. MERGE  — stitches fresh chunk files into a single merged .webm so the
 *             Recordings page loads instantly (FFmpeg runs here, not on page load).
 * 2. CLEAN  — deletes all video files for sessions older than 24 hours.
 *             DB rows are kept for audit purposes.
 */
class process_recordings extends \core\task\scheduled_task {

    public function get_name() {
        return 'Timadey: Merge & Clean Up Proctoring Recordings';
    }

    public function execute() {
        global $DB, $CFG;

        $ffmpeg  = $this->find_ffmpeg();
        $tmp_dir = realpath(__DIR__ . '/../../assets/tmp');
        if (!$tmp_dir || !is_dir($tmp_dir)) {
            @mkdir(__DIR__ . '/../../assets/tmp', 0755, true);
            $tmp_dir = realpath(__DIR__ . '/../../assets/tmp');
        }

        $cutoff = time() - 86400; // 24 hours

        // Get all known sessions from DB.
        $sessions = $DB->get_records_sql("
            SELECT userid, attemptid,
                   MIN(timecreated) AS started_at,
                   MAX(timecreated) AS last_chunk_at
            FROM {local_timadey_recordings}
            GROUP BY userid, attemptid
        ");

        foreach ($sessions as $s) {
            if ($s->started_at < $cutoff) {
                // Session is older than 24 h — delete files.
                $this->delete_session($s->userid, $s->attemptid, $tmp_dir);
            } else {
                // Session is recent — merge chunks if needed.
                if ($ffmpeg) {
                    $this->merge_session($s->userid, $s->attemptid, $s->last_chunk_at, $ffmpeg, $tmp_dir);
                }
            }
        }

        // Also scan the filesystem for sessions not yet in DB (upload in progress, etc.)
        if ($ffmpeg) {
            $rec_root = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings';
            if (is_dir($rec_root)) {
                foreach (glob($rec_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $udir) {
                    $uid = (int)basename($udir);
                    if ($uid <= 0) continue;
                    foreach (glob($udir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $adir) {
                        $aid     = (int)basename($adir);
                        $key     = $uid . '_' . $aid;
                        $in_db   = isset($sessions[$key]) || $DB->record_exists('local_timadey_recordings',
                            ['userid' => $uid, 'attemptid' => $aid]);
                        if ($in_db) continue; // already handled above
                        $chunks  = glob($adir . DIRECTORY_SEPARATOR . 'chunk_*.webm');
                        if (empty($chunks)) continue;
                        $mtime   = max(array_map('filemtime', $chunks));
                        if ($mtime < $cutoff) {
                            $this->delete_session($uid, $aid, $tmp_dir);
                        } else {
                            $this->merge_session($uid, $aid, $mtime, $ffmpeg, $tmp_dir);
                        }
                    }
                }
            }
        }
    }

    // ── merge ─────────────────────────────────────────────────────────────────

    private function merge_session($userid, $attemptid, $last_chunk_at, $ffmpeg, $tmp_dir) {
        global $CFG, $DB;

        // Collect chunk paths in order.
        $adir        = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
                     . DIRECTORY_SEPARATOR . $userid . DIRECTORY_SEPARATOR . $attemptid;
        $disk_chunks = glob($adir . DIRECTORY_SEPARATOR . 'chunk_*.webm');
        if (empty($disk_chunks)) return;
        natsort($disk_chunks);
        $chunk_paths = array_values($disk_chunks);

        $merged_name = $userid . '_' . $attemptid . '_merged.webm';
        $merged_path = $tmp_dir . DIRECTORY_SEPARATOR . $merged_name;

        // Ensure public chunk copies exist (recordings.php reads from tmp_dir).
        foreach ($chunk_paths as $src) {
            if (preg_match('/chunk_(\d{4})\.webm$/', basename($src), $m)) {
                $pub = $tmp_dir . DIRECTORY_SEPARATOR . $userid . '_' . $attemptid . '_' . (int)$m[1] . '.webm';
                if (!file_exists($pub) || filemtime($pub) < filemtime($src)) {
                    @copy($src, $pub);
                }
            }
        }

        // Skip if the existing merged file is still fresh.
        $total_src = array_sum(array_map('filesize', $chunk_paths));
        $need_merge = !file_exists($merged_path)
            || filemtime($merged_path) < $last_chunk_at
            || (count($chunk_paths) > 1
                && file_exists($merged_path)
                && filesize($merged_path) > $total_src * 1.3);

        if (!$need_merge) return;

        $ok = $this->run_merge($ffmpeg, $chunk_paths, $merged_path);
        if ($ok) {
            // Bust the stale duration cache so recordings.php re-probes.
            @unlink($merged_path . '.dur');
            mtrace("[Timadey] Merged " . count($chunk_paths) . " chunk(s) → {$merged_name}");
        } else {
            mtrace("[Timadey] Merge FAILED for u={$userid} a={$attemptid}");
        }
    }

    private function run_merge($ffmpeg, array $chunk_paths, $output) {
        $ff         = str_replace('\\', '/', $ffmpeg);
        $total_src  = array_sum(array_map('filesize', $chunk_paths));

        if (count($chunk_paths) === 1) {
            return copy($chunk_paths[0], $output);
        }

        // Write filelist without BOM — FFmpeg rejects BOM as invalid keyword.
        $listfile = $output . '.txt';
        $lines    = '';
        foreach ($chunk_paths as $p) {
            $lines .= "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $p)) . "'\n";
        }
        file_put_contents($listfile, $lines);

        $cmd = escapeshellarg($ff)
             . ' -y -f concat -safe 0'
             . ' -i ' . escapeshellarg(str_replace('\\', '/', $listfile))
             . ' -c copy -cues_to_front 1 '
             . escapeshellarg(str_replace('\\', '/', $output))
             . ' 2>&1';
        exec($cmd, $out, $ret);
        @unlink($listfile);

        if ($ret !== 0 || !file_exists($output)) return false;
        // Sanity: merged file should not be dramatically larger than source.
        if (filesize($output) > $total_src * 1.3) {
            @unlink($output);
            return false;
        }
        return true;
    }

    // ── delete ────────────────────────────────────────────────────────────────

    private function delete_session($userid, $attemptid, $tmp_dir) {
        global $CFG, $DB;

        $chunks = $DB->get_records('local_timadey_recordings',
            ['userid' => $userid, 'attemptid' => $attemptid]);

        foreach ($chunks as $chunk) {
            $src = $CFG->dataroot . DIRECTORY_SEPARATOR
                 . str_replace('/', DIRECTORY_SEPARATOR, $chunk->filepath);
            @unlink($src);
            $pub = $tmp_dir . DIRECTORY_SEPARATOR
                 . $userid . '_' . $attemptid . '_' . $chunk->chunkindex . '.webm';
            @unlink($pub);
        }

        // Also clean up merged file and its caches.
        $merged = $tmp_dir . DIRECTORY_SEPARATOR . $userid . '_' . $attemptid . '_merged.webm';
        @unlink($merged);
        @unlink($merged . '.dur');
        @unlink($merged . '.txt');

        // Remove empty moodledata directory.
        $dir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'timadey_recordings'
             . DIRECTORY_SEPARATOR . $userid . DIRECTORY_SEPARATOR . $attemptid;
        @rmdir($dir);

        mtrace("[Timadey] Deleted recordings for u={$userid} a={$attemptid} (>24h old).");
    }

    // ── ffmpeg ────────────────────────────────────────────────────────────────

    private function find_ffmpeg() {
        $candidates = [
            'C:/Users/msacc/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe/ffmpeg-8.1-full_build/bin/ffmpeg.exe',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/ffmpeg/bin/ffmpeg',
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        $out = []; $ret = 1;
        @exec('which ffmpeg 2>/dev/null', $out, $ret);
        if ($ret === 0 && !empty($out[0]) && file_exists(trim($out[0]))) return trim($out[0]);
        return null;
    }
}
