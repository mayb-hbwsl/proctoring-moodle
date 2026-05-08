<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_timadey', get_string('pluginname', 'local_timadey', null, true) ?: 'Timadey Proctoring');

    $settings->add(new admin_setting_configtext(
        'local_timadey/ffmpeg_path',
        'FFmpeg executable path',
        'Full path to the ffmpeg binary on this server (e.g. /usr/bin/ffmpeg or C:/ffmpeg/bin/ffmpeg.exe). Leave blank to auto-detect.',
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_timadey_recordings',
        'Timadey: Proctoring Recordings',
        new moodle_url('/local/timadey/recordings.php')
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_timadey_incidents',
        'Timadey: Proctoring Incidents',
        new moodle_url('/local/timadey/incidents.php')
    ));
}
