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
        this.isMonitoring = false;
        this.eventTimeout = null;

        // Moodle incident logging endpoint
        this.moodleApiEndpoint = '/local/timadey/log_incident.php';

        this.init();
    }

    async init() {
        // Activate on quiz attempt pages (standard quiz + adaptive quiz)
        const path = window.location.pathname;
        const isQuizPage = path.includes('/mod/quiz/attempt.php')
            || path.includes('/mod/adaptivequiz/attempt.php');
        const isTestMode = new URLSearchParams(window.location.search).get('test') === '1';

        if (!isQuizPage && !isTestMode) {
            console.log('[Timadey] Not a quiz page. Dormant.');
            return;
        }

        console.log('[Timadey] Quiz page detected. Starting AI Proctor...');

        // Inject the webcam widget UI
        this.injectUI();

        // Show full-screen loading overlay
        this.showLoadingScreen();

        try {
            // SEB compatibility: pre-create GL context if OffscreenCanvas is missing
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
                }
            }

            this.updateStatus('Loading AI Models...');
            this.engine = ProctoringEngine.getInstance({
                enableVisualDetection: true,
                enableAudioMonitoring: true,
                enablePatternDetection: true,
                enableBrowserTelemetry: true,
                detectionFPS: 10,

                onEvent: (event) => this.handleEvent(event),
                onBehavioralPattern: (pattern) => this.handlePattern(pattern),
                onStatusChange: (status) => this.updateStatus(status),
                onError: (error) => {
                    console.error('[Timadey] Engine error:', error);
                    this.logIncident(`Engine Error: ${error}`, 10);
                }
            });

            await this.engine.initialize();

            if (!this.engine.isInitialized) {
                console.error('[Timadey] FATAL: Engine could not initialize.');
                this.updateStatus('AI Failed to Load', 'error');
                return;
            }

            // Start camera and monitoring
            await this.startMonitoring();

            // Keep score UI in sync with state changes
            this.engine.stateManager.subscribe(() => this.updateScoreUI());

        } catch (error) {
            console.error('[Timadey] Initialization failed:', error);
            this.updateStatus('Initialization Error', 'error');
            this.lockQuiz();
        }
    }

    injectUI() {
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

        container.appendChild(this.videoElement);
        container.appendChild(this.overlay);
        container.appendChild(this.statusText);
        container.appendChild(this.scoreText);
        container.appendChild(this.recentEventText);

        document.body.appendChild(container);
    }

    async clearModelCache() {
        try {
            if (typeof indexedDB !== 'undefined') {
                await new Promise((resolve) => {
                    const req = indexedDB.deleteDatabase('SDProctor_Cache');
                    req.onsuccess = resolve;
                    req.onerror = resolve;
                    req.onblocked = resolve;
                });
            }
        } catch (e) {
            // Non-fatal — continue regardless
        }
    }

    async startMonitoring() {
        try {
            this.updateStatus('Requesting Camera...', 'warning');
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: 320, height: 240 },
                audio: true
            });

            this.videoElement.srcObject = stream;
            await new Promise(resolve => {
                this.videoElement.onloadedmetadata = () => this.videoElement.play();
                this.videoElement.onplaying = () => {
                    this.videoElement.width = this.videoElement.videoWidth;
                    this.videoElement.height = this.videoElement.videoHeight;
                    resolve();
                };
            });

            this.engine.start(this.videoElement);
            this.isMonitoring = true;
            this.updateStatus('Monitoring Active', 'success');

            // Reveal the quiz
            this.hideLoadingScreen();

        } catch (error) {
            console.error('[Timadey] Camera access denied:', error);
            this.updateStatus('Camera Access Denied!', 'error');
            this.lockQuiz();
        }
    }

    handleEvent(event) {
        this.logIncident(event.event, event.lv);
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
            this.overlay.style.borderColor = '#ff4d4f';
        } else if (score > 250) {
            this.overlay.style.borderColor = '#faad14';
        } else {
            this.overlay.style.borderColor = '#52c41a';
        }
    }

    showLoadingScreen() {
        if (document.getElementById('timadey-loading-overlay')) return;

        const overlay = document.createElement('div');
        overlay.id = 'timadey-loading-overlay';
        overlay.className = 'timadey-loading-overlay';
        overlay.innerHTML = `
            <div class="loading-content">
                <div class="spinner"></div>
                <h2>Securing Exam Environment...</h2>
                <p>Initializing AI Proctoring and verifying system integrity.</p>
                <p class="small">This system was developed by a crazy intern! So please have patience....</p>
                <div id="loading-status-subtext">Waiting for system...</div>
                
            </div>
        `;
        document.body.appendChild(overlay);

        // Disable quiz interactions
        document.body.classList.add('timadey-locked');
    }

    hideLoadingScreen() {
        const overlay = document.getElementById('timadey-loading-overlay');
        if (overlay) {
            overlay.classList.add('fade-out');
            setTimeout(() => {
                overlay.remove();
                document.body.classList.remove('timadey-locked');
            }, 500);
        }
    }

    updateStatus(message, state = 'info') {
        if (this.statusText) {
            this.statusText.textContent = message;
            this.statusText.className = `timadey-status state-${state}`;
        }

        // Also update the subtext on the loading screen if visible
        const subtext = document.getElementById('loading-status-subtext');
        if (subtext) subtext.textContent = message;
    }

    async logIncident(message, severity) {
        console.warn(`[Timadey Incident] ${message} (Severity: ${severity})`);

        try {
            await fetch(this.moodleApiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message,
                    severity,
                    timestamp: Date.now(),
                    sesskey: window.M?.cfg?.sesskey || '',
                    userid: window.M?.cfg?.userid || 0,
                    attemptid: this.getAttemptId(),
                })
            });
        } catch (err) {
            // Silent fail — don't disrupt the exam for logging errors
        }
    }

    /** Extract the quiz attempt ID from the Moodle page URL */
    getAttemptId() {
        const params = new URLSearchParams(window.location.search);
        return parseInt(params.get('attempt')) || 0;
    }

    lockQuiz() {
        const submitBtns = document.querySelectorAll('input[type="submit"], button[type="submit"]');
        submitBtns.forEach(btn => {
            btn.disabled = true;
            if (btn.tagName === 'INPUT') btn.value = "Camera Required to Continue";
            else btn.textContent = "Camera Required to Continue";
        });

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

// Auto-start when loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new MoodleProctorBridge());
} else {
    new MoodleProctorBridge();
}
