<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Password setting that encrypts its value before Moodle stores it.
 * Existing values are never rendered to the administrator's browser.
 * An empty submission preserves the currently configured secret.
 */
class local_ragassistant_admin_setting_configencryptedpassword extends admin_setting_configpasswordunmask {
    /**
     * Render the empty password input together with a safe credential status.
     *
     * The saved credential is never passed to Moodle's password input or
     * included in the generated HTML. Only its availability is displayed.
     */
    public function output_html($data, $query = '') {
        $html = parent::output_html('', $query);
        $status = $this->get_credential_status();

        $statushtml = html_writer::tag(
            'span',
            get_string($status['string'], 'local_ragassistant'),
            ['class' => 'badge ' . $status['class']]
        );
        $help = html_writer::div(
            $statushtml,
            'local-ragassistant-credential-status',
            ['style' => 'margin-top: .5rem;']
        );

        return $html . $help;
    }

    /**
     * Determine whether the credential for this setting exists and decrypts.
     *
     * Decryption happens only on the server. No credential value is returned
     * to the browser, logged, or included in the status message.
     *
     * @return array Status string identifier and CSS class.
     */
    private function get_credential_status(): array {
        $parts = explode('/', $this->name);
        $settingname = end($parts);

        try {
            if ($settingname === 'api_key') {
                \local_ragassistant\local\secret_store::get_api_key();
                return ['string' => 'credentialconfigured', 'class' => 'badge-success'];
            }

            if ($settingname === 'provider_api_key') {
                $provider = (string) get_config('local_ragassistant', 'llm_provider');
                if ($provider === 'ollama') {
                    return ['string' => 'credentialnotrequired', 'class' => 'badge-secondary'];
                }
                if (!in_array($provider, ['openai', 'gemini'], true)) {
                    return ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
                }
                $key = \local_ragassistant\local\secret_store::get_provider_api_key($provider);
                return $key !== ''
                    ? ['string' => 'credentialconfigured', 'class' => 'badge-success']
                    : ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
            }

            if ($settingname === 'image_provider_api_key') {
                $imageprovider = (string) get_config('local_ragassistant', 'image_provider');
                $chatprovider = (string) get_config('local_ragassistant', 'llm_provider');
                $reuse = (bool) get_config('local_ragassistant', 'reuse_chat_api_key');

                if ($reuse && $imageprovider === $chatprovider && in_array($chatprovider, ['openai', 'gemini'], true)) {
                    $key = \local_ragassistant\local\secret_store::get_provider_api_key($chatprovider);
                } else if (in_array($imageprovider, ['openai', 'gemini'], true)) {
                    $key = \local_ragassistant\local\secret_store::get_image_provider_api_key($imageprovider);
                } else {
                    return ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
                }

                return $key !== ''
                    ? ['string' => 'credentialconfigured', 'class' => 'badge-success']
                    : ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
            }
        } catch (\Throwable $exception) {
            // Do not expose decryption errors or credential details in settings HTML.
            debugging('Could not verify a stored API credential.', DEBUG_DEVELOPER);
            return ['string' => 'credentialverificationfailed', 'class' => 'badge-warning'];
        }

        return ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
    }

    public function write_setting($data) {
        $value = trim((string) $data);
        if ($value === '') {
            return '';
        }

        $parts = explode('/', $this->name);
        $settingname = end($parts);
        $purpose = $settingname;

        // The single provider key field stores the credential for the provider
        // selected immediately above it in the Moodle settings page.
        if ($settingname === 'provider_api_key') {
            $provider = (string) get_config('local_ragassistant', 'llm_provider');
            if (!in_array($provider, ['openai', 'gemini'], true)) {
                return get_string('providerkeynotrequired', 'local_ragassistant');
            }
            $settingname = $provider . '_api_key';
            $purpose = $settingname;
        } else if ($settingname === 'image_provider_api_key') {
            $provider = (string) get_config('local_ragassistant', 'image_provider');
            if (!in_array($provider, ['openai', 'gemini'], true)) {
                return get_string('providerkeynotrequired', 'local_ragassistant');
            }
            $settingname = 'image_' . $provider . '_api_key';
            $purpose = $settingname;
        }

        $minimumlength = $settingname === 'api_key' ? 32 : 20;
        if (!in_array($settingname, ['api_key', 'openai_api_key', 'gemini_api_key', 'image_openai_api_key', 'image_gemini_api_key'], true)
                || strlen($value) < $minimumlength) {
            return get_string(
                $settingname === 'api_key' ? 'apikeytoolong' : 'providerkeytoolong',
                'local_ragassistant'
            );
        }

        try {
            $encrypted = \local_ragassistant\local\secret_store::encrypt($value, $purpose);
            set_config($settingname, $encrypted, 'local_ragassistant');
        } catch (\Throwable $exception) {
            debugging('Could not securely store an API credential: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return get_string('secretstoragefailed', 'local_ragassistant');
        }
        return '';
    }
}
