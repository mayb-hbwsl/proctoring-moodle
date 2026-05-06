(function () {
    'use strict';

    var ENDPOINT       = '/local/timadey/save_recording.php';
    var CHUNK_DURATION = 300000; // rotate to a new chunk file every 5 minutes
    var TIMESLICE      = 10000;  // flush buffered data to server every 10 s
    var VIDEO_BITRATE  = 200000; // 200 kbps

    var chunkIndex     = 0;
    var activeStream   = null;
    var activeRecorder = null;
    var chunkTimer     = null;
    var isUnloading    = false;

    function getSesskey() {
        return (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : '';
    }

    function getAttemptId() {
        return parseInt(new URLSearchParams(window.location.search).get('attempt')) || 0;
    }

    // append=true → server appends blob to the existing chunk file (continuation segments)
    // append=false → server creates the file (first blob carries the WebM header)
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
                fetch(ENDPOINT, { method: 'POST', body: form });
            }
        } catch (e) { /* silent — never disrupt the exam */ }
    }

    function startChunk(stream) {
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
                // First blob has the WebM init segment (header) — must not be appended.
                // Subsequent blobs are continuation clusters — must be appended.
                uploadBlob(e.data, chunkIndex, !isFirstBlob);
                isFirstBlob = false;
            }
        };

        activeRecorder.start(TIMESLICE); // ondataavailable fires every 10 s automatically

        // After CHUNK_DURATION rotate: stop this recorder and start a fresh one on next index
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

    // Called on beforeunload — stop the recorder so it flushes any remaining buffered data.
    // sendBeacon is used in uploadBlob when isUnloading=true so the request survives navigation.
    function stopRecording() {
        isUnloading = true;
        clearTimeout(chunkTimer);
        chunkTimer = null;
        if (activeRecorder && activeRecorder.state === 'recording') {
            activeRecorder.stop();
        }
        activeRecorder = null;
        activeStream   = null;
    }

    function waitForStream() {
        var video = document.getElementById('timadey-webcam');
        if (video && video.srcObject && video.readyState >= 2) {
            activeStream = video.srcObject;
            startChunk(activeStream);
            console.log('[Timadey Recorder] Recording started (60 s flush, 5 min chunks).');
            window.addEventListener('beforeunload', stopRecording);
        } else {
            setTimeout(waitForStream, 1000);
        }
    }

    var path = window.location.pathname;
    var isQuizPage = path.includes('/mod/quiz/attempt.php')
        || path.includes('/mod/adaptivequiz/attempt.php');
    var isTestMode = new URLSearchParams(window.location.search).get('test') === '1';

    if (isQuizPage || isTestMode) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', waitForStream);
        } else {
            waitForStream();
        }
    }
})();
