<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

function xmldb_local_ragassistant_upgrade(int $oldversion): bool
{
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026101403) {
        $table = new xmldb_table('local_ragassistant_documents');
        $field = new xmldb_field('lecturernote', XMLDB_TYPE_TEXT, null, null, null, null, null, 'visibility');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ragassistant_coursecfg');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('allowstudentchat', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('allowstudentupload', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('courseid_uix', XMLDB_INDEX_UNIQUE, ['courseid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101403, 'local', 'ragassistant');
    }

    if ($oldversion < 2026101500) {
        $table = new xmldb_table('local_ragassistant_documents');
        $field = new xmldb_field(
            'retrievalenabled',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'status'
        );

        if (
            $dbman->table_exists($table) &&
            !$dbman->field_exists($table, $field)
        ) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101500, 'local', 'ragassistant');
    }

    if ($oldversion < 2026110600) {
        $table = new xmldb_table('local_ragassistant_suggestions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('documentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('suggestion', XMLDB_TYPE_CHAR, '500', null, XMLDB_NOTNULL, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('documentid_fk', XMLDB_KEY_FOREIGN, ['documentid'], 'local_ragassistant_documents', ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('course_doc_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'documentid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110600, 'local', 'ragassistant');
    }

    if ($oldversion < 2026111200) {
        $table = new xmldb_table('local_ragassistant_documents');
        $fields = [
            new xmldb_field('progress', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'status'),
            new xmldb_field('stage', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '', 'progress'),
            new xmldb_field('jobtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '', 'stage'),
        ];
        foreach ($fields as $field) {
            if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026111200, 'local', 'ragassistant');
    }

    if ($oldversion < 2026111600) {
        $table = new xmldb_table('local_ragassistant_suggestions');
        $slotfield = new xmldb_field('slot', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'userid');
        $statusfield = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'ready', 'suggestion');
        $modifiedfield = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');

        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $slotfield)) {
            $dbman->add_field($table, $slotfield);
        }
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $statusfield)) {
            $dbman->add_field($table, $statusfield);
        }
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $modifiedfield)) {
            $dbman->add_field($table, $modifiedfield);
        }

        if ($dbman->table_exists($table)) {
            $records = $DB->get_records('local_ragassistant_suggestions', null, 'documentid ASC, id ASC');
            $slots = [];
            foreach ($records as $record) {
                $documentid = (int) $record->documentid;
                $slots[$documentid] = ($slots[$documentid] ?? 0) + 1;
                $DB->set_field('local_ragassistant_suggestions', 'slot', min($slots[$documentid], 3), ['id' => $record->id]);
                $DB->set_field('local_ragassistant_suggestions', 'status', 'ready', ['id' => $record->id]);
                $DB->set_field('local_ragassistant_suggestions', 'timemodified', (int) $record->timecreated, ['id' => $record->id]);
            }
            $index = new xmldb_index('document_slot_uix', XMLDB_INDEX_UNIQUE, ['documentid', 'slot']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'ragassistant');
    }

    return true;
}
