(function () {
    'use strict';

    // On a resume (question navigation within the same attempt), the PHP layer has
    // already hidden the loading overlay via CSS. Remove the body lock class instantly
    // so the quiz is interactive while the camera reconnects in the background.
    if (window.timadeyIsResume) {
        document.body.classList.remove('timadey-locked');
    }

    var ENDPOINT       = '/local/timadey/save_recording.php';
    var LOG_ENDPOINT   = '/local/timadey/log_incident.php';
    var CHUNK_DURATION = 300000; // rotate to a new chunk file every 5 minutes
    var TIMESLICE      = 3000;   // flush buffered data every 3 s (reduces max data loss at page turn)
    var VIDEO_BITRATE  = 200000; // 200 kbps

    var chunkIndex       = 0;
    var activeStream     = null;
    var activeRecorder   = null;
    var chunkTimer       = null;
    var isUnloading      = false;
    var recordingStart   = 0;
    var recordingStarted = false;

    // ── helpers ───────────────────────────────────────────────────────────────

    function getSesskey() {
        return (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : '';
    }

    function getAttemptId() {
        // Highest priority: PHP-injected ID — works for both standard quiz (?attempt=X)
        // and adaptive quiz (?cmid=X) where the URL has no attempt param.
        if (window.timadeyAttemptId && window.timadeyAttemptId > 0) {
            try { localStorage.setItem('timadey_attemptid', String(window.timadeyAttemptId)); } catch (e) {}
            return window.timadeyAttemptId;
        }
        // Standard quiz fallback when PHP global is absent.
        var fromUrl = parseInt(new URLSearchParams(window.location.search).get('attempt')) || 0;
        if (fromUrl > 0) {
            try { localStorage.setItem('timadey_attemptid', String(fromUrl)); } catch (e) {}
            return fromUrl;
        }
        // Last resort: cached value from a previous page in the same attempt.
        try { return parseInt(localStorage.getItem('timadey_attemptid') || '0') || 0; } catch (e) { return 0; }
    }

    function saveChunkState() {
        try { localStorage.setItem('timadey_chunkindex', String(chunkIndex)); } catch (e) {}
    }

    function getNextChunkFromCache(minimum) {
        var cached = 0;
        try { cached = parseInt(localStorage.getItem('timadey_chunkindex') || '-1'); } catch (e) {}
        var next = cached >= 0 ? cached + 1 : 0;
        return Math.max(next, minimum || 0);
    }

    // ── loading screen ────────────────────────────────────────────────────────

    // Dismiss the MediaPipe loading overlay as soon as the camera is ready.
    // MediaPipe continues loading in the background for detection; the student
    // does not need to wait for it to see the quiz.
    function dismissLoadingOverlay() {
        var found = false;
        var polls = 0;

        function tryDismiss() {
            var overlay = document.getElementById('timadey-loading-overlay');
            if (overlay && !found) {
                found = true;
                overlay.classList.add('fade-out');
                setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    document.body.classList.remove('timadey-locked');
                }, 500);
                return;
            }
            // Poll for up to 15 seconds in case the bundle creates the overlay after us.
            if (!found && ++polls < 75) {
                setTimeout(tryDismiss, 200);
            }
        }

        setTimeout(tryDismiss, 200);
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
                        navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, form);
                    });
            }
        } catch (e) {}
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
            mimeType:           mimeType,
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
        saveChunkState();
        clearTimeout(chunkTimer);
        chunkTimer = null;
        if (activeRecorder && activeRecorder.state === 'recording') {
            activeRecorder.requestData();
            activeRecorder.stop();
        }
        activeRecorder = null;
        activeStream   = null;
    }

    // ── start recording ───────────────────────────────────────────────────────

    function beginRecordingFromStream(stream, immediateChunkIndex) {
        if (recordingStarted) return;
        recordingStarted = true;
        recordingStart   = Date.now();
        activeStream     = stream;
        var attemptid    = getAttemptId();

        // Dismiss the MediaPipe loading overlay immediately — recording is ready,
        // the student doesn't need to wait for ML model downloads to see the quiz.
        dismissLoadingOverlay();

        if (immediateChunkIndex !== undefined) {
            chunkIndex = immediateChunkIndex;
            startChunk(stream);
            if (immediateChunkIndex === 0) {
                sendRecordingStart(recordingStart, attemptid);
            }

            // Background pre-flight: correct chunkIndex if server knows of a higher one.
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
                waitForMediaPipeStream();
            });
    }

    // Fallback: only reached if getUserMedia is denied/unavailable.
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
