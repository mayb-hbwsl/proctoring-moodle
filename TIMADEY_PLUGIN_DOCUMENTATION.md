# Timadey AI Proctoring Plugin — Complete Technical Documentation

**Plugin Name:** Timadey AI Proctoring  
**Component:** local_timadey  
**Version:** 1.0.0 (Build 2026050402)  
**Maturity:** Beta  
**Requires:** Moodle 4.0 or higher  

---

## Table of Contents

1. What This Plugin Does
2. File Structure
3. Installation
4. How It Works — End to End
   - 4.1 How the Plugin Loads Into a Quiz
   - 4.2 How the Camera Starts
   - 4.3 How AI Detection Works
   - 4.4 How Incidents Are Logged
   - 4.5 How Video Recording Works
   - 4.6 How Video Is Stored and Served
5. Severity Levels and All Detected Events
6. Admin Pages
   - 6.1 Proctoring Incidents
   - 6.2 Proctoring Recordings
7. Auto-Deletion of Videos
8. Database Tables
9. All Endpoints
10. Third-Party Packages and Assets
11. FFmpeg — What It Does and Why
12. Running Moodle Cron — Local and Production
13. Known Limitations and Notes

---

## 1. What This Plugin Does

Timadey is a server-side Moodle local plugin that silently activates on any Moodle quiz attempt page and performs three tasks simultaneously during the quiz:

**Video Recording** — Records the student's webcam as a WebM video file. The video is uploaded to the server in 10-second chunks while the quiz is in progress. The student does not see any recording indicator.

**AI-Based Behaviour Detection** — Runs an on-device AI engine in the browser that detects suspicious behaviour in real time, including face absence, multiple faces, gaze direction, talking, phone use, tab switching, keyboard shortcuts, and more. Every detected event is logged to the database instantly.

**Suspicion Scoring** — Calculates a cumulative suspicion score for the session based on the weighted severity of all events detected.

Admins can review everything from two dedicated admin pages:

- Proctoring Incidents page — full event log per attempt with timestamps and severity
- Proctoring Recordings page — video playback synced with the event timeline

The plugin is completely invisible to the student. There is no popup, no warning, and no visible change to the quiz interface beyond a small webcam overlay in the corner.

---

## 2. File Structure

```
local/timadey/
|
|-- version.php
|-- lib.php
|-- settings.php
|-- log_incident.php
|-- save_recording.php
|-- merge_recording.php
|-- recordings.php
|-- incidents.php
|
|-- assets/
|   |-- moodle-proctor-bundle.iife.js
|   |-- moodle-proctor-bundle.css
|   |-- recorder.js
|   |-- tmp/
|   |-- wasm/
|       |-- vision_wasm_internal.js
|       |-- vision_wasm_internal.wasm
|
|-- db/
|   |-- install.xml
|   |-- upgrade.php
|   |-- tasks.php
|   |-- access.php
|
|-- lang/
|   |-- en/
|       |-- local_timadey.php
|
|-- classes/
    |-- task/
        |-- process_recordings.php
```

| File | Purpose |
|---|---|
| version.php | Plugin version and metadata |
| lib.php | Moodle hooks that inject assets into quiz pages |
| settings.php | Registers the two admin menu links |
| log_incident.php | API endpoint that receives events from the browser |
| save_recording.php | API endpoint that receives video chunks from the browser |
| merge_recording.php | Merges video chunks via FFmpeg and streams the result |
| recordings.php | Admin video review page |
| incidents.php | Admin event log page |
| moodle-proctor-bundle.iife.js | Bundled AI proctoring engine |
| moodle-proctor-bundle.css | Styles for the webcam overlay |
| recorder.js | Lightweight webcam video recorder |
| vision_wasm_internal.wasm | Compiled MediaPipe AI binary (WebAssembly) |
| install.xml | Database table definitions |
| upgrade.php | Database upgrade script for new versions |
| tasks.php | Registers the scheduled cleanup task |
| access.php | Capability definitions |
| process_recordings.php | Scheduled task that deletes recordings older than 24 hours |

---

## 3. Installation

### 3.1 Local Installation (Windows)

**Step 1** — Copy the plugin folder into your Moodle installation:

```
moodle/local/timadey/
```

**Step 2** — Open your browser and go to:

```
http://localhost/admin/index.php
```

**Step 3** — Moodle will detect the new plugin and display an upgrade prompt. Click "Upgrade Moodle database now".

This automatically creates the two database tables and registers the scheduled cleanup task. No further configuration is required. The plugin is immediately active on all quiz attempt pages.

**Step 4** — Install FFmpeg (required for video playback in the admin page):

```
winget install Gyan.FFmpeg
```

The plugin finds FFmpeg automatically at the standard winget installation path.

---

### 3.2 Production Installation (Linux)

**Step 1** — Copy the plugin folder to the server:

```
scp -r local/timadey user@yourserver:/var/www/html/moodle/local/
```

**Step 2** — Set correct file ownership:

```
chown -R www-data:www-data /var/www/html/moodle/local/timadey
```

**Step 3** — Log in to Moodle as admin and visit /admin/index.php. Click through the upgrade prompt. This creates the database tables and registers the task.

**Step 4** — Install FFmpeg on the server:

```
apt install ffmpeg
```

**Step 5** — Set up system cron so the scheduled task runs automatically. See Section 12 for full instructions.

---

## 4. How It Works — End to End

### 4.1 How the Plugin Loads Into a Quiz

The entry point is lib.php. Moodle calls two hook functions on every page request:

- local_timadey_before_footer — fires just before the page footer is rendered
- local_timadey_extend_navigation — fires during navigation tree building

Both hooks call the same internal function local_timadey_inject_assets(). A static variable ensures the function only executes once per page load, even if both hooks fire.

Inside inject_assets(), the plugin reads the current URL from the server and checks for the string "attempt.php":

```
if (strpos($url, 'attempt.php') !== false)
```

If the URL contains attempt.php, the plugin injects three files into the page:

| File | Injection method | Purpose |
|---|---|---|
| moodle-proctor-bundle.css | PAGE->requires->css() | Webcam overlay styles |
| moodle-proctor-bundle.iife.js | PAGE->requires->js() | AI proctoring engine |
| recorder.js | PAGE->requires->js() | Video recorder |

If the page header has already been sent at the time the hook fires, the plugin falls back to echoing raw HTML link and script tags directly.

The plugin activates on both the standard Moodle quiz (mod/quiz/attempt.php) and the adaptive quiz (mod/adaptivequiz/attempt.php). It does not activate on any other page and has no impact on the rest of Moodle.

---

### 4.2 How the Camera Starts

When the proctoring bundle loads in the browser, it immediately begins the following sequence:

**Step 1 — Request media permissions.**  
The browser's getUserMedia API is called to request webcam and microphone access:

```
{ video: { width: 320, height: 240 }, audio: true }
```

The browser shows the student a permission prompt on first visit. If permissions were previously granted, the camera starts without a prompt.

**Step 2 — Create the video element.**  
The engine creates a hidden video element with the ID "timadey-webcam", sets its source to the live media stream, mutes it, and appends it to the page. The video plays silently in the background.

**Step 3 — Show the overlay.**  
A floating panel is added to the page showing the live webcam thumbnail, a status indicator (Monitoring Active), the current suspicion score, and the most recent event detected.

**Step 4 — recorder.js picks up the stream.**  
recorder.js polls the DOM every second looking for the timadey-webcam element:

```
var video = document.getElementById('timadey-webcam');
if (video && video.srcObject && video.readyState >= 2) {
    // start recording
}
```

Once the element exists and has readyState of 2 or higher (meaning data is available), recording begins automatically.

---

### 4.3 How AI Detection Works

The AI engine inside moodle-proctor-bundle.iife.js uses Google MediaPipe running entirely inside the student's browser. No video frames or images are ever sent to an external server. All inference is local.

Three MediaPipe models run simultaneously:

| Model | What it analyses |
|---|---|
| Face Landmarker | Face presence, count, eye positions, gaze direction, mouth movement, blink rate |
| Hand Landmarker | Hand poses, specifically the phone-holding gesture |
| Object Detector | Physical objects appearing in the camera frame |

The compiled WASM binary (vision_wasm_internal.wasm) is the MediaPipe runtime. It is downloaded once from the server and cached by the browser. Subsequent quiz attempts use the cached version and load instantly.

**Detection loop** — The engine runs a processing loop using requestAnimationFrame at up to 30 frames per second. Each frame is passed through all active models. Results feed into a state machine that tracks ongoing conditions across multiple frames, such as:

- How long the face has been absent from frame
- Whether multiple faces are currently visible
- The direction the student is looking and whether they are blinking
- Whether the mouth is opening and moving consistently (talking)
- Whether audio levels indicate talking or whispering
- Whether hand landmarks match a phone-holding pose
- Whether a suspicious object appears across several consecutive frames

**Audio analysis** runs independently via the Web Audio API using an AudioContext and AnalyserNode. It monitors the microphone continuously in parallel with the visual models.

**Event throttling** — Events of the same type are throttled to a minimum of one second apart on the JavaScript side to avoid flooding the database with duplicate entries.

---

### 4.4 How Incidents Are Logged

When the AI engine detects a violation, it calls an internal emitEvent function with three arguments: the event name, a raw numeric score, and a details object containing a string severity label and additional metadata.

The engine sends a POST request to /local/timadey/log_incident.php with a JSON body:

```
{
  "message":   "TAB_SWITCHED",
  "severity":  "critical",
  "timestamp": 1746123456789,
  "attemptid": 21,
  "sesskey":   "abc123xyz"
}
```

log_incident.php performs the following:

1. Verifies the Moodle session key to prevent CSRF attacks
2. Maps the string severity label to an integer for storage:

| String label | Integer stored |
|---|---|
| info | 1 |
| low | 3 |
| medium | 5 |
| high | 7 |
| critical | 9 |

3. Inserts a row into the local_timadey_incidents table

The eventtime field stores the original JavaScript timestamp in milliseconds. The timecreated field stores the Unix timestamp of when the server received the request.

---

### 4.5 How Video Recording Works

recorder.js uses the browser's built-in MediaRecorder API to record the webcam stream as a WebM video file. It has no external dependencies.

**Recording settings:**

| Setting | Value |
|---|---|
| Codec | video/webm;codecs=vp8,opus |
| Video bitrate | 200 kbps |
| Flush interval | 10 seconds |
| Chunk rotation | Every 5 minutes |

**Full recording lifecycle:**

1. Quiz page loads and assets are injected
2. recorder.js polls for the timadey-webcam element every second
3. Once the webcam is ready, MediaRecorder.start(10000) is called — this causes the recorder to fire the ondataavailable event every 10 seconds
4. On every ondataavailable event, the blob is uploaded to save_recording.php
5. Every 5 minutes, the current recorder is stopped, the chunk index increments, and a new recorder starts on the next chunk file
6. When the student submits the quiz, the page begins navigating away, which fires the beforeunload event
7. The beforeunload handler calls recorder.stop(), which fires one final ondataavailable event with any remaining buffered data
8. Because the page is unloading, this final blob is sent using navigator.sendBeacon instead of fetch

**First blob vs continuation blobs:**

The very first blob produced by a new MediaRecorder contains the WebM EBML header (the file initialisation segment). Every subsequent blob contains raw media clusters without a header. This distinction is critical for correct playback:

- First blob is uploaded with append=false — the server creates a new file and a new DB record
- All subsequent blobs are uploaded with append=true — the server appends them to the existing file

---

### 4.6 How Video Is Stored and Served

**Primary storage (moodledata):**

Each chunk is saved inside the Moodle data directory, outside the web root and not publicly accessible:

```
moodledata/timadey_recordings/{userid}/{attemptid}/chunk_0000.webm
                                                    chunk_0001.webm
                                                    chunk_0002.webm
```

**Public copy (tmp folder):**

Every upload also creates a copy in the web-accessible tmp folder:

```
local/timadey/assets/tmp/{userid}_{attemptid}_{chunkindex}.webm
```

This is the path the browser uses to play the video.

**Merging for playback:**

Individual 10-second blobs cannot be reliably seeked in a browser video element. When an admin opens recordings.php, the page:

1. Collects all chunk file paths for each session
2. Checks whether an up-to-date merged file already exists
3. If merging is needed, runs FFmpeg to concatenate all chunks into one seekable file:

```
ffmpeg -y -f concat -safe 0 -i filelist.txt -c copy -cues_to_front 1 merged.webm
```

The -c copy flag means no re-encoding takes place. FFmpeg simply repackages the streams. This is fast and lossless. The -cues_to_front 1 flag moves the index to the beginning of the file so that seeking works correctly in the browser player.

4. The merged file is saved to tmp/{userid}_{attemptid}_merged.webm
5. The HTML video element points to this merged file

**Duration detection:**

FFmpeg is used to determine the actual video duration:

```
ffmpeg -i merged.webm -f null -c copy NUL
```

The output is parsed for the time= field. The result is stored in a .dur sidecar file so that duration is only calculated once and reused on subsequent page loads.

---

## 5. Severity Levels and All Detected Events

### Score 9 — Critical

| Event | What triggers it |
|---|---|
| TAB_SWITCHED | Student hides or switches the browser tab |
| PERSON_LEFT | No face detected in frame for a sustained period |
| MULTIPLE_FACES | More than one face is detected simultaneously |
| PHONE_DETECTED | Hand landmark pose matches the phone-holding gesture |
| SUSPICIOUS_GAZE_READING | Eyes open but no blink detected for more than 10 seconds |
| PASTE_ATTEMPT | Ctrl+V keyboard shortcut detected |
| PAGE_UNLOAD_ATTEMPT | Student attempts to navigate away or close the tab |
| TALKING_DETECTED (visual) | Sustained mouth movement detected by the face landmarker |
| SUSPICIOUS_OBJECT | Object detector identifies a suspicious physical object across multiple frames |

---

### Score 7 — High

| Event | What triggers it |
|---|---|
| EYES_CLOSED | Eyes remain closed for more than 2 seconds |
| TALKING_DETECTED (audio) | Sustained audio above the talking threshold |
| TALKING_EPISODE | End of a continuous talking burst |
| COPY_ATTEMPT | Ctrl+C keyboard shortcut detected |
| CUT_ATTEMPT | Ctrl+X keyboard shortcut detected |
| WHISPERING_DETECTED | Low-level sustained audio above the whisper threshold |
| EXITED_FULLSCREEN | Student exits fullscreen mode |
| WINDOW_FOCUS_LOST | Browser window loses focus |

---

### Score 5 — Medium

| Event | What triggers it |
|---|---|
| RIGHT_CLICK | Right-click context menu is opened |
| SUSPICIOUS_KEY_PRESS | Developer tool shortcuts, Ctrl+U, or similar key combinations |

---

### Score 3 — Low

| Event | What triggers it |
|---|---|
| MOUSE_LEFT_WINDOW | Mouse cursor moves outside the browser window |

---

### Score 1 — Info

| Event | What triggers it |
|---|---|
| ENTERED_FULLSCREEN | Student enters fullscreen mode |
| MOUSE_ENTERED_WINDOW | Mouse cursor returns to the browser window |
| TAB_RETURNED | Student returns to the quiz tab after switching away |

---

## 6. Admin Pages

Both pages are accessible only to site administrators. They appear in the Moodle admin menu under Site Administration > Plugins > Local plugins.

### 6.1 Proctoring Incidents

**URL:** /local/timadey/incidents.php

This page displays a card for each recorded quiz session. Each card contains:

- **Header** — Student name, attempt number, date and time, severity badge counts (how many critical, high, medium, low, and info events occurred), and total event count
- **Summary bar** — The most frequent event types and how many times each occurred during the session
- **Event table** — Every individual event with its time (relative to session start), severity badge, and event name

A filter dropdown at the top of the page allows filtering by a specific attempt ID to narrow results.

---

### 6.2 Proctoring Recordings

**URL:** /local/timadey/recordings.php

This page displays a card for each recorded session. Each card contains:

- **Header** — Student name, attempt number, date, number of video clips, total file size in MB, and video duration
- **Left panel (65% width)** — The merged video player with standard browser controls
- **Right panel (35% width)** — Scrollable incident timeline showing every event with its timestamp and severity

**Video and timeline synchronisation** — As the video plays, the timeline automatically highlights the event closest to the current playback position and scrolls it into view. Clicking any event in the timeline seeks the video directly to that point in time.

**Timestamp calculation** — Each event's position on the timeline is calculated as:

```
offset (seconds) = event.timecreated - session_start_time
```

The session start time is taken from the earliest incident timestamp. If no incidents were recorded, it falls back to the first chunk upload time.

**Actions** — Each session card has an Open button (plays the video in a new tab) and a Download button for the merged WebM file.

---

## 7. Auto-Deletion of Videos

Videos are automatically deleted 24 hours after the session started to prevent disk exhaustion. This runs as a Moodle scheduled task.

### What Gets Deleted

- Raw chunk files from moodledata/timadey_recordings/{userid}/{attemptid}/
- Public chunk copies from assets/tmp/{userid}_{attemptid}_{chunkindex}.webm
- Merged video file: assets/tmp/{userid}_{attemptid}_merged.webm
- Duration cache file: assets/tmp/{userid}_{attemptid}_merged.webm.dur
- Empty directories left behind in moodledata
- Database records in local_timadey_recordings — sessions no longer appear in recordings.php

### What Is Not Deleted

- Incident logs in local_timadey_incidents — these are kept permanently as the audit trail

### How the Deletion Works

The scheduled task in classes/task/process_recordings.php runs the following logic:

1. Calculates the cutoff timestamp: current time minus 86400 seconds (24 hours)
2. Queries the database for all sessions where the earliest chunk was created before the cutoff
3. For each expired session, deletes all files listed above
4. Deletes the database records for that session

The query uses get_recordset_sql rather than get_records_sql to correctly handle multiple sessions per user without key collisions.

The task is configured in db/tasks.php to run every 5 minutes, but it only deletes sessions that are actually older than 24 hours, so running frequently has no side effects.

---

## 8. Database Tables

### Table: mdl_local_timadey_incidents

Stores every proctoring event detected during a quiz attempt.

| Column | Type | Description |
|---|---|---|
| id | INT(10), Primary Key | Auto-increment identifier |
| userid | INT(10) | Moodle user ID of the student |
| attemptid | INT(10) | Moodle quiz attempt ID |
| message | TEXT | Event name, e.g. TAB_SWITCHED |
| severity | INT(4) | Mapped integer: 1, 3, 5, 7, or 9 |
| eventtime | INT(13) | JavaScript timestamp in milliseconds when the event occurred |
| timecreated | INT(10) | Unix timestamp when the server received the event |

Indexes: userid, attemptid, severity

---

### Table: mdl_local_timadey_recordings

Tracks each uploaded video chunk. One row is created per chunk file.

| Column | Type | Description |
|---|---|---|
| id | INT(10), Primary Key | Auto-increment identifier |
| userid | INT(10) | Moodle user ID of the student |
| attemptid | INT(10) | Moodle quiz attempt ID |
| chunkindex | INT(10) | Sequential chunk number, starting from 0 |
| filepath | TEXT | Relative path inside moodledata |
| filesize | INT(10) | File size in bytes at time of first upload |
| timecreated | INT(10) | Unix timestamp when the chunk was first received |

Indexes: userid, attemptid

---

## 9. All Endpoints

### lib.php

Not a direct URL. This is the Moodle hook file. Moodle calls it on every page request. It checks the URL and injects the proctoring assets only on attempt.php pages.

---

### log_incident.php

**Method:** POST  
**URL:** /local/timadey/log_incident.php  
**Authentication:** Requires active Moodle login and a valid Moodle session key  
**Request body (JSON):**

| Field | Type | Description |
|---|---|---|
| message | string | Event name |
| severity | string | info, low, medium, high, or critical |
| timestamp | number | JavaScript timestamp in milliseconds |
| attemptid | number | Quiz attempt ID |
| sesskey | string | Moodle session key for CSRF verification |

**Response:** {"status":"ok"} on success, or an error JSON with an HTTP error code.  
**Purpose:** Receives detected events from the browser AI engine and stores them in the database.

---

### save_recording.php

**Method:** POST (multipart form data)  
**URL:** /local/timadey/save_recording.php  
**Authentication:** Requires active Moodle login and a valid sesskey in the form fields  
**Form fields:**

| Field | Type | Description |
|---|---|---|
| chunk | file | WebM blob from MediaRecorder |
| attemptid | number | Quiz attempt ID |
| chunk_index | number | Sequential chunk number |
| append | 0 or 1 | 0 = create new file, 1 = append to existing |
| sesskey | string | Moodle session key |

**Response:** {"status":"ok","chunk":0,"append":false}  
**Purpose:** Saves the blob to moodledata, inserts or skips the DB record depending on the append flag, and copies the file to the public tmp folder.

---

### recordings.php

**Method:** GET  
**URL:** /local/timadey/recordings.php  
**Authentication:** Site admin only  
**Purpose:** Admin video review page. Merges video chunks on demand using FFmpeg, then displays the synced video player and incident timeline for every recorded session.

---

### incidents.php

**Method:** GET  
**URL:** /local/timadey/incidents.php  
**Optional parameter:** attemptid (integer) — filters to a specific attempt  
**Authentication:** Site admin only  
**Purpose:** Admin event log page showing all incidents grouped by session.

---

### merge_recording.php

**Method:** GET  
**URL:** /local/timadey/merge_recording.php?attemptid=X&action=watch  
**Authentication:** Site admin only  
**Parameters:** attemptid (required), action = watch or download  
**Purpose:** Legacy merge-and-stream endpoint. Merges all chunks for the given attempt via FFmpeg and streams the result directly to the browser. watch serves it inline, download serves it as an attachment.

---

### settings.php

Not a direct URL. This Moodle settings file registers the two admin pages in the Site Administration menu under Local plugins.

---

## 10. Third-Party Packages and Assets

### MediaPipe (by Google)

MediaPipe is an on-device machine learning framework for real-time perception tasks. The plugin uses three MediaPipe solutions:

- Face Landmarker — detects face presence, counts faces, and tracks 478 facial landmarks including eyes, mouth, and nose
- Hand Landmarker — detects hands and 21 landmarks per hand, used to identify the phone-holding pose
- Object Detector — identifies physical objects in the camera frame

All three models run inside the student's browser. No video frames, images, or biometric data are sent to Google or any external server. The entire inference pipeline is local.

---

### vision_wasm_internal.wasm

This is the compiled WebAssembly binary that powers the MediaPipe runtime. WebAssembly allows the compiled C++ code to run at near-native speed inside the browser sandbox.

The file is downloaded once from /local/timadey/assets/wasm/ when the student first takes a proctored quiz. The browser caches it automatically. All subsequent quiz attempts load it from the local browser cache, making startup faster.

The wasm file is several megabytes in size. It is the largest single asset the plugin serves.

---

### moodle-proctor-bundle.iife.js

This is the main proctoring engine. It is a production-built JavaScript bundle in IIFE (Immediately Invoked Function Expression) format, meaning it executes as soon as it is parsed by the browser. It contains:

- MediaPipe integration and model loading
- Face detection module (gaze, blink, mouth, multi-face)
- Hand detection module (phone gesture)
- Object detection module
- Audio analysis module (Web Audio API)
- Keyboard and clipboard event listeners
- Tab visibility and window focus listeners
- Mouse tracking listeners
- Event emission and throttling logic
- Webcam overlay UI rendering
- Suspicion score calculation
- Communication with log_incident.php

---

### recorder.js

A hand-written, lightweight script with no external dependencies. It uses only the browser's built-in MediaRecorder API. At approximately 113 lines of code it is intentionally minimal to avoid any risk of it disrupting the quiz.

Settings that can be changed directly in this file:

| Constant | Default | Effect |
|---|---|---|
| ENDPOINT | /local/timadey/save_recording.php | Upload destination |
| CHUNK_DURATION | 300000 ms (5 min) | How often to rotate to a new chunk file |
| TIMESLICE | 10000 ms (10 sec) | How often to flush buffered data to the server |
| VIDEO_BITRATE | 200000 bps (200 kbps) | Video quality |

---

## 11. FFmpeg — What It Does and Why

FFmpeg is an open-source command-line tool for video and audio processing. The plugin uses it server-side for two specific tasks.

### Task 1 — Merging Chunks for Playback

Individual blobs recorded by MediaRecorder cannot be reliably seeked in a browser video element because each blob has an independent timeline. FFmpeg concatenates all chunks and adds a cue index at the beginning of the output file. This makes the file fully seekable.

Command used:

```
ffmpeg -y -f concat -safe 0 -i filelist.txt -c copy -cues_to_front 1 output.webm
```

The -c copy flag means the video is not re-encoded. FFmpeg simply repackages the streams into a new container. This process is fast and produces no quality loss.

### Task 2 — Calculating Video Duration

The browser's video element cannot reliably report the duration of WebM files produced by MediaRecorder. FFmpeg decodes the file to determine the actual playback duration.

Command used:

```
ffmpeg -i merged.webm -f null -c copy NUL
```

The output contains a line in the format time=HH:MM:SS.ss. The plugin parses this and stores the result in a .dur sidecar file so the calculation only runs once per merged file.

### FFmpeg Search Order

The plugin searches for FFmpeg in the following locations:

1. Windows winget install path (C:\Users\...\ffmpeg-8.1-full_build\bin\ffmpeg.exe)
2. /usr/bin/ffmpeg
3. /usr/local/bin/ffmpeg
4. /opt/ffmpeg/bin/ffmpeg
5. System PATH via the which command

If FFmpeg is not found, video upload and storage still work correctly. Only the playback in recordings.php is affected — the page shows a "Merge failed — FFmpeg not available" message instead of the video player.

---

## 12. Running Moodle Cron — Local and Production

The auto-deletion scheduled task only runs when Moodle's internal cron system is triggered. Moodle does not run cron on its own — something external must call it periodically.

### Local — Windows

A background PowerShell script handles this. It was set up at:

```
C:\Users\msacc\Downloads\moodle\server\moodle-cron.ps1
```

The script runs PHP cron every 5 minutes silently in the background and writes a log to moodle-cron.log in the same folder.

**Important:** The script stops when the computer is restarted. To start it again after a reboot, run the following command:

```
powershell -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\Users\msacc\Downloads\moodle\server\moodle-cron.ps1"
```

To make it start automatically on every login, save the above as a .bat file and place it in the Windows Startup folder. Open the Startup folder by pressing Win+R and typing:

```
shell:startup
```

---

### Production — Linux

Add one line to the web server user's crontab.

Open the crontab for editing:

```
crontab -u www-data -e
```

Add the following line:

```
* * * * * /usr/bin/php /var/www/html/moodle/admin/cli/cron.php >/dev/null 2>&1
```

Replace /var/www/html/moodle with the actual path to your Moodle installation, and replace www-data with the user your web server runs as (commonly www-data, apache, or nginx).

This fires every minute. Moodle itself throttles the cleanup task to run only every 5 minutes as declared in db/tasks.php, so triggering cron every minute is the correct and recommended approach.

This crontab entry survives server reboots automatically.

**To verify it is working:**

```
sudo -u www-data php /var/www/html/moodle/admin/cli/cron.php
```

Look in the output for a line containing:

```
Execute scheduled task: Timadey: Clean Up Old Proctoring Recordings
```

---

## 13. Known Limitations and Notes

### Browser Support

The plugin requires a modern browser with support for MediaRecorder, getUserMedia, and WebAssembly.

| Browser | Support |
|---|---|
| Chrome | Full support |
| Edge | Full support |
| Firefox | Supported, WASM performance may vary |
| Safari | Limited — MediaRecorder codec support is restricted |

### Camera Permission

The browser will show a permission prompt the first time a student opens a proctored quiz. If the student denies the camera or microphone permission, the proctoring engine cannot run. No recording is made and no incidents are logged. There is currently no mechanism to block quiz access if camera permission is denied. This would need to be enforced at the quiz configuration level.

### Short Quizzes

With a 10-second flush interval, any quiz that lasts longer than 10 seconds will have at least one chunk uploaded to the server before submission. For quizzes shorter than 10 seconds, only the final sendBeacon upload on page unload is relied upon. Browser sendBeacon calls can occasionally be dropped by the browser if the payload is too large or if the page unloads faster than the browser can queue the request.

### Incident Logs Are Permanent

Only video files are deleted after 24 hours. The rows in local_timadey_incidents are never deleted and serve as the permanent audit trail. For production deployments subject to data privacy regulations, a separate data retention policy for incident logs should be defined.

### FFmpeg Path on Windows

The FFmpeg installation path is partially hardcoded to the winget install location for the development machine. On any other Windows machine, FFmpeg should be added to the system PATH so the plugin can find it via the which fallback.

### Video File Size

At 200 kbps, one hour of recording produces approximately 90 MB. For a deployment with many concurrent students taking long exams, disk space and the 24-hour deletion window should be considered carefully.

### Merged Files Are Regenerated When New Chunks Arrive

If a student's quiz is still in progress and an admin opens recordings.php, the merged file will be regenerated on the next page load to include the latest chunks. This is handled automatically by comparing the merged file timestamp against the last chunk upload time.

### Works on Both Quiz Types

The plugin detects attempt.php in the URL and therefore activates on both the standard Moodle quiz (mod/quiz/attempt.php) and the adaptive quiz module (mod/adaptivequiz/attempt.php). No additional configuration is needed for adaptive quizzes.
