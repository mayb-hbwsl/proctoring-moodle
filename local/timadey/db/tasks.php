<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_timadey\task\process_recordings',
        'blocking'  => 0,
        'minute'    => '*/5',   // every 5 minutes
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
