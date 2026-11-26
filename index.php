<?php
// Main page: the course chat, with the course materials shortcut for educators.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/api_client.php');
require_once($CFG->dirroot . '/local/ragassistant/classes/local/course_policy.php');
use local_ragassistant\local\course_policy;
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
        '/exam' => '#270089',
        '/notes' => '#270089',
        '/summarize' => '#270089',
        '/flashcards' => '#270089',
        '/explain' => '#270089',
        '/quiz' => '#270089',
        '/studyguide' => '#270089',
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

/**
 * Validate the backend's optional "download" field (a study sheet PDF is ready).
 *
 * Only the expected keys are passed on, and file_id must be 32 lowercase hex characters.
 * The file is fetched later through download_sheet.php, never from the backend directly.
 *
 * @param mixed $value
 * @return array|null
 */
function local_ragassistant_clean_download($value): ?array
{
    if (!is_array($value) || !is_string($value['file_id'] ?? null) ||
            !preg_match('/^[0-9a-f]{32}$/', $value['file_id'])) {
        return null;
    }

    return [
        'file_id' => $value['file_id'],
        'filename' => clean_filename((string) ($value['filename'] ?? 'study_sheet.pdf')),
        'expires_at' => (int) ($value['expires_at'] ?? 0),
        'ttl_minutes' => (int) ($value['ttl_minutes'] ?? 0),
        'pages' => (int) ($value['pages'] ?? 1),
    ];
}

/**
 * Keep the valid entries of the backend's optional "images" list (document figures).
 *
 * The backend's "path" is dropped: figures are fetched through document_image.php, which
 * builds the backend URL itself. Caption, filename and page are document text, so they stay
 * plain strings here and chat.js only ever sets them with textContent.
 *
 * @param mixed $value
 * @return array List of ['marker', 'image_id', 'document_id', 'filename', 'page', 'caption', 'placed'].
 */
function local_ragassistant_clean_images($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $images = [];
    $markers = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $marker = (string) ($item['marker'] ?? '');
        $imageid = (string) ($item['image_id'] ?? '');
        $documentid = (string) ($item['document_id'] ?? '');
        if (!preg_match('/^IMG\d{1,3}$/', $marker) || isset($markers[$marker]) ||
                !preg_match('/^[0-9a-f]{32}$/', $imageid) || !preg_match('/^[1-9]\d{0,9}$/', $documentid)) {
            continue;
        }
        $markers[$marker] = true;
        $page = $item['page'] ?? null;
        $images[] = [
            'marker' => $marker,
            'image_id' => $imageid,
            'document_id' => $documentid,
            'filename' => core_text::substr(trim((string) ($item['filename'] ?? '')), 0, 255),
            'page' => is_numeric($page) && (int) $page > 0 ? (int) $page : null,
            'caption' => core_text::substr(trim((string) ($item['caption'] ?? '')), 0, 500),
            'placed' => !empty($item['placed']),
        ];
        if (count($images) >= 20) {
            break;
        }
    }
    return $images;
}

/**
 * Render an answer, with each figure marker that stands on its own line made into its own
 * paragraph so chat.js can put the figure there.
 *
 * The backend puts "[IMGn]" on its own line, but Markdown joins single lines into one
 * paragraph. Only markers of listed images are touched, so answers without figures are
 * rendered exactly as before.
 *
 * @param string $answer Raw Markdown answer.
 * @param array $images Cleaned images (local_ragassistant_clean_images()).
 * @param context $context Course context.
 * @return string HTML.
 */
function local_ragassistant_render_answer(string $answer, array $images, context $context): string
{
    if ($images) {
        $markers = array_flip(array_column($images, 'marker'));
        $answer = preg_replace_callback(
            '/^([ \t]*)\[(IMG\d{1,3})\][ \t]*$/m',
            static fn($m) => isset($markers[$m[2]]) ? "\n" . $m[1] . '[' . $m[2] . "]\n" : $m[0],
            $answer
        );
    }
    return markdown_renderer::render($answer, $context);
}

/**
 * How a message without a selected tool would reach the backend's /exam tool, if at all.
 *
 * The backend picks a tool from the text when Moodle sends no tool_id: a leading "/exam",
 * or a request such as "create mock exam questions on ...". This mirrors that routing
 * (Z:\app\prompt_router.py, route_message() and _PATTERN_ROUTES), so a course that turned
 * /exam off for students can stop both. Keep the pattern in step with the backend's.
 *
 * @param string $question The message as typed.
 * @return string 'command' for a leading /exam, 'pattern' for an exam request in words, or ''.
 */
function local_ragassistant_exam_route(string $question): string
{
    $text = core_text::strtolower(trim($question));
    $first = preg_split('/\s+/', $text, 2)[0] ?? '';
    if ($first === '/exam') {
        return 'command';
    }
    if (preg_match('/\b(?:create|generate|make|write|prepare|design)\b.{0,70}' .
            '\b(?:exam questions?|examination questions?|mock exam|exam paper|' .
            'mcqs?|multiple.choice questions?|test questions?)\b/u', $text)) {
        return 'pattern';
    }
    return '';
}

/**
 * Read a typed reply to the "search more widely?" question as approve or decline.
 *
 * Only short, clear answers count; anything longer or unclear returns null and is sent
 * as an ordinary chat message.
 *
 * @param string $text The student's message.
 * @return string|null 'approve', 'decline' or null.
 */
function local_ragassistant_typed_decision(string $text): ?string
{
    $text = core_text::strtolower(trim(preg_replace('/[\s.!,]+/u', ' ', $text)));
    $approve = ['y', 'yes', 'yes please', 'yeah', 'yep', 'sure', 'ok', 'okay', 'go ahead',
        'please do', 'do it', 'yes search more widely', 'search more widely'];
    $decline = ['n', 'no', 'no thanks', 'no thank you', 'nope', 'cancel', 'no cancel', 'stop',
        'never mind', 'nevermind'];
    if (in_array($text, $approve, true)) {
        return 'approve';
    }
    if (in_array($text, $decline, true)) {
        return 'decline';
    }
    return null;
}

/**
 * Validate the backend's optional "pending_action" field (approve/decline question).
 *
 * The token is opaque and signed by the backend; it is passed through unchanged and
 * only its type and length are checked.
 *
 * @param mixed $value
 * @return array|null
 */
function local_ragassistant_clean_pending_action($value): ?array
{
    // expand_notes_search: widen a thin /notes search. expand_quiz_scope: broaden a /quiz
    // topic that is too narrow for a full quiz. Both are answered the same way.
    $types = ['expand_notes_search', 'expand_quiz_scope'];
    if (!is_array($value) || !in_array($value['type'] ?? '', $types, true) ||
            !is_string($value['token'] ?? null) || $value['token'] === '' ||
            strlen($value['token']) > 4096) {
        return null;
    }

    return [
        'type' => (string) $value['type'],
        'token' => $value['token'],
        'expires_at' => (int) ($value['expires_at'] ?? 0),
        'approve_label' => clean_param((string) ($value['approve_label'] ?? ''), PARAM_TEXT),
        'decline_label' => clean_param((string) ($value['decline_label'] ?? ''), PARAM_TEXT),
    ];
}

/**
 * Validate the backend's optional "quiz" field (a /quiz multiple-choice practice quiz).
 *
 * All or nothing: if any question is malformed the whole quiz is dropped and only the
 * answer text is shown. Every string is plain text; chat.js sets it with textContent.
 *
 * @param mixed $value
 * @return array|null ['title', 'questions' => [['question', 'options', 'answer_index',
 *     'explanation', 'source']]]
 */
function local_ragassistant_clean_quiz($value): ?array
{
    if (!is_array($value) || !is_array($value['questions'] ?? null)) {
        return null;
    }
    $count = count($value['questions']);
    if ($count < 1 || $count > 10) {
        return null;
    }

    $text = function ($raw, int $max): string {
        $clean = trim(clean_param(is_scalar($raw) ? (string) $raw : '', PARAM_TEXT));
        return core_text::strlen($clean) > $max ? '' : $clean;
    };

    $questions = [];
    foreach (array_values($value['questions']) as $item) {
        if (!is_array($item) || !is_array($item['options'] ?? null) || count($item['options']) !== 4) {
            return null;
        }
        $question = $text($item['question'] ?? '', 600);
        $options = array_map(function ($option) use ($text) {
            return $text($option, 300);
        }, array_values($item['options']));
        $answerindex = $item['answer_index'] ?? null;
        if ($question === '' || in_array('', $options, true) ||
                !is_int($answerindex) || $answerindex < 0 || $answerindex > 3) {
            return null;
        }

        $source = '';
        if (is_array($item['source'] ?? null)) {
            $source = $text($item['source']['filename'] ?? '', 255);
            if ($source !== '' && !empty($item['source']['page'])) {
                $source .= ' · ' . get_string('sourcepage', 'local_ragassistant') . ' ' .
                    (int) $item['source']['page'];
            }
        }

        $questions[] = [
            'question' => $question,
            'options' => $options,
            'answer_index' => $answerindex,
            'explanation' => $text($item['explanation'] ?? '', 800),
            'source' => $source,
        ];
    }

    $clean = [
        'title' => $text($value['title'] ?? '', 160),
        'questions' => $questions,
    ];
    // Moodle's own id (never the backend's), used to save the attempt's progress.
    if (\local_ragassistant\local\quiz_progress::valid_id($value['id'] ?? null)) {
        $clean['id'] = $value['id'];
    }
    return $clean;
}

/**
 * Validate the backend's optional "flashcards" field (a /flashcards revision deck).
 *
 * All or nothing, like the quiz: if any card is malformed the whole deck is dropped and only
 * the answer text is shown. Every string is plain text; chat.js sets it with textContent.
 *
 * @param mixed $value
 * @return array|null ['title', 'cards' => [['front', 'back', 'source']]]
 */
function local_ragassistant_clean_flashcards($value): ?array
{
    if (!is_array($value) || !is_array($value['cards'] ?? null)) {
        return null;
    }
    // The backend sends 3-15 cards (5 by default); a little headroom is allowed here.
    $count = count($value['cards']);
    if ($count < 1 || $count > 20) {
        return null;
    }

    $text = function ($raw, int $max): string {
        $clean = trim(clean_param(is_scalar($raw) ? (string) $raw : '', PARAM_TEXT));
        return core_text::strlen($clean) > $max ? '' : $clean;
    };

    $cards = [];
    foreach (array_values($value['cards']) as $item) {
        if (!is_array($item)) {
            return null;
        }
        $front = $text($item['front'] ?? '', 300);
        $back = $text($item['back'] ?? '', 800);
        if ($front === '' || $back === '') {
            return null;
        }

        $source = '';
        if (is_array($item['source'] ?? null)) {
            $source = $text($item['source']['filename'] ?? '', 255);
            if ($source !== '' && !empty($item['source']['page'])) {
                $source .= ' · ' . get_string('sourcepage', 'local_ragassistant') . ' ' .
                    (int) $item['source']['page'];
            }
        } else if (is_string($item['source'] ?? null)) {
            // Already formatted (a deck saved in the session history).
            $source = $text($item['source'], 300);
        }

        $cards[] = ['front' => $front, 'back' => $back, 'source' => $source];
    }

    return [
        'title' => $text($value['title'] ?? '', 160),
        'cards' => $cards,
    ];
}

// Input handling and access control setup.
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$policy = course_policy::get($courseid);

// Check user capabilities before allowing page access.
$canmanage = has_capability('local/ragassistant:managedocuments', $context);
$canusechat = has_capability('local/ragassistant:useassistant', $context);

// Allow users who can manage documents OR use the assistant.
if (!$canmanage && !$canusechat) {
    require_capability('local/ragassistant:useassistant', $context);
}

// Managers can use chat regardless of the student policy, provided they also
// have the useassistant capability. Chat must also be enabled site-wide.
$enabled = (bool) get_config('local_ragassistant', 'enable_chat');
$allowed = course_policy::can_use_chat($courseid, $context);
// The course can turn /exam off for students, with or without past exam papers.
$examallowed = $allowed && course_policy::can_use_exam($courseid, $context);

$pageurl = new moodle_url('/local/ragassistant/index.php', ['courseid' => $courseid]);

// Page setup and metadata.

// Moodle only loads MathJax when a filtered string is rendered on the page. This page
// renders answers through markdown_renderer (and via AJAX), so make sure the filters
// (filter_mathjaxloader) are set up even when there is no chat history yet.
\filter_manager::instance()->setup_page_for_filters($PAGE, $context);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('pluginname', 'local_ragassistant'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/ragassistant/chat.js'));
$PAGE->requires->strings_for_js([
    'downloadpdf', 'downloadpdfaria', 'downloadavailableuntil', 'downloadbusy',
    'downloadexpired', 'downloadfailed',
    'figureunavailable', 'figuresourcepage', 'figurealt', 'figureopen',
    'quizaria', 'quizquestionof', 'quizscoresofar', 'quizfinished', 'quizoptions', 'quizcheck',
    'quiznext', 'quizseescore', 'quizcorrect', 'quizincorrect', 'quizsource', 'quizscorearia',
    'quizverdictexcellent', 'quizverdictgood', 'quizverdictclose', 'quizverdictpractise',
    'quizpercent', 'quizretry',
    'charactercount',
    'flashcardsaria', 'flashcardcount', 'flashcardquestion', 'flashcardanswer', 'flashcardfliphint',
    'flashcardfliphinttouch', 'flashcardprevious', 'flashcardnext', 'flashcardrestart',
    'flashcardsource', 'flashcardanswershown', 'flashcardquestionshown',
    'flashcardarialabelquestion', 'flashcardarialabelanswer',
], 'local_ragassistant');
$PAGE->requires->css(new moodle_url('/local/ragassistant/styles.css'));

// Set session history if available.
if (!isset($SESSION->local_ragassistant_history)) {
    $SESSION->local_ragassistant_history = [];
}
if (!isset($SESSION->local_ragassistant_history[$courseid])) {
    $SESSION->local_ragassistant_history[$courseid] = [];
}

// Clear the current course conversation and return to a new chat.
if ($canusechat && data_submitted() && optional_param('clear_history', 0, PARAM_BOOL) && confirm_sesskey()) {
    $SESSION->local_ragassistant_history[$courseid] = [];

    redirect($pageurl);
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
            AND s.status = :suggestionready
            AND d.status = :ready
            AND d.retrievalenabled = 1
            AND d.documentrole = :courserole
            AND (d.visibility = :coursevisibility OR d.userid = :userid OR :canmanage = 1)
          ORDER BY s.id ASC",
        [
            'courseid' => $courseid,
            'suggestionready' => 'ready',
            'ready' => 'ready',
            // Never from a past exam paper: students must not see its questions.
            'courserole' => \local_ragassistant\local\past_exam::COURSE_MATERIAL,
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

// Handle form submission and chat logic.
$history =& $SESSION->local_ragassistant_history[$courseid];
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$error = '';
$question = '';
if (data_submitted() && confirm_sesskey()) {
    $question = trim(optional_param('question', '', PARAM_TEXT));
    $toolid = trim(optional_param('tool_id', '', PARAM_ALPHANUMEXT));
    $displayquestion = trim(optional_param('display_question', '', PARAM_TEXT));
    $commandnames = [
        'exam_generation' => '/exam',
        'note_making' => '/notes',
        'summarization' => '/summarize',
        'flashcards' => '/flashcards',
        'concept_explanation' => '/explain',
        'practice_quiz' => '/quiz',
        'study_guide' => '/studyguide',
    ];
    // Only forward tools that have a slash command here, so a hand-made request
    // cannot reach a backend tool that was removed from the menu (e.g. /extract).
    if (!isset($commandnames[$toolid])) {
        $toolid = '';
    }
    if ($displayquestion === '' && $toolid !== '') {
        $displayquestion = ($commandnames[$toolid] ?? '') .
            ($commandnames[$toolid] ?? '' ? ' ' : '') . $question;
    }
    if ($displayquestion === '') {
        $displayquestion = $question;
    }

    // Typed answer to a backend "widen the /notes search?" question. The question's token
    // is kept in the session (never sent to the browser). The student's next message
    // answers it when it is a clear yes or no; anything else is an ordinary message and
    // drops the question. Either way the question can only be answered once.
    $pendingtoken = '';
    $pendingdecision = local_ragassistant_typed_decision($question);
    $savedpending = $SESSION->local_ragassistant_pending[$courseid] ?? null;
    unset($SESSION->local_ragassistant_pending[$courseid]);
    if (is_array($savedpending) && $pendingdecision !== null && $toolid === '') {
        $pendingtoken = (string) ($savedpending['token'] ?? '');
    }
    $haspendingdecision = $pendingtoken !== '' && strlen($pendingtoken) <= 4096;

    // Check if chat is allowed for the user
    if (!$allowed) {
        $error = $enabled ? get_string('studentchatdisabled', 'local_ragassistant') : get_string('chatdisabledreason', 'local_ragassistant');

        // /exam is turned off for students in this course: refuse the menu tool and a typed /exam.
    } else if (!$examallowed &&
            ($toolid === 'exam_generation' || local_ragassistant_exam_route($question) === 'command')) {
        $error = get_string('examdisabled', 'local_ragassistant');

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

                // Document reindexing runs independently in the background.
                // Do not block site-wide chat while individual documents are rebuilt.

                // Retrieve the answer from the RAG Assistant API.
                $provider = trim((string) get_config('local_ragassistant', 'llm_provider'));
                // With /exam turned off, a request in words ("make mock exam questions...")
                // is pinned to the ordinary chat tool, so the backend does not route it to
                // /exam itself. The student gets a normal answer instead of a practice paper.
                $sendtoolid = $toolid;
                if (!$examallowed && $sendtoolid === '' && !$haspendingdecision &&
                        local_ragassistant_exam_route($question) === 'pattern') {
                    $sendtoolid = 'ask';
                }
                $chatrequest = [
                    'question' => $question,
                    'course_id' => (int) $course->id,
                    'user_id' => (int) $USER->id,
                    'include_course_documents' => true,
                    'history' => $conversationhistory,
                    'tool_id' => $sendtoolid !== '' ? $sendtoolid : null,
                    'provider' => $provider,
                    'model' => trim((string) get_config('local_ragassistant', 'chat_model')),
                ];
                // The backend switches to the local Ollama model only when the request names
                // it (both fields, or neither). Not needed when chat already uses Ollama.
                $fallbackmodel = trim((string) get_config('local_ragassistant', 'fallback_model'));
                if ($fallbackmodel !== '' && $provider !== 'ollama') {
                    $chatrequest['fallback_provider'] = 'ollama';
                    $chatrequest['fallback_model'] = $fallbackmodel;
                }
                // The backend requires both fields or neither (422 otherwise).
                if ($haspendingdecision) {
                    $chatrequest['pending_action_token'] = $pendingtoken;
                    $chatrequest['pending_decision'] = $pendingdecision;
                }
                $response = $apiclient->post_json('/api/v1/chat', $chatrequest);
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

            // Optional /notes fields; both are absent for every other command. A /exam reply
            // in a course with past exam papers has the same "download" (a practice paper PDF).
            $download = local_ragassistant_clean_download($response['download'] ?? null);
            $pendingaction = local_ragassistant_clean_pending_action($response['pending_action'] ?? null);
            // Optional figures from course documents; absent for most answers.
            $images = local_ragassistant_clean_images($response['images'] ?? null);
            // Optional /quiz questions; absent for every other command.
            $quiz = is_array($response['quiz'] ?? null)
                ? local_ragassistant_clean_quiz(['id' => random_string(16)] + $response['quiz'])
                : null;
            // Optional /flashcards deck; absent for every other command.
            $flashcards = local_ragassistant_clean_flashcards($response['flashcards'] ?? null);
            // A quiz or flashcard reply shows no Sources list: each question or card names its own.
            $sources = !$quiz && !$flashcards && is_array($response['sources'] ?? null)
                ? $response['sources'] : [];

            // Remember a new "search more widely?" question so the student's next typed
            // yes/no can answer it. Only the token and expiry are kept, in the session.
            if ($pendingaction) {
                $SESSION->local_ragassistant_pending[$courseid] = [
                    'token' => $pendingaction['token'],
                    'expires_at' => $pendingaction['expires_at'],
                ];
            }

            $history[] = [
                'question' => $displayquestion,
                'answer' => $answer,
                'illustration_url' => $illustrationurl,
                // Kept so the Download PDF button is shown again after a reload. The
                // approve/decline question is not kept: it is only answerable once.
                'download' => $download,
                // Kept so figures are shown again after a reload (fetched again then).
                'images' => $images,
                // Kept so the quiz card is rebuilt after a reload (it starts again at question 1).
                'quiz' => $quiz,
                // Kept so the deck is rebuilt after a reload (it reopens on card 1).
                'flashcards' => $flashcards,
                'sources' => $sources,
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
                    'answer_html' => local_ragassistant_render_answer($answer, $images, $context),
                    'illustration_url' => $illustrationurl,
                    'images' => $images,
                    'sources' => $sources,
                    'download' => $download,
                    'quiz' => $quiz,
                    'flashcards' => $flashcards,
                ],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            //preserve the existing question in the input field for non-AJAX requests
            redirect($pageurl);

        } catch (\Throwable $exception) {
            debugging(
                'RAG Assistant backend request failed: ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );

            $error = get_string('answerfailed', 'local_ragassistant');

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

// Render page content.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('dashboardheading', 'local_ragassistant'));

// Course materials shortcut: only for educators who manage documents. Students have
// nothing to upload, so they do not see it at all.
if ($canmanage) {
    echo html_writer::div(
        html_writer::div(
            html_writer::tag('h3', get_string('uploadCardTitle', 'local_ragassistant')) .
            html_writer::tag('p', get_string('uploadCardDesc', 'local_ragassistant')),
            'ragassistant-manage-card-text'
        ) .
        html_writer::link(
            new moodle_url('/local/ragassistant/documents.php', ['courseid' => $courseid]),
            get_string('uploadButton', 'local_ragassistant'),
            ['class' => 'btn btn-primary']
        ),
        'ragassistant-card ragassistant-manage-card'
    );
}

// Display message based on chat availability and any errors encountered during processing.
if (!$allowed) {
    if (!$enabled) {
        $reason = get_string('chatdisabledreason', 'local_ragassistant');
    } else if (!$canusechat) {
        $reason = get_string('chatnopermission', 'local_ragassistant');
    } else {
        $reason = get_string('studentchatdisabled', 'local_ragassistant');
    }
    echo html_writer::div($reason, 'alert alert-warning');
}
if ($error !== '') {
    echo html_writer::div(s($error), 'alert alert-danger');
}

// Users without the assistant capability (educators who only manage documents)
// see the course materials shortcut but no chat window.
if ($canusechat) {
    // Render chat element
    echo html_writer::start_div(
        'ragassistant-chat-app' . (!empty($history) ? ' ragassistant-chat-active' : ''),
        [
            // Study sheet PDFs are fetched through this Moodle proxy, never from the backend.
            'data-download-url' => (new moodle_url('/local/ragassistant/download_sheet.php', [
                'courseid' => $courseid,
            ]))->out(false),
            'data-pdf-icon' => $OUTPUT->image_url('f/pdf')->out(false),
            // Document figures are loaded through this Moodle proxy, never from the backend.
            'data-figure-url' => (new moodle_url('/local/ragassistant/document_image.php', [
                'courseid' => $courseid,
            ]))->out(false),
            // Quiz progress is saved in the chat session history so a reload keeps it.
            'data-quiz-progress-url' => (new moodle_url('/local/ragassistant/quiz_progress.php', [
                'courseid' => $courseid,
            ]))->out(false),
            // Commands chat.js leaves out of the menu. The server refuses them as well.
            'data-disabled-commands' => $examallowed ? '' : 'exam_generation',
        ]
    );

    // Display the new chat button only when conversation history exists.
    if (!empty($history)) {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $pageurl->out(false),
            'class' => 'ragassistant-new-chat-form',
        ]);

        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'clear_history',
            'value' => '1',
        ]);

        echo html_writer::tag('button',
            html_writer::tag('span', '+', ['aria-hidden' => 'true']) . ' New chat',
            [
                'type' => 'submit',
                'class' => 'ragassistant-new-chat-btn',
                'title' => 'Start a new conversation',
            ]
        );

        echo html_writer::end_tag('form');
    }

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
        // chat.js labels new answers with the same name as the history below.
        'data-assistant-name' => get_string('assistantname', 'local_ragassistant'),
    ]);

    // Render each turn of the conversation history
    foreach ($history as $turnindex => $turn) {
        echo html_writer::div(
            html_writer::div(
                local_ragassistant_render_question((string) $turn['question']),
                'ragassistant-message-content'
            ),
            'ragassistant-message ragassistant-message-user'
        );
        // A saved study sheet download is re-rendered by chat.js (expired state included).
        $savedownload = local_ragassistant_clean_download($turn['download'] ?? null);
        // Saved figures are placed by chat.js too, the same way as for a new answer.
        $savedimages = local_ragassistant_clean_images($turn['images'] ?? null);
        // A saved quiz is rebuilt by chat.js where the student left it.
        $savedquiz = local_ragassistant_clean_quiz($turn['quiz'] ?? null);
        // A quiz saved before quiz ids existed gets one now (kept in the session), so its
        // progress can be saved from here on.
        if ($savedquiz && empty($savedquiz['id'])) {
            $savedquiz['id'] = random_string(16);
            $history[$turnindex]['quiz']['id'] = $savedquiz['id'];
        }
        $messageattributes = [];
        if ($savedquiz) {
            $messageattributes['data-quiz'] = json_encode($savedquiz,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $savedprogress = \local_ragassistant\local\quiz_progress::clean(
                $turn['quiz_progress'] ?? null, count($savedquiz['questions']));
            if ($savedprogress) {
                $messageattributes['data-quiz-progress'] = json_encode($savedprogress);
            }
        }
        // A saved flashcard deck is rebuilt by chat.js (on card 1).
        $savedflashcards = local_ragassistant_clean_flashcards($turn['flashcards'] ?? null);
        if ($savedflashcards) {
            $messageattributes['data-flashcards'] = json_encode($savedflashcards,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($savedownload) {
            $messageattributes['data-download'] = json_encode($savedownload);
        }
        if ($savedimages) {
            $messageattributes['data-images'] = json_encode($savedimages,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        echo html_writer::start_div(
            'ragassistant-message ragassistant-message-assistant',
            $messageattributes
        );
        // Header: decorative avatar and the assistant's name, above the answer.
        echo html_writer::div(
            html_writer::tag('div', '✦', ['class' => 'ragassistant-assistant-avatar', 'aria-hidden' => 'true']) .
                html_writer::div(get_string('assistantname', 'local_ragassistant'), 'ragassistant-assistant-name'),
            'ragassistant-assistant-header'
        );
        echo html_writer::start_div('ragassistant-message-content');
        echo local_ragassistant_render_answer((string) $turn['answer'], $savedimages, $context);
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
        // Quiz replies show no Sources list (also for quizzes saved before that rule).
        if (!empty($turn['sources']) && !$savedquiz && !$savedflashcards) {
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
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'ragassistant-chat-composer']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tool_id', 'id' => 'ragassistant-tool-id', 'value' => '']);
        echo html_writer::tag('label', get_string('yourquestion', 'local_ragassistant'), ['id' => 'ragassistant-question-label', 'class' => 'sr-only']);
        // One field: the question box with a toolbar (study tools, character count, send) under it.
        echo html_writer::start_div('ragassistant-composer-input-wrap ragassistant-composer-field');
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

        echo html_writer::start_div('ragassistant-composer-toolbar');
        // Opens the slash-command menu, so the study tools can be found without knowing "/".
        echo html_writer::tag('button',
            html_writer::tag('span', '/', ['class' => 'ragassistant-tools-slash', 'aria-hidden' => 'true']) .
                get_string('studytools', 'local_ragassistant'),
            [
                'type' => 'button',
                'id' => 'ragassistant-tools-button',
                'class' => 'ragassistant-tools-button',
                'aria-haspopup' => 'listbox',
                'aria-controls' => 'ragassistant-command-menu',
                'aria-expanded' => 'false',
            ]
        );
        echo html_writer::start_div('ragassistant-composer-actions');
        // Shown by chat.js only near the 4000 character limit.
        echo html_writer::tag('span', '', [
            'id' => 'ragassistant-char-count',
            'class' => 'ragassistant-char-count',
            'hidden' => 'hidden',
        ]);
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
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::end_div();

        echo html_writer::tag('small', get_string('chatinputhint', 'local_ragassistant'), ['class' => 'text-muted ragassistant-chat-hint']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
