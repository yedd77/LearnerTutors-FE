<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Password setting that encrypts its value before Moodle stores it.
 * Existing values are never rendered to the administrator's browser.
 * An empty submission preserves the currently configured secret.
 */
class local_ragassistant_admin_setting_configencryptedpassword extends admin_setting_configtext {
    /**
     * Construct a password input without Moodle's unmask/show-password control.
     *
     * @param string $name Setting name.
     * @param string $visiblename Visible label.
     * @param string $description Setting description.
     * @param string $defaultsetting Default value (intentionally blank).
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 30);
    }

    /**
     * Mask credential values in Moodle's configuration change log.
     *
     * @param string $name Setting name.
     * @param string $oldvalue Previous value.
     * @param string $value New value.
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        if ($value !== '') {
            $value = '********';
        }
        if ($oldvalue !== '' && $oldvalue !== null) {
            $oldvalue = '********';
        }
        parent::add_to_config_log($name, $oldvalue, $value);
    }
    /**
     * Render the empty password input together with a safe credential status.
     *
     * The saved credential is never passed to Moodle's password input or
     * included in the generated HTML. Only its availability is displayed.
     */
    public function output_html($data, $query = '') {
        // Render our own password input instead of Moodle's unmaskable password
        // setting. This avoids the Show/Hide control and Moodle's generic placeholder.
        $id = $this->get_id();
        $attributes = [
            'type' => 'password',
            'size' => $this->size,
            'id' => $id,
            'name' => $this->get_full_name(),
            'value' => '',
            'placeholder' => get_string('credentialinputplaceholder', 'local_ragassistant'),
            'autocomplete' => 'new-password',
            'class' => 'form-control',
        ];

        if ($this->is_readonly()) {
            $attributes['readonly'] = 'readonly';
            unset($attributes['name']);
        }

        $element = html_writer::empty_tag('input', $attributes);
        $html = format_admin_setting(
            $this,
            $this->visiblename,
            $element,
            $this->description,
            true,
            '',
            null,
            $query
        );
        $status = $this->get_credential_status();

        // Show a badge only when something needs attention (for example a key that cannot be
        // decrypted). "Configured" is redundant, and "Not configured" is not shown either: the
        // empty field already invites a key, and the configuration tests report a missing one.
        if (in_array($status['string'], ['credentialconfigured', 'credentialnotconfigured'], true)) {
            return $html;
        }

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
                $stored = (string) get_config('local_ragassistant', 'api_key');
                if ($stored === '') {
                    return ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
                }
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

            if ($settingname === 'embedding_provider_api_key') {
                $provider = (string) get_config('local_ragassistant', 'embedding_provider');
                // "Use the server setting" (blank) and Ollama need no key in Moodle.
                if (!in_array($provider, ['openai', 'gemini'], true)) {
                    return ['string' => 'credentialnotrequired', 'class' => 'badge-secondary'];
                }
                $reuse = (bool) get_config('local_ragassistant', 'embedding_reuse_chat_api_key');
                $key = ($reuse && $provider === (string) get_config('local_ragassistant', 'llm_provider'))
                    ? \local_ragassistant\local\secret_store::get_provider_api_key($provider)
                    : \local_ragassistant\local\secret_store::get_embedding_provider_api_key($provider);
                return $key !== ''
                    ? ['string' => 'credentialconfigured', 'class' => 'badge-success']
                    : ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
            }
        } catch (\Throwable $exception) {
            // Do not expose decryption errors or credential details in settings HTML.
            // Use error_log() rather than debugging(): with developer debugging and Whoops
            // enabled, debugging() is raised as an error and replaces the settings page.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- see the comment above.
            error_log('local_ragassistant: could not verify a stored API credential: ' . get_class($exception));
            return ['string' => 'credentialverificationfailed', 'class' => 'badge-warning'];
        }

        return ['string' => 'credentialnotconfigured', 'class' => 'badge-secondary'];
    }

    /**
     * Encrypt and store a submitted key. A blank submission keeps the saved key.
     *
     * @param mixed $data Submitted value.
     * @return string Empty string on success, otherwise an error message.
     */
    public function write_setting($data) {
        $value = trim((string) $data);
        $parts = explode('/', $this->name);
        $settingname = end($parts);

        // The "Reuse chat provider API key" option only works when chat and image use the
        // same provider; the backend rejects the whole configuration otherwise. This field is
        // saved after both providers and the reuse checkbox, so correct the checkbox here.
        if (
            $settingname === 'image_provider_api_key'
            && get_config('local_ragassistant', 'reuse_chat_api_key')
            && get_config('local_ragassistant', 'image_provider') !== get_config('local_ragassistant', 'llm_provider')
        ) {
            set_config('reuse_chat_api_key', 0, 'local_ragassistant');
        }
        // The same rule for reusing the chat key for text embeddings.
        if (
            $settingname === 'embedding_provider_api_key'
            && get_config('local_ragassistant', 'embedding_reuse_chat_api_key')
            && get_config('local_ragassistant', 'embedding_provider') !== get_config('local_ragassistant', 'llm_provider')
        ) {
            set_config('embedding_reuse_chat_api_key', 0, 'local_ragassistant');
        }

        if ($value === '') {
            return '';
        }

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
        } else if ($settingname === 'embedding_provider_api_key') {
            $provider = (string) get_config('local_ragassistant', 'embedding_provider');
            if (!in_array($provider, ['openai', 'gemini'], true)) {
                return get_string('providerkeynotrequired', 'local_ragassistant');
            }
            $settingname = 'embedding_' . $provider . '_api_key';
            $purpose = $settingname;
        }

        $minimumlength = $settingname === 'api_key' ? 32 : 20;
        $allowed = ['api_key', 'openai_api_key', 'gemini_api_key', 'image_openai_api_key', 'image_gemini_api_key',
            'embedding_openai_api_key', 'embedding_gemini_api_key'];
        if (!in_array($settingname, $allowed, true) || strlen($value) < $minimumlength) {
            return get_string(
                $settingname === 'api_key' ? 'apikeytoolong' : 'providerkeytoolong',
                'local_ragassistant'
            );
        }

        try {
            $encrypted = \local_ragassistant\local\secret_store::encrypt($value, $purpose);
            set_config($settingname, $encrypted, 'local_ragassistant');
        } catch (\Throwable $exception) {
            // Use error_log() rather than debugging(), so a storage failure shows the form error
            // instead of a Whoops page. The message comes from secret_store and never contains the key.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- see the comment above.
            error_log('local_ragassistant: could not securely store an API credential: ' . $exception->getMessage());
            return get_string('secretstoragefailed', 'local_ragassistant');
        }

        // Only the selected provider's key is kept: saving a key for one provider deletes
        // the other provider's key in the same group, here and (on the next sync) on the
        // backend. Moodle setting name => [the other provider's setting, its backend field].
        $replaces = [
            'openai_api_key' => ['gemini_api_key', 'gemini_api_key'],
            'gemini_api_key' => ['openai_api_key', 'openai_api_key'],
            'image_openai_api_key' => ['image_gemini_api_key', 'gemini_image_api_key'],
            'image_gemini_api_key' => ['image_openai_api_key', 'openai_image_api_key'],
            'embedding_openai_api_key' => ['embedding_gemini_api_key', 'gemini_embedding_api_key'],
            'embedding_gemini_api_key' => ['embedding_openai_api_key', 'openai_embedding_api_key'],
        ];
        if (isset($replaces[$settingname])) {
            $savedfield = $replaces[$replaces[$settingname][0]][1];
            \local_ragassistant\local\secret_store::remove_pending_key_clear($savedfield);

            [$othersetting, $otherfield] = $replaces[$settingname];
            if ((string) get_config('local_ragassistant', $othersetting) !== '') {
                unset_config($othersetting, 'local_ragassistant');
                \local_ragassistant\local\secret_store::add_pending_key_clear($otherfield);
            }
        }
        return '';
    }
}
