/**
 * Configuration health checks
 *
 * Values are submitted to Moodle's server-side endpoint
 * Provider credentials are not sent to the client-side.
 *
 * @module local_ragassistant/configuration_health
 */

// Icon, icon colour and badge style for each state of a test row.
const STATES = {
    'idle': {icon: 'fa-regular fa-circle', color: 'text-muted', badge: 'text-bg-light border'},
    'pending': {icon: 'fa-solid fa-spinner fa-spin', color: 'text-primary', badge: 'text-bg-info'},
    'pass': {icon: 'fa-solid fa-circle-check', color: 'text-success', badge: 'text-bg-success'},
    'fail': {icon: 'fa-solid fa-circle-xmark', color: 'text-danger', badge: 'text-bg-danger'},
    'skipped': {icon: 'fa-solid fa-circle-minus', color: 'text-muted', badge: 'text-bg-secondary'},
};

export const init = (option) => {
    const button = document.getElementById('ragassistant-run-healthcheck');

    if (!button) {
        return;
    }

    const rows = document.querySelectorAll('#ragassistant-healthcheck [data-test]');
    const summary = document.getElementById('ragassistant-healthcheck-summary');
    const strings = option.strings || {};

    // Admin setting inputs are named s_<plugin>_<setting>.
    const getValue = (name) => {
        const field = document.querySelector(`[name="s_local_ragassistant_${name}"]`);
        return field ? field.value.trim() : '';
    };

    // Moodle renders a hidden "0" input before each checkbox, so read the checkbox itself.
    const isChecked = (name) => {
        const field = document.querySelector(`input[type="checkbox"][name="s_local_ragassistant_${name}"]`);
        return field ? field.checked : false;
    };

    // Collect the unsaved form values to test, keyed as ajax/test_configuration.php expects.
    // The backend URL and application API key are not sent: the server always uses the
    // saved ones. Key fields are blank unless the administrator has just typed a new key,
    // in which case the saved key is used for the other provider.
    const collectConfiguration = () => ({
        'llm_provider': getValue('llm_provider'),
        'llm_model': getValue('chat_model'),
        'fallback_model': getValue('fallback_model'),
        'image_provider': getValue('image_provider'),
        'image_model': getValue('image_model'),
        'reuse_chat_api_key': isChecked('reuse_chat_api_key'),
        'provider_api_key': getValue('provider_api_key'),
        'image_provider_api_key': getValue('image_provider_api_key'),
    });

    // Show one test's state. Text is set with textContent, so backend text is never parsed as HTML.
    const setRow = (row, status, message) => {
        const state = STATES[status] || STATES.idle;
        const icon = row.querySelector('[data-role="icon"]');
        const badge = row.querySelector('[data-role="badge"]');
        const text = row.querySelector('[data-role="message"]');

        row.dataset.status = status;
        icon.className = `${state.icon} fs-4 ${state.color} me-3 mt-1`;
        badge.className = `badge ${state.badge} ms-3`;
        badge.textContent = status === 'pending' ? strings.testing : (strings[status] || strings.idle);
        text.textContent = message || row.dataset.description;
        text.className = status === 'fail' ? 'small text-danger' : 'small text-muted';
    };

    const setSummary = (text, isError) => {
        if (!summary) {
            return;
        }
        summary.textContent = text;
        summary.className = isError ? 'mt-3 fw-bold text-danger' : 'mt-3 fw-bold';
    };

    button.addEventListener('click', async() => {
        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = strings.running;
        setSummary('', false);

        rows.forEach((row) => setRow(row, 'pending', ''));

        try {
            const formData = new URLSearchParams();
            formData.append('sesskey', M.cfg.sesskey);
            formData.append('config', JSON.stringify(collectConfiguration()));

            const response = await fetch(option.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData,
            });

            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message);
            }

            let failed = false;
            rows.forEach((row) => {
                const test = result.results[row.dataset.test];
                if (test) {
                    failed = failed || test.status === 'fail';
                    setRow(row, test.status, test.message);
                } else {
                    setRow(row, 'idle', '');
                }
            });
            setSummary(result.summary, failed);
        } catch (error) {
            // The request itself failed, so no test result is known: put the rows back.
            rows.forEach((row) => setRow(row, 'idle', ''));
            setSummary(error.message, true);
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    });
};
