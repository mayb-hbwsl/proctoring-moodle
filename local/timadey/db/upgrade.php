<?php
/**
 * Upgrade script for the Timadey AI Proctoring plugin.
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_timadey_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026043000) {

        // 1. Add current_score field to local_timadey_incidents table
        $table = new xmldb_table('local_timadey_incidents');
        $field = new xmldb_field('current_score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'severity');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Create local_timadey_scores table
        $table = new xmldb_table('local_timadey_scores');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('final_score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('max_score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('idx_attempt', XMLDB_INDEX_UNIQUE, array('attemptid'));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Local savepoint reach
        upgrade_plugin_savepoint(true, 2026043000, 'local', 'timadey');
    }

    return true;
}
