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

    if ($oldversion < 2026111600) {
        $table = new xmldb_table('local_ragassistant_suggestions');
        $field = new xmldb_field('slot', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'userid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            // Preserve existing generated suggestions by assigning stable slots per document.
            $records = $DB->get_records('local_ragassistant_suggestions', null, 'documentid ASC, id ASC');
            $nextslots = [];
            foreach ($records as $record) {
                $documentid = (int) $record->documentid;
                $nextslots[$documentid] = ($nextslots[$documentid] ?? 0) + 1;
                $DB->set_field(
                    'local_ragassistant_suggestions',
                    'slot',
                    min($nextslots[$documentid], 3),
                    ['id' => $record->id]
                );
            }
        }

        $index = new xmldb_index('document_slot_uix', XMLDB_INDEX_UNIQUE, ['documentid', 'slot']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'ragassistant');
    }

    return true;
}
