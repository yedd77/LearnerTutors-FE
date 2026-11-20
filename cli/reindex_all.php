<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Queue a site-wide rebuild of every stored RAG Assistant document.
 *
 * Use this after changing the backend's embedding model (in its .env), because
 * the backend does not report that change to Moodle. The rebuild runs as the
 * existing reindex_documents ad hoc task, so it executes on the next cron run
 * or immediately with: php admin/cli/adhoc_task.php --execute
 *
 * @package local_ragassistant
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    echo "Queue a site-wide rebuild of every stored RAG Assistant document.

Run this after changing the backend embedding model. Every document in every
course is re-sent to the backend for indexing and its suggestions are refreshed.

Options:
-h, --help    Print this help.

Example (from the Moodle root):
\$ php public/local/ragassistant/cli/reindex_all.php
\$ php admin/cli/adhoc_task.php --execute
";
    exit(0);
}

$count = $DB->count_records('local_ragassistant_documents');
if ($count === 0) {
    cli_writeln('No RAG Assistant documents are stored; nothing to rebuild.');
    exit(0);
}

// The second argument reuses an identical rebuild that is already waiting instead of
// adding a duplicate; in that case Moodle returns the existing task's id.
$taskid = \core\task\manager::queue_adhoc_task(new \local_ragassistant\task\reindex_documents(), true);

if (!$taskid) {
    cli_error('Moodle could not queue the rebuild task.');
}
cli_writeln("Rebuild of {$count} document(s) is queued (task id {$taskid}).");
cli_writeln('It runs on the next cron run, or now with: php admin/cli/adhoc_task.php --execute');
exit(0);
