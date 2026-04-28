import { ProctoringEngine } from '@timadey/proctor';

/**
 * Manages soft-lock security features
 */
class SecurityManager {
    constructor(callbacks) {
        this.callbacks = callbacks;
        this.isSessionActive = false;
        this.violations = 0;
        
        // UI Elements
        this.overlay = document.getElementById('security-alert');
        this.alertMsg = document.getElementById('alert-message');
        this.resumeBtn = document.getElementById('resume-btn');
        this.statWindow = document.getElementById('stat-window');
        this.statFocus = document.getElementById('stat-focus');
        this.statMonitors = document.getElementById('stat-monitors');

        this.init();
    }

    init() {
        // Fullscreen Change
        document.addEventListener('fullscreenchange', () => this.handleFullscreenChange());
        
        // Window Focus/Blur
        window.addEventListener('blur', () => this.handleViolation('Window Focus Lost', 'blur'));
        window.addEventListener('focus', () => this.handleFocus());
        
        // Window Resize
        window.addEventListener('resize', () => {
            if (this.isSessionActive && !document.fullscreenElement) {
                console.log('Resize detected outside fullscreen');
                this.handleViolation('Window Resized (Potential Split-screen)', 'resize');
            }
        });

        // Shortcut Blocking
        window.addEventListener('keydown', (e) => this.blockShortcuts(e));
        window.addEventListener('contextmenu', (e) => e.preventDefault());

        // Resume Button
        this.resumeBtn.addEventListener('click', () => this.requestFullscreen());
        
        // Detect Monitors
        this.detectMonitors();
    }

    start() {
        this.isSessionActive = true;
        this.requestFullscreen();
    }

    stop() {
        this.isSessionActive = false;
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
    }

    async requestFullscreen() {
        try {
            if (!document.fullscreenElement) {
                await document.documentElement.requestFullscreen();
            }
            // Only hide if we actually entered fullscreen
            if (document.fullscreenElement) {
                this.hideAlert();
            } else {
                this.showAlert('Fullscreen mode could not be activated. Please try again.');
            }
        } catch (err) {
            console.error('Fullscreen request failed:', err);
            this.showAlert('Fullscreen permission is required to continue. Check browser settings.');
        }
    }

    handleFullscreenChange() {
        if (this.isSessionActive) {
            if (!document.fullscreenElement) {
                this.handleViolation('Exited Fullscreen Mode', 'fullscreen');
                this.showAlert('Security Restriction: Fullscreen mode is mandatory.');
            } else {
                this.hideAlert();
            }
        }
        
        this.statWindow.textContent = document.fullscreenElement ? 'Fullscreen' : 'Windowed';
        this.statWindow.className = document.fullscreenElement ? 'safe-text' : 'violation-text';
    }

    handleFocus() {
        this.statFocus.textContent = 'Active';
        this.statFocus.className = 'safe-text';
    }

    handleViolation(message, type) {
        if (!this.isSessionActive) return;
        
        this.violations++;
        this.callbacks.onIncident(message, type);
        
        if (type === 'blur') {
            this.statFocus.textContent = 'Background';
            this.statFocus.className = 'violation-text';
        }
    }

    blockShortcuts(e) {
        if (!this.isSessionActive) return;

        // Block Ctrl+C, Ctrl+V, Ctrl+Shift+I, F12
        const isControl = e.ctrlKey || e.metaKey;
        if (
            (isControl && (e.key === 'c' || e.key === 'v' || e.key === 'u')) || // Copy, Paste, View Source
            (e.key === 'F12') || 
            (isControl && e.shiftKey && (e.key === 'I' || e.key === 'C' || e.key === 'J')) // DevTools
        ) {
            e.preventDefault();
            this.handleViolation(`Blocked Shortcut: ${e.key}`, 'shortcut');
        }
    }

    async detectMonitors() {
        try {
            // Check if newer API is available
            if ('getScreenDetails' in window) {
                const screenDetails = await window.getScreenDetails();
                this.statMonitors.textContent = screenDetails.screens.length;
                screenDetails.addEventListener('screenschange', () => {
                    this.statMonitors.textContent = screenDetails.screens.length;
                    if (this.isSessionActive && screenDetails.screens.length > 1) {
                        this.handleViolation('Multiple Monitors Detected', 'hardware');
                    }
                });
            } else {
                // Fallback: estimate based on screen size
                this.statMonitors.textContent = '1 (Estimated)';
            }
        } catch (err) {
            this.statMonitors.textContent = 'Unknown';
        }
    }

    showAlert(message) {
        this.alertMsg.textContent = message;
        this.overlay.classList.remove('hidden');
    }

    hideAlert() {
        this.overlay.classList.add('hidden');
    }
}

/**
 * Proctoring Dashboard Controller
 */
class Dashboard {
    constructor() {
        this.engine = null;
        this.startTime = null;
        this.timerInterval = null;
        this.isMonitoring = false;

        // UI Elements
        this.video = document.getElementById('webcam');
        this.startBtn = document.getElementById('start-btn');
        this.stopBtn = document.getElementById('stop-btn');
        this.statusBadge = document.getElementById('engine-status');
        this.scoreDisplay = document.getElementById('suspicion-score');
        this.scoreFill = document.getElementById('score-fill');
        this.timer = document.getElementById('timer');
        this.logsContainer = document.getElementById('event-logs');
        
        // Stats Elements
        this.statFaces = document.getElementById('stat-faces');
        this.statGaze = document.getElementById('stat-gaze');
        this.statAway = document.getElementById('stat-away');
        this.statMouth = document.getElementById('stat-mouth');
        this.statAudio = document.getElementById('stat-audio');
        this.statTabs = document.getElementById('stat-tabs');

        this.security = new SecurityManager({
            onIncident: (msg, type) => this.addLog(`SECURITY: ${msg}`, 'critical')
        });

        this.init();
    }

    async init() {
        this.bindEvents();
        this.setupTabs();

        try {
            this.updateStatus('Initializing Modules...');
            
            // 1. Initialize the engine singleton
            this.engine = ProctoringEngine.getInstance({
                enableVisualDetection: true,
                enableAudioMonitoring: true,
                enablePatternDetection: true,
                enableBrowserTelemetry: true,
                detectionFPS: 10,
                
                onEvent: (event) => this.handleEvent(event),
                onBehavioralPattern: (pattern) => this.handlePattern(pattern),
                onStatusChange: (status) => this.updateStatus(status),
                onError: (error) => this.addLog(`Error: ${error}`, 'critical')
            });

            // 2. Initialize modules (loads MediaPipe models)
            await this.engine.initialize();
            this.updateStatus('Ready to Start');
            this.startBtn.disabled = false;
            
            // 3. Subscribe to state updates for stats tab
            this.engine.stateManager.subscribe((state) => this.updateStats(state));

        } catch (error) {
            console.error('Initialization failed:', error);
            this.addLog(`Initialization Error: ${error.message}`, 'critical');
            this.updateStatus('Error');
        }
    }

    bindEvents() {
        this.startBtn.addEventListener('click', () => this.startSession());
        this.stopBtn.addEventListener('click', () => this.stopSession());
    }

    setupTabs() {
        const tabs = document.querySelectorAll('.tab-btn');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                
                // Toggle active state
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Toggle content
                document.getElementById('logs-tab').classList.add('hidden');
                document.getElementById('stats-tab').classList.add('hidden');
                document.getElementById(`${target}-tab`).classList.remove('hidden');
            });
        });
    }

    async startSession() {
        try {
            this.updateStatus('Starting Camera...');
            
            // Request camera access
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { width: 1280, height: 720 },
                audio: true 
            });
            
            this.video.srcObject = stream;
            await this.video.play();

            // Start the engine
            this.engine.start(this.video);
            
            this.isMonitoring = true;
            this.startTime = Date.now();
            this.startTimer();
            
            this.startBtn.disabled = true;
            this.stopBtn.disabled = false;
            this.updateStatus('Monitoring Active');
            this.addLog('Proctoring session started successfully.', 'system');
            
            // Start security manager
            this.security.start();

        } catch (error) {
            this.addLog(`Camera Error: ${error.message}`, 'critical');
            this.updateStatus('Camera Access Denied');
        }
    }

    stopSession() {
        this.engine.stop();
        this.security.stop();
        this.isMonitoring = false;
        clearInterval(this.timerInterval);
        
        // Stop camera stream
        if (this.video.srcObject) {
            this.video.srcObject.getTracks().forEach(track => track.stop());
        }

        this.startBtn.disabled = false;
        this.stopBtn.disabled = true;
        this.updateStatus('Session Ended');
        
        const summary = this.engine.getSessionSummary();
        this.addLog(`Session ended. Total Events: ${summary.totalEvents}. Final Score: ${summary.suspiciousScore}`, 'system');
    }

    handleEvent(event) {
        // Map severity levels to CSS classes
        let severity = 'info';
        if (event.lv >= 9) severity = 'critical';
        else if (event.lv >= 7) severity = 'high';
        else if (event.lv >= 5) severity = 'medium';

        // Log events with severity above a threshold to the UI
        if (event.lv >= 5) {
            this.addLog(`${event.event.replace(/_/g, ' ')}`, severity);
        }

        // Update score display
        this.updateScore();
    }

    handlePattern(pattern) {
        // Behavioral patterns are critical
        this.addLog(`PATTERN DETECTED: ${pattern.pattern.replace(/([A-Z])/g, ' $1')}`, 'critical');
        this.updateScore();
    }

    updateScore() {
        const score = this.engine.calculateSuspiciousScore();
        this.scoreDisplay.textContent = score;
        this.scoreFill.style.width = `${Math.min(100, (score / 1000) * 100)}%`;
        
        // Change color based on score
        if (score > 500) this.scoreFill.style.background = 'var(--danger)';
        else if (score > 200) this.scoreFill.style.background = 'var(--warning)';
        else this.scoreFill.style.background = 'linear-gradient(90deg, var(--primary), var(--secondary))';
    }

    updateStats(state) {
        if (!state) return;
        
        // Visual Stats
        const visual = state.visual;
        this.statFaces.textContent = visual.numFaces;
        this.statGaze.textContent = visual.currentGazeDirection.charAt(0).toUpperCase() + visual.currentGazeDirection.slice(1);
        
        // Use isTalkingByMouth as it is the property actually updated by the detection module
        this.statAway.textContent = (visual.currentGazeDirection !== 'center' && visual.currentGazeDirection !== 'unknown') ? 'Yes' : 'No';
        this.statMouth.textContent = (visual.isMouthMoving || visual.isTalkingByMouth) ? 'Yes' : 'No';
        
        // Audio Stats
        const audio = state.audio;
        this.statAudio.textContent = `${Math.round(audio.currentAudioLevel)} dB`;

        // Telemetry Stats (accessed via engine summary)
        const summary = this.engine.getSessionSummary();
        this.statTabs.textContent = summary.eventCounts?.TAB_SWITCHED || 0;
    }

    addLog(message, type = 'info') {
        const entry = document.createElement('div');
        entry.className = `log-entry ${type}`;
        
        const timestamp = new Date().toLocaleTimeString();
        entry.innerHTML = `
            <span class="log-time">${timestamp}</span>
            <span class="log-message">${message}</span>
        `;
        
        this.logsContainer.prepend(entry);
    }

    updateStatus(status) {
        this.statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        
        // Color coding
        if (status.includes('ready')) {
            this.statusBadge.style.color = 'var(--success)';
            this.statusBadge.style.borderColor = 'var(--success)';
        } else if (status.includes('error')) {
            this.statusBadge.style.color = 'var(--danger)';
            this.statusBadge.style.borderColor = 'var(--danger)';
        } else if (status.includes('Active')) {
            this.statusBadge.style.color = 'var(--secondary)';
            this.statusBadge.style.borderColor = 'var(--secondary)';
        }
    }

    startTimer() {
        this.timerInterval = setInterval(() => {
            const diff = Date.now() - this.startTime;
            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            
            this.timer.textContent = [h, m, s]
                .map(v => v.toString().padStart(2, '0'))
                .join(':');
        }, 1000);
    }
}

// Start the dashboard
document.addEventListener('DOMContentLoaded', () => {
    new Dashboard();
});
