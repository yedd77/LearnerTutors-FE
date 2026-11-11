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
        'local_ragassistant/chat_model',
        get_string('llm_model', 'local_ragassistant'),
        get_string('llm_model_desc', 'local_ragassistant'),
        'gpt-5.6-sol',
        PARAM_TEXT
    ));

    // Embedding configuration is global because every query vector must
    // use the exact same embedding space as indexed document vectors.
    $settings->add(new admin_setting_configselect(
        'local_ragassistant/embedding_provider',
        get_string('embeddingprovider', 'local_ragassistant'),
        get_string('embeddingprovider_desc', 'local_ragassistant'),
        'openai',
        [
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'ollama' => 'Ollama',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/embedding_model',
        get_string('embeddingmodel', 'local_ragassistant'),
        get_string('embeddingmodel_desc', 'local_ragassistant'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/gemini_embed_dimensions',
        get_string('geminiembeddimensions', 'local_ragassistant'),
        get_string('geminiembeddimensions_desc', 'local_ragassistant'),
        '768',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ragassistant/temperature',
        get_string('temperature', 'local_ragassistant'),
        get_string('temperature_desc', 'local_ragassistant'),
        '0',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ragassistant/api_key',
        get_string('apikey', 'local_ragassistant'),
        get_string('apikey_desc', 'local_ragassistant'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);
}
