(function () {
    'use strict';

    var ENDPOINT       = '/local/timadey/save_recording.php';
    var LOG_ENDPOINT   = '/local/timadey/log_incident.php';
    var CHUNK_DURATION = 300000; // rotate to a new chunk file every 5 minutes
    var TIMESLICE      = 3000;   // flush buffered data every 3 s (reduces max data loss at page turn)
    var VIDEO_BITRATE  = 200000; // 200 kbps

    var chunkIndex      = 0;
    var activeStream    = null;
    var activeRecorder  = null;
    var chunkTimer      = null;
    var isUnloading     = false;
    var recordingStart  = 0;
    var recordingStarted = false; // guard against double-start

    // ── helpers ──────────────────────────────────────────────────────────────

    function getSesskey() {
        return (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : '';
    }

    // Read attempt ID from URL.  Cache it in localStorage so summary.php inherits
    // the correct value even when the URL lacks the ?attempt= parameter.
    function getAttemptId() {
        var fromUrl = parseInt(new URLSearchParams(window.location.search).get('attempt')) || 0;
        if (fromUrl > 0) {
            try { localStorage.setItem('timadey_attemptid', String(fromUrl)); } catch (e) {}
            return fromUrl;
        }
        try { return parseInt(localStorage.getItem('timadey_attemptid') || '0') || 0; } catch (e) { return 0; }
    }

    // Persist the current chunkIndex so the next page load can continue from here
    // without a network round-trip.
    function saveChunkState() {
        try { localStorage.setItem('timadey_chunkindex', String(chunkIndex)); } catch (e) {}
    }

    // Return the next chunk index to use, based on what localStorage last saved.
    // Minimum value is enforced for the caller (e.g. summary page always starts >= 1).
    function getNextChunkFromCache(minimum) {
        var cached = 0;
        try { cached = parseInt(localStorage.getItem('timadey_chunkindex') || '-1'); } catch (e) {}
        var next = cached >= 0 ? cached + 1 : 0;
        return Math.max(next, minimum || 0);
    }

    // ── upload ────────────────────────────────────────────────────────────────

    function uploadBlob(blob, index, append) {
        if (!blob || blob.size === 0) return;
        try {
            var form = new FormData();
            form.append('chunk', blob, 'chunk_' + String(index).padStart(4, '0') + '.webm');
            form.append('attemptid', getAttemptId());
            form.append('chunk_index', index);
            form.append('append', append ? '1' : '0');
            form.append('sesskey', getSesskey());
            if (isUnloading && navigator.sendBeacon) {
                navigator.sendBeacon(ENDPOINT, form);
            } else {
                fetch(ENDPOINT, { method: 'POST', body: form })
                    .catch(function () {
                        // On regular fetch failure, retry once with sendBeacon.
                        navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, form);
                    });
            }
        } catch (e) { /* silent — never disrupt the exam */ }
    }

    function sendRecordingStart(ts, attemptid) {
        try {
            fetch(LOG_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message:   '__recording_start__',
                    severity:  'info',
                    timestamp: ts,
                    attemptid: attemptid,
                    sesskey:   getSesskey()
                })
            });
        } catch (e) {}
    }

    // ── recording lifecycle ───────────────────────────────────────────────────

    function startChunk(stream) {
        saveChunkState();

        var mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
            ? 'video/webm;codecs=vp8,opus'
            : 'video/webm';

        activeRecorder = new MediaRecorder(stream, {
            mimeType: mimeType,
            videoBitsPerSecond: VIDEO_BITRATE,
        });

        var isFirstBlob = true;
        activeRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) {
                uploadBlob(e.data, chunkIndex, !isFirstBlob);
                isFirstBlob = false;
            }
        };

        activeRecorder.start(TIMESLICE);

        // Rotate to the next chunk file every CHUNK_DURATION milliseconds.
        chunkTimer = setTimeout(function () {
            if (activeRecorder && activeRecorder.state === 'recording') {
                activeRecorder.stop();
            }
            chunkIndex++;
            setTimeout(function () {
                if (activeStream) startChunk(activeStream);
            }, 300);
        }, CHUNK_DURATION);
    }

    function stopRecording() {
        isUnloading = true;
        saveChunkState(); // persist before teardown so next page inherits correct index
        clearTimeout(chunkTimer);
        chunkTimer = null;
        if (activeRecorder && activeRecorder.state === 'recording') {
            activeRecorder.requestData(); // flush buffered data now, don't wait for stop()
            activeRecorder.stop();
        }
        activeRecorder = null;
        activeStream   = null;
    }

    // ── start recording ───────────────────────────────────────────────────────

    // Unified entry point after a stream is obtained.
    // immediateChunkIndex: when set, skip the pre-flight network request and start
    // recording right away using the provided chunk index.  A background pre-flight
    // still runs to verify, but it doesn't block the recorder from starting.
    function beginRecordingFromStream(stream, immediateChunkIndex) {
        if (recordingStarted) return;
        recordingStarted = true;
        recordingStart   = Date.now();
        activeStream     = stream;
        var attemptid    = getAttemptId();

        if (immediateChunkIndex !== undefined) {
            // Fast path: start immediately with the cached index.
            chunkIndex = immediateChunkIndex;
            startChunk(stream);
            if (immediateChunkIndex === 0) {
                sendRecordingStart(recordingStart, attemptid);
            }

            // Background pre-flight: if server reports a higher index (e.g. a chunk we
            // didn't know about), update chunkIndex for the NEXT rotation.  Current chunk
            // continues as-is — server-side overwrite protection handles any collision.
            fetch(ENDPOINT + '?mode=status&attemptid=' + attemptid + '&sesskey=' + getSesskey())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (typeof data.next_chunk === 'number' && data.next_chunk > chunkIndex) {
                        chunkIndex = data.next_chunk;
                        saveChunkState();
                    }
                })
                .catch(function () {});
        } else {
            // Standard path: wait for pre-flight before starting (attempt.php first load).
            fetch(ENDPOINT + '?mode=status&attemptid=' + attemptid + '&sesskey=' + getSesskey())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    chunkIndex = (typeof data.next_chunk === 'number') ? data.next_chunk : 0;
                    startChunk(stream);
                    if (!data.is_resume) {
                        sendRecordingStart(recordingStart, attemptid);
                    }
                })
                .catch(function () {
                    startChunk(stream);
                    sendRecordingStart(recordingStart, attemptid);
                });
        }

        window.addEventListener('beforeunload', stopRecording);
    }

    // Primary recording entry point.
    // On summary.php: use cached chunk index (minimum 1) so recording starts
    // immediately — no network round-trip, no race condition with fast submits.
    // On attempt.php: use cached index as immediate start; background pre-flight
    // corrects it if needed.
    function startDirectRecording(isSummary) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            waitForMediaPipeStream();
            return;
        }
        var immediateChunk = getNextChunkFromCache(isSummary ? 1 : 0);
        navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            .then(function (stream) {
                beginRecordingFromStream(stream, immediateChunk);
            })
            .catch(function () {
                // getUserMedia denied/failed — fall back to MediaPipe stream.
                waitForMediaPipeStream();
            });
    }

    // Fallback: wait for MediaPipe's <video id="timadey-webcam"> element.
    // Only reached when getUserMedia itself is unavailable or denied.
    function waitForMediaPipeStream() {
        var video = document.getElementById('timadey-webcam');
        if (video && video.srcObject && video.readyState >= 2) {
            beginRecordingFromStream(video.srcObject, getNextChunkFromCache(0));
            return;
        }
        var observer = new MutationObserver(function () {
            var v = document.getElementById('timadey-webcam');
            if (!v || !v.srcObject) return;
            observer.disconnect();
            if (v.readyState >= 2) {
                beginRecordingFromStream(v.srcObject, getNextChunkFromCache(0));
            } else {
                v.addEventListener('loadeddata', function () {
                    beginRecordingFromStream(v.srcObject, getNextChunkFromCache(0));
                }, { once: true });
            }
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    // ── page detection ────────────────────────────────────────────────────────

    var path          = window.location.pathname;
    var isQuizPage    = path.includes('/mod/quiz/attempt.php')
                     || path.includes('/mod/adaptivequiz/attempt.php');
    var isSummaryPage = path.includes('/mod/quiz/summary.php');
    var isTestMode    = new URLSearchParams(window.location.search).get('test') === '1';

    if (isQuizPage || isSummaryPage || isTestMode) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                startDirectRecording(isSummaryPage);
            });
        } else {
            startDirectRecording(isSummaryPage);
        }
    }
})();
