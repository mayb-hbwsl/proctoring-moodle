import { ProctoringEngine } from '@timadey/proctor';
import './moodle-bridge.css';

class MoodleProctorBridge {
    constructor() {
        this.engine = null;
        this.videoElement = null;
        this.overlay = null;
        this.statusText = null;
        this.scoreText = null;
        this.recentEventText = null;
        this.debugLog = null;
        this.isMonitoring = false;
        this.eventTimeout = null;
        this.debugMessages = [];
        
        // Moodle specific API endpoint (Placeholder)
        this.moodleApiEndpoint = '/local/timadey/log_incident.php';

        this.init();
    }

    async init() {
        // 1. Check if we are on a quiz page
        // A simple check: URL should contain /mod/quiz/attempt.php
        // For local testing, we bypass this if we add ?test=1
        const urlParams = new URLSearchParams(window.location.search);
        const isTestMode = urlParams.get('test') === '1';
        const isQuizPage = window.location.pathname.includes('/mod/quiz/attempt.php');

        if (!isQuizPage && !isTestMode) {
            console.log('[Timadey Bridge] Not a quiz page. Remaining dormant.');
            return;
        }

        console.log('[Timadey Bridge] Quiz page detected. Waking up AI Proctor...');

        // 2. Inject UI
        this.injectUI();

        // 3. Initialize Engine
        try {
            // Diagnostic checks for SEB environment
            const testCanvas = document.createElement('canvas');
            const gl = testCanvas.getContext('webgl2') || testCanvas.getContext('webgl');
            this.addDebug(`WebGL: ${gl ? gl.getParameter(gl.VERSION) : 'NOT AVAILABLE'}`);
            this.addDebug(`OffscreenCanvas: ${typeof OffscreenCanvas !== 'undefined' ? 'YES' : 'NO'}`);
            
            try {
                const resp = await fetch('https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@latest/wasm/vision_wasm_internal.js', { method: 'HEAD' });
                this.addDebug(`CDN fetch: ${resp.status} ${resp.ok ? 'OK' : 'FAIL'}`);
            } catch(e) {
                this.addDebug(`CDN fetch BLOCKED: ${e.message}`);
            }

            if (typeof OffscreenCanvas === 'undefined') {
                const mpCanvas = document.createElement('canvas');
                mpCanvas.width = 640;
                mpCanvas.height = 480;
                mpCanvas.style.cssText = 'position:fixed;top:-9999px;left:-9999px;pointer-events:none;';
                document.body.appendChild(mpCanvas);
                const mpGl = mpCanvas.getContext('webgl2', { preserveDrawingBuffer: true }) 
                          || mpCanvas.getContext('webgl', { preserveDrawingBuffer: true });
                
                if (!window.Module) window.Module = {};
                window.Module.canvas = mpCanvas;
                if (mpGl) {
                    window.Module.preinitializedWebGLContext = mpGl;
                    this.addDebug('Pre-created GL context for Emscripten.');
                } else {
                    this.addDebug('WARNING: Could not create GL context for Emscripten.');
                }
            } else {
                this.addDebug('OffscreenCanvas supported. Skipping Emscripten GL hack.');
            }

            // SEB clears cookies on start which can corrupt the IndexedDB model cache.
            // Wipe it clean before we begin so MediaPipe loads fresh models.
            this.addDebug('Clearing model cache (SEB fix)...');
            await this.clearModelCache();

            this.addDebug('Creating ProctoringEngine instance...');
            this.engine = ProctoringEngine.getInstance({
                enableVisualDetection: true,
                enableAudioMonitoring: true,
                enablePatternDetection: true,
                enableBrowserTelemetry: true,
                detectionFPS: 10,
                
                onEvent: (event) => this.handleEvent(event),
                onBehavioralPattern: (pattern) => this.handlePattern(pattern),
                onStatusChange: (status) => {
                    this.addDebug(`Status: ${status}`);
                    this.updateStatus(status);
                },
                onError: (error) => {
                    this.addDebug(`ERROR: ${error}`);
                    this.logIncident(`Engine Error: ${error}`, 10);
                }
            });

            this.addDebug('Engine created. Starting initialize()...');
            this.updateStatus('Initializing AI Models...');
            await this.engine.initialize();
            
            // Check if the engine ACTUALLY initialized (it swallows errors internally)
            if (!this.engine.isInitialized) {
                this.addDebug('Engine init failed silently! Retrying...');
                // Force reset the singleton so we get a fresh instance
                ProctoringEngine.instance = null;
                this.engine = ProctoringEngine.getInstance({
                    enableVisualDetection: true,
                    enableAudioMonitoring: true,
                    enablePatternDetection: true,
                    enableBrowserTelemetry: true,
                    detectionFPS: 10,
                    onEvent: (event) => this.handleEvent(event),
                    onBehavioralPattern: (pattern) => this.handlePattern(pattern),
                    onStatusChange: (status) => {
                        this.addDebug(`Status: ${status}`);
                        this.updateStatus(status);
                    },
                    onError: (error) => {
                        this.addDebug(`RETRY ERROR: ${error}`);
                    }
                });
                await this.engine.initialize();
                this.addDebug(`Retry result: isInitialized=${this.engine.isInitialized}`);
            }

            if (!this.engine.isInitialized) {
                this.addDebug('FATAL: Engine could not initialize after retry.');
                this.updateStatus('AI Models Failed to Load', 'error');
                // Don't lock the quiz — camera still works for recording
                return;
            }

            this.addDebug('initialize() complete. Engine ready.');
            
            // Start monitoring immediately for the quiz
            await this.startMonitoring();
            
            // Subscribe to state updates to ensure UI always refreshes
            this.addDebug('Subscribing to state updates...');
            this.engine.stateManager.subscribe(() => {
                this.updateScoreUI();
            });
            this.addDebug('All systems GO.');

        } catch (error) {
            console.error('[Timadey Bridge] Initialization failed:', error);
            this.addDebug(`FATAL: ${error.message || error}`);
            this.updateStatus('Initialization Error. Check permissions.', 'error');
            this.lockQuiz(); // Prevent quiz continuation if proctoring fails
        }
    }

    injectUI() {
        // Create a floating widget in the bottom right corner
        const container = document.createElement('div');
        container.id = 'timadey-proctor-widget';
        container.className = 'timadey-widget';

        this.videoElement = document.createElement('video');
        this.videoElement.id = 'timadey-webcam';
        this.videoElement.autoplay = true;
        this.videoElement.muted = true;
        this.videoElement.playsInline = true;

        this.overlay = document.createElement('div');
        this.overlay.className = 'timadey-overlay';

        this.statusText = document.createElement('div');
        this.statusText.className = 'timadey-status';
        this.statusText.textContent = 'Starting...';

        this.scoreText = document.createElement('div');
        this.scoreText.className = 'timadey-score';
        this.scoreText.textContent = 'Score: 0';

        this.recentEventText = document.createElement('div');
        this.recentEventText.className = 'timadey-recent-event';

        // Debug log panel (visible on screen since SEB has no dev tools)
        this.debugLog = document.createElement('div');
        this.debugLog.className = 'timadey-debug-log';
        this.debugLog.textContent = 'Debug: Waiting...';

        container.appendChild(this.videoElement);
        container.appendChild(this.overlay);
        container.appendChild(this.statusText);
        container.appendChild(this.scoreText);
        container.appendChild(this.recentEventText);

        document.body.appendChild(container);
        document.body.appendChild(this.debugLog);
    }

    addDebug(msg) {
        const time = new Date().toLocaleTimeString();
        this.debugMessages.push(`[${time}] ${msg}`);
        // Keep last 15 messages
        if (this.debugMessages.length > 15) this.debugMessages.shift();
        if (this.debugLog) {
            this.debugLog.textContent = this.debugMessages.join('\n');
            this.debugLog.scrollTop = this.debugLog.scrollHeight;
        }
    }

    async clearModelCache() {
        // Delete the IndexedDB database that @timadey/proctor uses to cache MediaPipe models.
        // SEB's "clear cookies on start" can corrupt this cache, causing silent init failures.
        try {
            if (typeof indexedDB !== 'undefined') {
                await new Promise((resolve, reject) => {
                    const req = indexedDB.deleteDatabase('SDProctor_Cache');
                    req.onsuccess = () => {
                        this.addDebug('Model cache cleared OK.');
                        resolve();
                    };
                    req.onerror = () => {
                        this.addDebug('Cache clear error (non-fatal).');
                        resolve(); // Don't block on this
                    };
                    req.onblocked = () => {
                        this.addDebug('Cache clear blocked (non-fatal).');
                        resolve();
                    };
                });
            } else {
                this.addDebug('No IndexedDB available.');
            }
        } catch (e) {
            this.addDebug(`Cache clear exception: ${e.message}`);
        }
    }

    async startMonitoring() {
        try {
            this.addDebug('Requesting camera + mic...');
            this.updateStatus('Requesting Camera...', 'warning');
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { width: 320, height: 240 },
                audio: true 
            });
            this.addDebug(`Got stream. Tracks: ${stream.getTracks().map(t => t.kind).join(', ')}`);
            
            this.videoElement.srcObject = stream;
            // Await video play to ensure dimensions are ready before engine starts
            await new Promise(resolve => {
                this.videoElement.onloadedmetadata = () => {
                    this.videoElement.play();
                };
                this.videoElement.onplaying = () => {
                    this.videoElement.width = this.videoElement.videoWidth;
                    this.videoElement.height = this.videoElement.videoHeight;
                    resolve();
                };
            });
            this.addDebug(`Video playing. Size: ${this.videoElement.videoWidth}x${this.videoElement.videoHeight}`);

            this.addDebug('Calling engine.start()...');
            this.engine.start(this.videoElement);
            this.isMonitoring = true;
            this.addDebug('engine.start() called successfully.');
            this.updateStatus('Monitoring Active', 'success');

            // Start diagnostic logging
            this.startDiagnostics();

        } catch (error) {
            console.error('[Timadey Bridge] Camera access denied:', error);
            this.addDebug(`CAMERA ERROR: ${error.message || error}`);
            this.updateStatus('Camera Access Denied!', 'error');
            this.lockQuiz();
        }
    }

    startDiagnostics() {
        setInterval(() => {
            const vm = this.engine.visualModule;
            const v = this.videoElement;
            if (!vm) {
                this.addDebug('DIAG: No visual module!');
                return;
            }

            this.addDebug(`DIAG: frames=${vm.frameCount} running=${vm.isRunning} faces=${vm.state?.numFaces} noFace=${vm.state?.consecutiveNoFaceFrames}`);
            this.addDebug(`DIAG: video ready=${v.readyState} ${v.videoWidth}x${v.videoHeight} paused=${v.paused}`);
            this.addDebug(`DIAG: faceLandmarker=${!!vm.faceLandmarker} handLandmarker=${!!vm.handLandmarker} objDetector=${!!vm.objectDetector}`);

            // Test if we can read pixels from video
            try {
                const tc = document.createElement('canvas');
                tc.width = v.videoWidth || 320;
                tc.height = v.videoHeight || 240;
                const ctx = tc.getContext('2d');
                ctx.drawImage(v, 0, 0);
                const px = ctx.getImageData(tc.width/2, tc.height/2, 1, 1).data;
                this.addDebug(`DIAG: pixel rgba(${px[0]},${px[1]},${px[2]},${px[3]})`);
            } catch(e) {
                this.addDebug(`DIAG: pixel read FAIL: ${e.message}`);
            }
        }, 3000);
    }

    handleEvent(event) {
        this.logIncident(event.event, event.lv);
        // For testing/debugging, let's show ALL events on screen so you can verify the engine is catching them
        this.showRecentEvent(`[Lv ${event.lv}] ${event.event}`);
        this.updateScoreUI();
    }

    handlePattern(pattern) {
        this.logIncident(`PATTERN: ${pattern.pattern}`, 10);
        this.showRecentEvent(`Pattern: ${pattern.pattern}`);
        this.updateScoreUI();
    }

    showRecentEvent(text) {
        if (!this.recentEventText) return;
        this.recentEventText.textContent = text;
        if (this.eventTimeout) clearTimeout(this.eventTimeout);
        this.eventTimeout = setTimeout(() => {
            this.recentEventText.textContent = '';
        }, 3000);
    }

    updateScoreUI() {
        const score = this.engine.calculateSuspiciousScore();
        if (this.scoreText) {
            this.scoreText.textContent = `Score: ${score}`;
        }
        
        if (score > 500) {
            this.overlay.style.borderColor = '#ff4d4f'; // Red
        } else if (score > 200) {
            this.overlay.style.borderColor = '#faad14'; // Orange
        } else {
            this.overlay.style.borderColor = '#52c41a'; // Green
        }
    }

    updateStatus(message, state = 'info') {
        if (!this.statusText) return;
        this.statusText.textContent = message;
        this.statusText.className = `timadey-status state-${state}`;
    }

    async logIncident(message, severity) {
        console.warn(`[Timadey Bridge Incident] ${message} (Severity: ${severity})`);
        
        // In a real environment, we would send this to Moodle
        try {
            // const response = await fetch(this.moodleApiEndpoint, {
            //     method: 'POST',
            //     headers: { 'Content-Type': 'application/json' },
            //     body: JSON.stringify({
            //         message,
            //         severity,
            //         timestamp: Date.now(),
            //         // Could include user ID or attempt ID fetched from Moodle's DOM or global M.cfg
            //     })
            // });
        } catch (err) {
            console.error('[Timadey Bridge] Failed to log incident to Moodle', err);
        }
    }

    lockQuiz() {
        // Fallback: If camera is denied or AI fails, we block the quiz.
        // Disable Moodle's "Next" button.
        const submitBtns = document.querySelectorAll('input[type="submit"], button[type="submit"]');
        submitBtns.forEach(btn => {
            btn.disabled = true;
            if (btn.tagName === 'INPUT') btn.value = "Camera Required to Continue";
            else btn.textContent = "Camera Required to Continue";
        });
        
        // Also show an alert over the whole screen
        const lockOverlay = document.createElement('div');
        lockOverlay.className = 'timadey-lock-screen';
        lockOverlay.innerHTML = `
            <div class="lock-box">
                <h2>Proctoring Required</h2>
                <p>You must allow camera and microphone access to proceed with this exam.</p>
                <button onclick="window.location.reload()">Reload Page to Try Again</button>
            </div>
        `;
        document.body.appendChild(lockOverlay);
    }
}

// Automatically boot up when the script is loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new MoodleProctorBridge());
} else {
    new MoodleProctorBridge();
}
