<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Site administration settings.
 *
 * @package    local_ragassistant
 * @copyright  2026 Zarith
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/admin_setting_configuration_health.php');
require_once(__DIR__ . '/classes/admin_setting_configencryptedpassword.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ragassistant_settings',
        get_string('pluginname', 'local_ragassistant')
    );

    $settings->add(new admin_setting_heading(
        'local_ragassistant/heading',
        get_string('settingsheading', 'local_ragassistant'),
        get_string('settingsintro', 'local_ragassistant')
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/api_base_url',
        get_string('apibaseurl', 'local_ragassistant'),
        get_string('apibaseurl_desc', 'local_ragassistant'),
        'http://127.0.0.1:8000',
        PARAM_URL
    ));

    // This setting stores AES-256-GCM ciphertext in Moodle's config_plugins table.
    // The encryption key itself must be configured outside the database.
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/api_key',
        get_string('apikey', 'local_ragassistant'),
        get_string('apikey_desc', 'local_ragassistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ragassistant/enable_chat',
        get_string('enablechat', 'local_ragassistant'),
        get_string('enablechat_desc', 'local_ragassistant'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_ragassistant/llm_heading',
        get_string('llm_heading', 'local_ragassistant'),
        get_string('llm_heading_desc', 'local_ragassistant')
    ));

    // Chat provider/model and provider credentials are managed by site administrators.
    // Provider keys are encrypted in Moodle and sent server-to-server to FastAPI only
    // when the saved settings change; they are never rendered back into the browser.
    $settings->add(new admin_setting_configselect(
        'local_ragassistant/llm_provider',
        get_string('llmprovider', 'local_ragassistant'),
        get_string('llmprovider_desc', 'local_ragassistant'),
        'openai',
        [
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'ollama' => 'Ollama',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/chat_model',
        get_string('llm_model', 'local_ragassistant'),
        get_string('llm_model_desc', 'local_ragassistant'),
        '',
        PARAM_TEXT
    ));

    // One credential field follows the provider selector. The setting class
    // stores the submitted key against the selected provider while retaining
    // previously saved credentials for the other provider.
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/provider_api_key',
        get_string('provider_api_key', 'local_ragassistant'),
        get_string('provider_api_key_desc', 'local_ragassistant'),
        ''
    ));

    // Image generation has an independent provider and model.
    $settings->add(new admin_setting_heading(
        'local_ragassistant/image_heading',
        get_string('image_heading', 'local_ragassistant'),
        get_string('image_heading_desc', 'local_ragassistant')
    ));

    $settings->add(new admin_setting_configselect(
        'local_ragassistant/image_provider',
        get_string('image_provider', 'local_ragassistant'),
        get_string('image_provider_desc', 'local_ragassistant'),
        'openai',
        [
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/image_model',
        get_string('image_model', 'local_ragassistant'),
        get_string('image_model_desc', 'local_ragassistant'),
        'gpt-image-1',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ragassistant/reuse_chat_api_key',
        get_string('reuse_chat_api_key', 'local_ragassistant'),
        get_string('reuse_chat_api_key_desc', 'local_ragassistant'),
        0
    ));

    // Separate encrypted credential used only when the image key is not reused.
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/image_provider_api_key',
        get_string('image_provider_api_key', 'local_ragassistant'),
        get_string('image_provider_api_key_desc', 'local_ragassistant'),
        ''
    ));

    // Text embeddings are used for document indexing and retrieval, so this section stays
    // visible when AI chat is off. "Use the server setting" (the default) sends no override
    // and the backend keeps its own .env choice. Changing the provider or model makes the
    // backend report reindex_required, and api_client::sync_configuration() then queues a
    // reindex of every document, because vectors from different models are not comparable.
    // Once an embedding provider has been saved, warn that changing it costs a full reindex.
    // Heading descriptions are rendered as Markdown, so the blank line starts a new paragraph.
    $embeddingdesc = get_string('embedding_heading_desc', 'local_ragassistant');
    if ((string) get_config('local_ragassistant', 'embedding_provider') !== '') {
        $embeddingdesc .= "\n" . get_string('embedding_change_warning', 'local_ragassistant');
    }
    $settings->add(new admin_setting_heading(
        'local_ragassistant/embedding_heading',
        get_string('embedding_heading', 'local_ragassistant'),
        $embeddingdesc
    ));

    $settings->add(new admin_setting_configselect(
        'local_ragassistant/embedding_provider',
        get_string('embedding_provider', 'local_ragassistant'),
        get_string('embedding_provider_desc', 'local_ragassistant'),
        '',
        [
            '' => get_string('embedding_provider_server', 'local_ragassistant'),
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'ollama' => 'Ollama',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/embedding_model',
        get_string('embedding_model', 'local_ragassistant'),
        get_string('embedding_model_desc', 'local_ragassistant'),
        '',
        PARAM_TEXT
    ));

    // Same as the image section: with the same provider as chat, the chat key can be reused.
    $settings->add(new admin_setting_configcheckbox(
        'local_ragassistant/embedding_reuse_chat_api_key',
        get_string('embedding_reuse_chat_api_key', 'local_ragassistant'),
        get_string('embedding_reuse_chat_api_key_desc', 'local_ragassistant'),
        0
    ));

    // Embedding keys are stored separately from the chat and image keys, per provider,
    // in the same way as the image key (see the encrypted setting class).
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/embedding_provider_api_key',
        get_string('embedding_provider_api_key', 'local_ragassistant'),
        get_string('embedding_provider_api_key_desc', 'local_ragassistant'),
        ''
    ));

     // Local Ollama is used by FastAPI only when the primary cloud model fails.
    $settings->add(new admin_setting_heading(
        'local_ragassistant/fallback_heading',
        get_string('fallback_heading', 'local_ragassistant'),
        get_string('fallback_heading_desc', 'local_ragassistant')
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/fallback_model',
        get_string('fallback_model', 'local_ragassistant'),
        get_string('fallback_model_desc', 'local_ragassistant'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_ragassistant/health_heading',
        get_string('configurationhealth_heading', 'local_ragassistant'),
        get_string('configurationhealth_heading_desc', 'local_ragassistant')
    ));

    $settings->add(new local_ragassistant_admin_setting_configuration_health());

    // Show only the settings that apply. Hidden values are still saved and kept.
    // Several hide_if() calls on one setting hide it when any condition matches.
    // Chat off: hide the settings used only by the chatbot. The backend URL and
    // application API key stay visible because indexing and connection tests use them.
    $chatonly = [
        'llm_provider', 'chat_model', 'provider_api_key', 'fallback_model',
        'image_provider', 'image_model', 'reuse_chat_api_key', 'image_provider_api_key',
    ];
    foreach ($chatonly as $name) {
        $settings->hide_if('local_ragassistant/' . $name, 'local_ragassistant/enable_chat', 'notchecked');
    }
    // Ollama runs locally: no provider key, no Ollama fallback, and no chat key to reuse.
    $settings->hide_if('local_ragassistant/provider_api_key', 'local_ragassistant/llm_provider', 'eq', 'ollama');
    $settings->hide_if('local_ragassistant/fallback_model', 'local_ragassistant/llm_provider', 'eq', 'ollama');
    $settings->hide_if('local_ragassistant/reuse_chat_api_key', 'local_ragassistant/llm_provider', 'eq', 'ollama');
    // A reused chat key replaces the separate image key.
    $settings->hide_if('local_ragassistant/image_provider_api_key', 'local_ragassistant/reuse_chat_api_key', 'checked');
    // Embeddings: "Use the server setting" needs no model or key here, and Ollama needs no key.
    // Not chat-only: indexing uses embeddings even when AI chat is off.
    $settings->hide_if('local_ragassistant/embedding_model', 'local_ragassistant/embedding_provider', 'eq', '');
    $settings->hide_if('local_ragassistant/embedding_provider_api_key', 'local_ragassistant/embedding_provider', 'eq', '');
    $settings->hide_if('local_ragassistant/embedding_provider_api_key', 'local_ragassistant/embedding_provider', 'eq', 'ollama');
    // A reused chat key replaces the separate embedding key. The reuse option needs a chat
    // key to reuse, so it is hidden with chat off, for Ollama chat, and when embeddings need
    // no key. Each "Reuse chat provider API key" tick is also hidden while its provider differs
    // from the chat provider; hide_if() cannot compare two fields, so configuration_health.js
    // does that part (initReuseVisibility).
    $settings->hide_if('local_ragassistant/embedding_provider_api_key', 'local_ragassistant/embedding_reuse_chat_api_key', 'checked');
    $settings->hide_if('local_ragassistant/embedding_reuse_chat_api_key', 'local_ragassistant/enable_chat', 'notchecked');
    $settings->hide_if('local_ragassistant/embedding_reuse_chat_api_key', 'local_ragassistant/llm_provider', 'eq', 'ollama');
    $settings->hide_if('local_ragassistant/embedding_reuse_chat_api_key', 'local_ragassistant/embedding_provider', 'eq', '');
    $settings->hide_if('local_ragassistant/embedding_reuse_chat_api_key', 'local_ragassistant/embedding_provider', 'eq', 'ollama');

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ragassistant_testconnection',
        get_string('testconnection', 'local_ragassistant'),
        new moodle_url('/local/ragassistant/test_connection.php')
    ));
}
