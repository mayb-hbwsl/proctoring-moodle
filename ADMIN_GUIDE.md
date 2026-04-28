# Timadey AI Proctoring — Admin Setup Guide
**For: Moodle Administrator (Linux Server)**

---

## Step 1: Install the Plugin (5 minutes)

```bash
# SSH into the Moodle server, then:
cd /var/www/html/moodle/local/      # adjust path if Moodle is elsewhere
git clone https://github.com/mayb-hbwsl/proctoring-moodle.git /tmp/proctoring
cp -r /tmp/proctoring/local/timadey ./timadey
chown -R www-data:www-data ./timadey   # match your web server user
rm -rf /tmp/proctoring
```

Then visit: **http://learn.hbwsl.com/admin/index.php**
→ Moodle will show "Plugins requiring attention" → Click **Upgrade Moodle database**
→ Done. The plugin is installed.

---

## Step 2: Create a Proctored Quiz

### 2A. Create the Quiz
1. Go to your **Course** → **Turn editing on**
2. Click **Add an activity → Quiz**
3. Fill in: Name, Description, Time limit, etc.
4. Under **Grade** → set passing grade
5. Under **Question bank** → add your questions

### 2B. Enable Proctoring (Automatic)
**No extra quiz settings needed!** The Timadey plugin automatically injects the AI proctor on ALL quiz attempt pages. Once the plugin is installed, every quiz is proctored.

### 2C. (Optional) Enable Safe Exam Browser
If you want to lock students into a secure browser:
1. In Quiz Settings → **Extra restrictions on attempts**
2. Set **"Require the use of Safe Exam Browser"** → Yes
3. Under **"Show Safe Exam Browser download button"** → Yes
4. Save

Then share the `TimadeyExam.seb` file with students (it's in the GitHub repo).

---

## Step 3: Viewing Proctoring Reports

Incidents are stored in the database table `local_timadey_incidents`.

Quick query to see all incidents for a quiz attempt:
```sql
SELECT u.firstname, u.lastname, i.message, i.severity, 
       FROM_UNIXTIME(i.timecreated) as time
FROM mdl_local_timadey_incidents i
JOIN mdl_user u ON u.id = i.userid
WHERE i.attemptid = <ATTEMPT_ID>
ORDER BY i.eventtime;
```

Or view all high-severity incidents:
```sql
SELECT u.firstname, u.lastname, i.message, i.severity,
       FROM_UNIXTIME(i.timecreated) as time
FROM mdl_local_timadey_incidents i
JOIN mdl_user u ON u.id = i.userid
WHERE i.severity >= 8
ORDER BY i.timecreated DESC
LIMIT 50;
```

---

## What the AI Proctor Detects

| Detection | Severity | Description |
|-----------|----------|-------------|
| No Face | 8 | Student's face not visible |
| Person Left | Critical | Student left the frame |
| Multiple Faces | 10 | More than one person visible |
| Looking Away | 6-9 | Gaze directed left/right/down |
| Head Turned | 7 | Head significantly turned |
| Talking Detected | 8-9 | Mouth movement or audio detected |
| Whispering | 7 | Low-level speech detected |
| Phone Detected | 9 | Hand pose suggests phone use |
| Suspicious Object | 9 | Phone/book/laptop visible |
| Tab Switched | 10 | Student switched browser tab |
| Window Focus Lost | 8 | Student clicked outside browser |
| Copy/Paste | 7-9 | Clipboard usage detected |

---

## Server Requirements

- **Moodle 4.0+** (PHP 7.4+, MySQL/MariaDB/PostgreSQL)
- **HTTPS strongly recommended** — camera/mic access requires HTTPS in modern browsers
  - Without HTTPS: only works inside Safe Exam Browser
  - With HTTPS: works in any browser
- **Outbound access** to `storage.googleapis.com` — AI models (~15 MB) are downloaded once per student and cached locally in their browser

---

## Student Requirements (Windows)

| Requirement | Details |
|-------------|---------|
| **OS** | Windows 10/11 |
| **Browser** | Chrome, Edge, or Firefox (latest) |
| **Safe Exam Browser** | Download from [safeexambrowser.org](https://safeexambrowser.org/download_en.html) (Windows) |
| **Webcam** | Any USB or built-in webcam |
| **Microphone** | Any mic (built-in is fine) |
| **Internet** | Required (for first exam — AI models download). After that, models are cached. |

### Student Flow:
1. Download & install **Safe Exam Browser**
2. Download the `.seb` config file from the instructor
3. Double-click the `.seb` file → SEB opens and navigates to Moodle
4. Log in → go to quiz → click "Attempt quiz"
5. **Allow camera + microphone** when prompted
6. Take the quiz — AI monitors in the background via a small webcam widget

---

## File Structure on Server

```
/var/www/html/moodle/local/timadey/
├── version.php                          # Plugin metadata
├── lib.php                              # Auto-injects JS on quiz pages
├── log_incident.php                     # API endpoint for incident logging
├── assets/
│   ├── moodle-proctor-bundle.iife.js    # AI proctoring engine (190 KB)
│   ├── moodle-proctor-bundle.css        # Widget styles (1.5 KB)
│   └── wasm/
│       ├── vision_wasm_internal.js      # MediaPipe WASM loader (324 KB)
│       └── vision_wasm_internal.wasm    # MediaPipe WASM binary (11.5 MB)
└── db/
    ├── install.xml                      # DB table definition (auto-created)
    └── access.php                       # Permission definitions
```
