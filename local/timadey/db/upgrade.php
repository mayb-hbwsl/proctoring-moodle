<?php
// This file is part of the Timadey AI Proctoring plugin for Moodle
defined('MOODLE_INTERNAL') || die();

function xmldb_local_timadey_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050401) {
        $table = new xmldb_table('local_timadey_recordings');

        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attemptid',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('chunkindex',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filepath',    XMLDB_TYPE_TEXT,    null,   null, XMLDB_NOTNULL, null, null);
        $table->add_field('filesize',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_userid',    XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('idx_attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050401, 'local', 'timadey');
    }

    if ($oldversion < 2026050601) {
        // Add unique index on (userid, attemptid, chunkindex) to prevent duplicate inserts.
        $table = new xmldb_table('local_timadey_recordings');
        $index = new xmldb_index('uniq_user_attempt_chunk', XMLDB_INDEX_UNIQUE, ['userid', 'attemptid', 'chunkindex']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026050601, 'local', 'timadey');
    }

    return true;
}
