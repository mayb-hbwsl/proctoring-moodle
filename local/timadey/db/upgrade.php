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

    return true;
}
