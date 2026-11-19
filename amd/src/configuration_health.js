/**
 * Configuration health checks
 *
 * Values are submitted to Moodle's server-side endpoint
 * Provider credentials are not sent to the client-side.
 *
 * @module local_ragassistant/configuration_health
 */

// Icon, badge and message style for each state of a test row. "primary" is the theme's
// brand colour in Moodle, so the running state follows the site's accent colour. The
// spinner is 1rem wide; mx-1 keeps it in the same 1.5rem column as the fs-4 icons.
const STATES = {
    'idle': {icon: 'fa-regular fa-circle fs-4 text-muted mt-1', badge: 'text-bg-light border', text: 'text-muted'},
    'pending': {icon: 'spinner-border spinner-border-sm text-primary mx-1 mt-2', badge: 'text-bg-primary', text: 'text-primary'},
    'pass': {icon: 'fa-solid fa-circle-check fs-4 text-success mt-1', badge: 'text-bg-success', text: 'text-muted'},
    'fail': {icon: 'fa-solid fa-circle-xmark fs-4 text-danger mt-1', badge: 'text-bg-danger', text: 'text-danger'},
    'skipped': {icon: 'fa-solid fa-circle-minus fs-4 text-muted mt-1', badge: 'text-bg-secondary', text: 'text-muted'},
};

// Settings that the configuration tests cover. Changing any of them while AI chat is
// enabled means the tests must pass again before the settings can be saved.
const TESTED_SETTINGS = ['enable_chat', 'api_base_url', 'api_key', 'llm_provider', 'chat_model', 'provider_api_key',
    'fallback_model', 'image_provider', 'image_model', 'reuse_chat_api_key', 'image_provider_api_key'];

// The checks whose failure blocks saving. Background tasks (cron) only warn: saving
// the form cannot fix them.
const BLOCKING_TESTS = ['server', 'llm', 'image', 'fallback'];

// Admin setting inputs are named s_<plugin>_<setting>.
const field = (name) => document.querySelector(`[name="s_local_ragassistant_${name}"]`);

// Moodle renders a hidden "0" input before each checkbox, so find the checkbox itself.
const checkbox = (name) => document.querySelector(`input[type="checkbox"][name="s_local_ragassistant_${name}"]`);

// Tell Moodle's own scripts (hide_if, change tracking) about a value set from code.
const notify = (element, type) => element.dispatchEvent(new Event(type, {bubbles: true}));

// settings.php uses hide_if() for the setting rows, but hide_if() cannot reach the section
// headings or this panel because they have no form field. Follow the rows Moodle shows and
// hides instead, so the hide_if() rules in settings.php stay the single source of truth.
const initSectionVisibility = () => {
    const chat = checkbox('enable_chat');
    const panel = document.getElementById('admin-configuration_health');
    // The first setting row of each section that has a heading. The panel's own row
    // anchors the "Configuration Health Checks" heading.
    const anchors = ['admin-llm_provider', 'admin-image_provider', 'admin-fallback_model', 'admin-configuration_health']
        .map((id) => document.getElementById(id))
        .filter((row) => row);

    if (!chat || !anchors.length) {
        return;
    }

    const isHidden = (element) => element.hidden || element.classList.contains('d-none') ||
        window.getComputedStyle(element).display === 'none';

    // Only write when the value changes, so the observer below does not loop.
    const setHidden = (element, hidden) => {
        if (element.hidden !== hidden) {
            element.hidden = hidden;
        }
    };

    // A heading and its description sit directly before the first setting row of the section.
    const headingParts = (row) => {
        const parts = [];
        let element = row.previousElementSibling;
        while (element && !element.classList.contains('form-item')) {
            parts.push(element);
            element = element.previousElementSibling;
        }
        return parts;
    };

    const sync = () => {
        // Update the panel first so its heading follows in the same pass.
        if (panel) {
            setHidden(panel, !chat.checked);
        }
        anchors.forEach((row) => {
            const hidden = isHidden(row);
            headingParts(row).forEach((part) => setHidden(part, hidden));
        });
    };

    new MutationObserver(sync).observe(chat.form || document.body, {
        attributes: true,
        subtree: true,
        attributeFilter: ['class', 'style', 'hidden'],
    });
    chat.addEventListener('change', sync);
    sync();
};

// When a provider changes, empty its key field. Text typed for a provider is kept in this
// page only and restored on switching back; saved keys are never sent to the browser, so
// a status line says whether a key is saved for the selected provider instead.
const initProviderKeys = (savedkeys, strings) => {
    [['llm_provider', 'provider_api_key'], ['image_provider', 'image_provider_api_key']].forEach(([providername, keyname]) => {
        const provider = field(providername);
        const key = field(keyname);
        if (!provider || !key) {
            return;
        }

        const typed = {};
        let current = provider.value;
        let status = null;

        provider.addEventListener('change', () => {
            typed[current] = key.value;
            current = provider.value;
            key.value = typed[current] || '';

            // The status badge rendered with the page describes the previous provider.
            const row = key.closest('.form-item');
            const pagebadge = row ? row.querySelector('.local-ragassistant-credential-status') : null;
            if (pagebadge) {
                pagebadge.hidden = true;
            }
            if (!status) {
                status = document.createElement('div');
                status.className = 'form-text';
                status.dataset.role = 'keystatus';
                key.insertAdjacentElement('afterend', status);
            }
            const saved = (savedkeys[keyname] || {})[current];
            status.textContent = saved === undefined ? '' : (saved ? strings.keysaved : strings.keynone);
            notify(key, 'input');
        });
    });
};

// "Reuse chat provider API key" needs the same provider for chat and images, and it
// silently follows the chat key. Untick it when the chat provider or key changes, or when
// the image provider no longer matches, so the administrator chooses the image key again.
const initReuseRules = () => {
    const reuse = checkbox('reuse_chat_api_key');
    const chatprovider = field('llm_provider');
    const imageprovider = field('image_provider');
    const chatkey = field('provider_api_key');
    if (!reuse) {
        return;
    }

    const untick = () => {
        if (reuse.checked) {
            reuse.checked = false;
            notify(reuse, 'change');
        }
    };

    if (chatprovider) {
        chatprovider.addEventListener('change', untick);
    }
    if (imageprovider) {
        imageprovider.addEventListener('change', () => {
            if (!chatprovider || imageprovider.value !== chatprovider.value) {
                untick();
            }
        });
    }
    if (chatkey) {
        chatkey.addEventListener('input', () => {
            if (chatkey.value.trim() !== '') {
                untick();
            }
        });
    }
};

// While AI chat is enabled, changes to tested settings lock "Save changes" until a test
// run with no blocking failures has covered exactly the current values. Turning AI chat
// off always unlocks it, so a provider outage can never lock an administrator out.
const initSaveGate = (strings) => {
    const chat = checkbox('enable_chat');
    const form = chat ? chat.form : null;
    if (!form) {
        return {snapshot: () => '', tested: () => null};
    }

    const snapshot = () => JSON.stringify(TESTED_SETTINGS.map((name) => {
        const box = checkbox(name);
        if (box) {
            return box.checked;
        }
        const element = field(name);
        return element ? element.value : null;
    }));

    const initial = snapshot();
    let passed = null;

    const buttons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
    const notice = document.createElement('div');
    notice.className = 'alert alert-warning';
    notice.dataset.role = 'savelocked';
    notice.textContent = strings.savelocked;
    notice.hidden = true;
    if (buttons.length) {
        buttons[0].insertAdjacentElement('beforebegin', notice);
    }

    const locked = () => {
        const now = snapshot();
        return chat.checked && now !== initial && now !== passed;
    };

    const update = () => {
        const islocked = locked();
        buttons.forEach((button) => {
            button.disabled = islocked;
        });
        notice.hidden = !islocked;
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('submit', (event) => {
        if (locked()) {
            event.preventDefault();
            update();
        }
    });
    update();

    return {
        snapshot,
        // Record the values a finished run tested; null when it had blocking failures.
        tested: (values) => {
            passed = values;
            update();
        },
    };
};

export const init = (option) => {
    const strings = option.strings || {};

    initSectionVisibility();
    initProviderKeys(option.keys || {}, strings);
    initReuseRules();
    const gate = initSaveGate(strings);

    const button = document.getElementById('ragassistant-run-healthcheck');

    if (!button) {
        return;
    }

    const rows = document.querySelectorAll('#ragassistant-healthcheck [data-test]');
    const summary = document.getElementById('ragassistant-healthcheck-summary');
    const results = document.querySelector('#ragassistant-healthcheck [data-role="results"]');

    const getValue = (name) => {
        const element = field(name);
        return element ? element.value.trim() : '';
    };

    const isChecked = (name) => {
        const element = checkbox(name);
        return element ? element.checked : false;
    };

    // Collect the form values to test, keyed as ajax/test_configuration.php expects. Key
    // fields are blank unless the administrator has just typed a new key; blank means the
    // server uses the saved key. The backend URL and application key are sent so new
    // values can be tested before they are saved; testing never saves anything.
    const collectConfiguration = () => ({
        'api_base_url': getValue('api_base_url'),
        'api_key': getValue('api_key'),
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
        icon.className = `${state.icon} me-3 flex-shrink-0`;
        badge.className = `badge ${state.badge} ms-3`;
        badge.textContent = status === 'pending' ? strings.testing : (strings[status] || strings.idle);
        // No badge until a test has started.
        badge.hidden = status === 'idle';
        text.textContent = message || strings.idle;
        text.className = `small ${state.text}`;
    };

    // Running line under the list: elapsed seconds, so a long AI check never looks frozen.
    const running = document.querySelector('#ragassistant-healthcheck [data-role="running"]');
    const elapsed = running ? running.querySelector('[data-role="elapsed"]') : null;
    let timer = null;
    const startRunning = () => {
        const started = Date.now();
        const tick = () => {
            const seconds = Math.round((Date.now() - started) / 1000);
            elapsed.textContent = (strings.elapsed || '').replace('{seconds}', seconds);
        };
        if (running && elapsed) {
            tick();
            running.hidden = false;
            timer = setInterval(tick, 1000);
        }
    };
    const stopRunning = () => {
        clearInterval(timer);
        timer = null;
        if (running) {
            running.hidden = true;
        }
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
        // The values this run tests; edits made while it runs need another run.
        const testedvalues = gate.snapshot();
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('aria-hidden', 'true');
        button.replaceChildren(spinner, document.createTextNode(strings.running));
        // Announced once by screen readers but not shown: the button and the elapsed line
        // already say it. The result summary replaces it.
        if (summary) {
            summary.textContent = strings.running;
            summary.className = 'visually-hidden';
        }

        // The rows stay hidden until the first run.
        if (results) {
            results.hidden = false;
            results.setAttribute('aria-busy', 'true');
        }
        rows.forEach((row) => setRow(row, 'pending', row.dataset.testing));
        startRunning();

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
            let blocked = false;
            rows.forEach((row) => {
                const test = result.results[row.dataset.test];
                if (test) {
                    failed = failed || test.status === 'fail';
                    blocked = blocked || (test.status === 'fail' && BLOCKING_TESTS.includes(row.dataset.test));
                    setRow(row, test.status, test.message);
                } else {
                    blocked = blocked || BLOCKING_TESTS.includes(row.dataset.test);
                    setRow(row, 'idle', '');
                }
            });
            setSummary(result.summary, failed);
            gate.tested(blocked ? null : testedvalues);
        } catch (error) {
            // The request itself failed, so no test result is known: put the rows back.
            rows.forEach((row) => setRow(row, 'idle', ''));
            setSummary(error.message, true);
            gate.tested(null);
        } finally {
            stopRunning();
            if (results) {
                results.removeAttribute('aria-busy');
            }
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = originalLabel;
        }
    });
};
