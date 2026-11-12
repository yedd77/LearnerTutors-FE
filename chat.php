<?php
// Chat conversation page 

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/api_client.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/course_policy.php');
use local_ragassistant\local\markdown_renderer;

/**
 * Render a user question with its known slash command highlighted.
 *
 * @param string $question
 * @return string
 */
function local_ragassistant_render_question(string $question): string
{
    $commands = [
        '/exam' => '#1e88e5',
        '/notes' => '#1e88e5',
        '/summarize' => '#1e88e5',
        '/flashcards' => '#1e88e5',
        '/explain' => '#1e88e5',
        '/quiz' => '#1e88e5',
        '/studyguide' => '#1e88e5',
        '/extract' => '#1e88e5',
    ];

    if (!preg_match('/^(\s*)(\/[^\s]*)(?=\s|$)/', $question, $matches)) {
        return s($question);
    }

    $command = core_text::strtolower($matches[2]);
    if (!isset($commands[$command])) {
        return s($question);
    }

    $style = 'color: ' . $commands[$command] . '; background-color: ' .
        $commands[$command] . '26; box-shadow: 0 0 0 2px ' .
        $commands[$command] . '26;';

    return s($matches[1]) .
        html_writer::span(s($matches[2]), 'ragassistant-command-mark', [
            'data-cmd' => $command,
            'style' => $style,
        ]) .
        s(core_text::substr($question, core_text::strlen($matches[0])));
}

/**
 * Normalize an illustration URL returned by the backend.
 *
 * The backend may return either an absolute signed URL or a path relative
 * to its configured base URL.
 *
 * @param mixed $value
 * @param string $baseurl
 * @return string|null
 */
function local_ragassistant_illustration_url($value, string $baseurl): ?string
{
    $url = trim((string) $value);
    if ($url === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    return rtrim($baseurl, '/') . '/' . ltrim($url, '/');
}

// Input handling and access control setup
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/ragassistant:useassistant', $context);
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$policy = \local_ragassistant\local\course_policy::get($courseid);
$enabled = (bool) get_config('local_ragassistant', 'enable_chat');
$allowed = $enabled && ($canmanage || (bool) $policy->allowstudentchat);

// Page setup and metadata

// Moodle only loads MathJax when a filtered string is rendered on the page. This page
// renders answers through markdown_renderer (and via AJAX), so make sure the filters
// (filter_mathjaxloader) are set up even when there is no chat history yet.
\filter_manager::instance()->setup_page_for_filters($PAGE, $context);
$PAGE->set_url(new moodle_url('/local/ragassistant/chat.php', ['courseid' => $courseid]));
$PAGE->requires->js(new moodle_url('/local/ragassistant/chat.js'));
$PAGE->requires->css(new moodle_url('/local/ragassistant/styles.css'));

// Set session history if available
if (!isset($SESSION->local_ragassistant_history)) {
    $SESSION->local_ragassistant_history = [];
}
if (!isset($SESSION->local_ragassistant_history[$courseid])) {
    $SESSION->local_ragassistant_history[$courseid] = [];
}

// Load suggestions only for a new chat. The joined document checks preserve
// course/personal visibility and exclude deleted, failed, or retrieval-disabled files.
$suggestions = [];
if ($allowed) {
    $available = array_values($DB->get_records_sql(
        "SELECT s.id, s.suggestion
           FROM {local_ragassistant_suggestions} s
           JOIN {local_ragassistant_documents} d ON d.id = s.documentid
          WHERE s.courseid = :courseid
            AND d.status = :ready
            AND d.retrievalenabled = 1
            AND (d.visibility = :coursevisibility OR d.userid = :userid OR :canmanage = 1)
          ORDER BY s.id ASC",
        [
            'courseid' => $courseid,
            'ready' => 'ready',
            'coursevisibility' => 'course',
            'userid' => $USER->id,
            'canmanage' => $canmanage ? 1 : 0,
        ]
    ));

    if ($available) {
        $lastids = $SESSION->local_ragassistant_suggestion_last[$courseid] ?? [];
        $fresh = array_values(array_filter(
            $available,
            static fn($item) => !in_array((int) $item->id, $lastids, true)
        ));
        shuffle($fresh);
        $suggestions = array_slice($fresh, 0, 3);

        // If fewer than three unseen suggestions remain, fill from the full pool.
        if (count($suggestions) < 3) {
            $selectedids = array_map(static fn($item) => (int) $item->id, $suggestions);
            $remaining = array_values(array_filter(
                $available,
                static fn($item) => !in_array((int) $item->id, $selectedids, true)
            ));
            shuffle($remaining);
            $suggestions = array_merge(
                $suggestions,
                array_slice($remaining, 0, 3 - count($suggestions))
            );
        }

        $SESSION->local_ragassistant_suggestion_last[$courseid] = array_map(
            static fn($item) => (int) $item->id,
            $suggestions
        );
    }
}

// Handle form submission and chat logic
$history =& $SESSION->local_ragassistant_history[$courseid];
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$error = '';
$question = '';
if (data_submitted() && confirm_sesskey()) {
    $question = trim(optional_param('question', '', PARAM_TEXT));
    $toolid = trim(optional_param('tool_id', '', PARAM_ALPHANUMEXT));
    $displayquestion = trim(optional_param('display_question', '', PARAM_TEXT));
    if ($displayquestion === '' && $toolid !== '') {
        $commandnames = [
            'exam_generation' => '/exam',
            'note_making' => '/notes',
            'summarization' => '/summarize',
            'flashcards' => '/flashcards',
            'concept_explanation' => '/explain',
            'practice_quiz' => '/quiz',
            'study_guide' => '/studyguide',
            'exact_text_extraction' => '/extract',
        ];
        $displayquestion = ($commandnames[$toolid] ?? '') .
            ($commandnames[$toolid] ?? '' ? ' ' : '') . $question;
    }
    if ($displayquestion === '') {
        $displayquestion = $question;
    }

    // Check if chat is allowed for the user
    if (!$allowed) {
        $error = $enabled ? get_string('studentchatdisabled', 'local_ragassistant') : get_string('chatdisabledreason', 'local_ragassistant');

        // Validate question length between 2 and 4000 characters
    } else if (core_text::strlen($question) < 2) {
        $error = get_string('questionrequired', 'local_ragassistant');
    } else if (core_text::strlen($question) > 4000) {
        $error = get_string('questiontoolong', 'local_ragassistant');
    } else {

        // Prepare conversation history for the API request
        try {

            // Retrieve the last 6 turns of conversation history to provide context for the API.
            $conversationhistory = [];
            foreach (array_slice($history, -6) as $turn) {
                $conversationhistory[] = [
                    'role' => 'user',
                    'content' => core_text::substr((string) $turn['question'], 0, 4000)
                ];

                $conversationhistory[] = [
                    'role' => 'assistant',
                    'content' => core_text::substr((string) $turn['answer'], 0, 4000)
                ];
            }

            // Moodle normally holds a write lock on the user's session for the
            // entire PHP request. Release it before waiting on the remote AI service
            // so other Moodle tabs and AJAX requests from this user remain responsive.
            $sessionclosed = false;
            try {
                \core\session\manager::write_close();
                $sessionclosed = true;

                $apiclient = new \local_ragassistant\local\api_client();
                // Configuration is synchronized only when its saved values change.
                $apiclient->sync_configuration_if_changed();

                // Never query an empty/new embedding collection while the automatic
                // rebuild is queued or running. Moodle cron clears this flag on completion.
                if (get_config('local_ragassistant', 'reindex_pending')) {
                    throw new \moodle_exception('reindexpending', 'local_ragassistant');
                }

                // Retrieve the answer from the RAG Assistant API.
                $response = $apiclient->post_json('/api/v1/chat', [
                    'question' => $question,
                    'course_id' => (int) $course->id,
                    'user_id' => (int) $USER->id,
                    'include_course_documents' => true,
                    'history' => $conversationhistory,
                    'tool_id' => $toolid !== '' ? $toolid : null,
                    'provider' => trim((string) get_config('local_ragassistant', 'llm_provider')) ?: 'openai',
                    'model' => trim((string) get_config('local_ragassistant', 'chat_model')) ?: null,
                    'temperature' => (float) get_config('local_ragassistant', 'temperature'),
                ]);
            } finally {
                if ($sessionclosed) {
                    // Re-open the same Moodle session briefly to persist the answer.
                    \core\session\manager::start();
                }
            }

            // Reacquire the session-backed history after write_close/start reloads it.
            $history =& $SESSION->local_ragassistant_history[$courseid];

            // Extract the raw Markdown answer from the API response.
            $answer = (string) ($response['answer'] ?? '');

            // Store the raw Markdown answer and sources in session history.
            $illustrationurl = local_ragassistant_illustration_url(
                $response['illustration_url'] ?? null,
                (new \local_ragassistant\local\api_client())->get_base_url()
            );

            $history[] = [
                'question' => $displayquestion,
                'answer' => $answer,
                'illustration_url' => $illustrationurl,
                'sources' => is_array($response['sources'] ?? null)
                    ? $response['sources']
                    : [],
                'time' => time(),
            ];

            // Limit the history to the last 20 turns to prevent excessive memory usage.
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }

            // Return JSON for AJAX request 
            if ($ajax) {
                header('Content-Type: application/json');

                echo json_encode([
                    'success' => true,
                    'question' => $question,
                    'display_question' => $displayquestion,
                    'answer_html' => markdown_renderer::render($answer, $context),
                    'illustration_url' => $illustrationurl,
                    'sources' => is_array($response['sources'] ?? null)
                        ? $response['sources']
                        : [],
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            //preserve the existing question in the input field for non-AJAX requests
            redirect(new moodle_url('/local/ragassistant/chat.php', ['courseid' => $courseid]));

        } catch (\Throwable $exception) {
            debugging(
                'RAG Assistant backend request failed: ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );

            $error = get_config('local_ragassistant', 'reindex_pending')
                ? get_string('reindexpending', 'local_ragassistant')
                : get_string('answerfailed', 'local_ragassistant');

            if ($ajax) {
                http_response_code(500);
                header('content-type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => $error,
                ]);
                exit;
            }
        }
    }
}

if ($ajax) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'error' => $error ?: 'Unable to process your question.',
    ]);

    exit;
}

// Render page content
echo $OUTPUT->header();
// Display message based on chat availability and any errors encountered during processing
if (!$allowed) {
    echo html_writer::div($enabled ? get_string('studentchatdisabled', 'local_ragassistant') : get_string('chatdisabledreason', 'local_ragassistant'), 'alert alert-warning');
}
if ($error !== '') {
    echo html_writer::div(s($error), 'alert alert-danger');
}

// Render chat element
echo html_writer::start_div(
    'ragassistant-chat-app' . (!empty($history) ? ' ragassistant-chat-active' : '')
);

//render chat background blobs
echo html_writer::start_div('ragassistant-chat-background');
echo html_writer::div('', 'ragassistant-chat-blob blob-1');
echo html_writer::div('', 'ragassistant-chat-blob blob-2');
echo html_writer::div('', 'ragassistant-chat-blob blob-3');
echo html_writer::end_div();

echo html_writer::start_div('ragassistant-chat-transcript', [
    'id' => 'ragassistant-chat-transcript',
    'role' => 'log',
    'aria-live' => 'polite',
]);

if (empty($history)) {
    
} else {
    // Render each turn of the conversation history
    foreach ($history as $turn) {
        echo html_writer::div(
            html_writer::div(
                local_ragassistant_render_question((string) $turn['question']),
                'ragassistant-message-content'
            ),
            'ragassistant-message ragassistant-message-user'
        );
        echo html_writer::start_div('ragassistant-message ragassistant-message-assistant');
        echo html_writer::tag('div', '✦', ['class' => 'ragassistant-assistant-avatar']);
        echo html_writer::start_div('ragassistant-message-content');
        echo markdown_renderer::render((string) $turn['answer'], $context);
        if (!empty($turn['illustration_url'])) {
            echo html_writer::div(
                html_writer::empty_tag('img', [
                    'src' => $turn['illustration_url'],
                    'alt' => 'Educational illustration for this explanation',
                    'class' => 'ragassistant-explain-illustration',
                    'loading' => 'lazy',
                ]),
                'ragassistant-illustration-wrap'
            );
        }

        // Display sources of cite if available
        if (!empty($turn['sources'])) {
            echo html_writer::tag('details', html_writer::tag('summary', get_string('sourcesheading', 'local_ragassistant')) . html_writer::start_tag('ul', ['class' => 'ragassistant-source-list']) . implode('', array_map(function ($source) {
                $label = (string) ($source['filename'] ?? get_string('unknown', 'local_ragassistant'));
                if (!empty($source['page'])) {
                    $label .= ' · ' . get_string('sourcepage', 'local_ragassistant') . ' ' . (int) $source['page'];
                }
                $excerpt = trim((string) ($source['excerpt'] ?? ''));
                return html_writer::tag('li', html_writer::tag('strong', s($label)) . ($excerpt !== '' ? html_writer::tag('p', s($excerpt)) : ''));
            }, $turn['sources'])) . html_writer::end_tag('ul'), ['class' => 'ragassistant-sources-details']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}
echo html_writer::end_div();

// Show up to three stored document suggestions on a new conversation.
if ($allowed && empty($history) && !empty($suggestions)) {
    echo html_writer::start_div('ragassistant-suggestions', [
        'aria-label' => 'Suggested questions based on course documents',
    ]);
    echo html_writer::tag('h3', 'Try asking', ['class' => 'ragassistant-suggestions-title']);
    echo html_writer::tag(
        'p',
        'Explore topics from your available course materials.',
        ['class' => 'ragassistant-suggestions-description']
    );
    echo html_writer::start_div('ragassistant-suggestions-grid');
    foreach ($suggestions as $suggestion) {
        echo html_writer::tag(
            'button',
            html_writer::tag('span', s($suggestion->suggestion), ['class' => 'ragassistant-suggestion-text']) .
            html_writer::tag('span', '↗', ['class' => 'ragassistant-suggestion-arrow', 'aria-hidden' => 'true']),
            [
                'type' => 'button',
                'class' => 'ragassistant-suggestion-card',
                'data-prompt' => $suggestion->suggestion,
            ]
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Render chat input if user is allowed to use the assistant
if ($allowed) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/ragassistant/chat.php', ['courseid' => $courseid]))->out(false), 'class' => 'ragassistant-chat-composer']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tool_id', 'id' => 'ragassistant-tool-id', 'value' => '']);
    echo html_writer::tag('label', get_string('yourquestion', 'local_ragassistant'), ['for' => 'ragassistant-question', 'class' => 'sr-only']);
    echo html_writer::start_div('ragassistant-composer-input-wrap');
    echo html_writer::tag('div', '', ['id' => 'ragassistant-command-menu', 'class' => 'ragassistant-command-menu', 'role' => 'listbox', 'aria-label' => 'Available assistant commands', 'hidden' => 'hidden']);
    echo html_writer::tag('div', s($question), [
        'id' => 'ragassistant-question',
        'class' => 'form-control ragassistant-chat-input',
        'contenteditable' => 'plaintext-only',
        'role' => 'textbox',
        'aria-multiline' => 'true',
        'aria-labelledby' => 'ragassistant-question-label',
        'aria-autocomplete' => 'list',
        'aria-controls' => 'ragassistant-command-menu',
        'data-placeholder' => get_string('questionplaceholder', 'local_ragassistant'),
        'data-empty' => $question === '' ? 'true' : 'false',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'question', 'id' => 'ragassistant-question-value', 'value' => $question]);

    echo html_writer::end_div();

    $sendicon = $OUTPUT->pix_icon(
        'send',
        "",
        'local_ragassistant',
        ['class' => 'ragassistant-send-icon']
    );
    echo html_writer::tag('button', $sendicon ,[
        'type'  => 'submit',
        'class' => 'btn btn-primary ragassistant-send-button',
        'aria-label' => 'Send question',
    ]);
    echo html_writer::tag('small', get_string('chatinputhint', 'local_ragassistant'), ['class' => 'text-muted ragassistant-chat-hint']);
    echo html_writer::end_tag('form');
}
echo html_writer::end_div();
// back to dashboard link
echo html_writer::div(html_writer::link(new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]), get_string('backtodashboard', 'local_ragassistant')), 'mt-3');
echo $OUTPUT->footer();