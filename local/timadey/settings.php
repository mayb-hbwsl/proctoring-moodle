<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
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
