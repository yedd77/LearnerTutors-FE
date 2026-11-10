<?php
// This file is part of Moodle - https://moodle.org/
//
// Site administration settings.

defined('MOODLE_INTERNAL') || die();

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

    // Chat provider/model are defaults only. The backend remains responsible
    // for API credentials; Moodle must never store provider API keys.
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
        'local_ragassistant/llm_model',
        get_string('llm_model', 'local_ragassistant'),
        get_string('llm_model_desc', 'local_ragassistant'),
        'gpt-4o-luna',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
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

    $ADMIN->add('localplugins', $settings);
}
