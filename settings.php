<?php
// This file is part of Moodle - https://moodle.org/
//
// Site administration settings.

defined('MOODLE_INTERNAL') || die();

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

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/image_model',
        get_string('image_model', 'local_ragassistant'),
        get_string('image_model_desc', 'local_ragassistant'),
        '',
        PARAM_TEXT
    ));

    // Provider credentials are encrypted at rest and never shown after saving.
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/openai_api_key',
        get_string('openai_api_key', 'local_ragassistant'),
        get_string('openai_api_key_desc', 'local_ragassistant'),
        ''
    ));

    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/gemini_api_key',
        get_string('gemini_api_key', 'local_ragassistant'),
        get_string('gemini_api_key_desc', 'local_ragassistant'),
        ''
    ));

    // This setting stores AES-256-GCM ciphertext in Moodle's config_plugins table.
    // The encryption key itself must be configured outside the database.
    $settings->add(new local_ragassistant_admin_setting_configencryptedpassword(
        'local_ragassistant/api_key',
        get_string('apikey', 'local_ragassistant'),
        get_string('apikey_desc', 'local_ragassistant'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ragassistant_testconnection',
        get_string('testconnection', 'local_ragassistant'),
        new moodle_url('/local/ragassistant/test_connection.php')
    ));
}
