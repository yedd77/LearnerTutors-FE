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

// Every check must pass before changed settings can be saved. The local fallback is not
// required while chat itself uses Ollama, because the fallback is then hidden and unused.
const ALL_TESTS = ['server', 'cron', 'llm', 'image', 'fallback'];

// Checks that the backend runs. The backend always tests the LLM in the same request.
const AI_TESTS = ['llm', 'image', 'fallback'];

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

// While AI chat is enabled, changes to tested settings lock "Save changes" until every
// required check has passed for the current values (isSatisfied). Turning AI chat off
// always unlocks it, so a provider outage can never lock an administrator out.
const initSaveGate = (strings, isSatisfied) => {
    const chat = checkbox('enable_chat');
    const form = chat ? chat.form : null;
    if (!form) {
        return {update: () => null};
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

    const buttons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
    const notice = document.createElement('div');
    notice.className = 'alert alert-warning';
    notice.dataset.role = 'savelocked';
    notice.textContent = strings.savelocked;
    notice.hidden = true;
    if (buttons.length) {
        buttons[0].insertAdjacentElement('beforebegin', notice);
    }

    const locked = () => chat.checked && snapshot() !== initial && !isSatisfied();

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

    return {update};
};

export const init = (option) => {
    const strings = option.strings || {};

    initSectionVisibility();
    initProviderKeys(option.keys || {}, strings);
    initReuseRules();

    const button = document.getElementById('ragassistant-run-healthcheck');

    if (!button) {
        // Without the panel nothing can be tested, so do not lock saving.
        initSaveGate(strings, () => true);
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

    const rowFor = (test) => [...rows].find((row) => row.dataset.test === test);

    // The form values each check depends on. A result only counts while these are unchanged.
    const signature = (test) => {
        const server = [getValue('api_base_url'), getValue('api_key')];
        const reuse = isChecked('reuse_chat_api_key');
        const values = {
            server,
            cron: [],
            llm: [...server, getValue('llm_provider'), getValue('chat_model'), getValue('provider_api_key')],
            image: [...server, getValue('image_provider'), getValue('image_model'), getValue('image_provider_api_key'),
                reuse, ...(reuse ? [getValue('llm_provider'), getValue('provider_api_key')] : [])],
            fallback: [...server, getValue('fallback_model')],
        };
        return JSON.stringify(values[test]);
    };

    const requiredTests = () => ALL_TESTS.filter((test) => test !== 'fallback' || getValue('llm_provider') !== 'ollama');

    const passedNow = (test) => {
        const row = rowFor(test);
        return !!row && row.dataset.status === 'pass' && row.dataset.sig === signature(test);
    };

    // One summary for all rows, including results kept from an earlier run.
    const showSummary = () => {
        const counts = {pass: 0, fail: 0, skipped: 0};
        rows.forEach((row) => {
            if (counts[row.dataset.status] !== undefined) {
                counts[row.dataset.status]++;
            }
        });
        const text = (strings.summary || '').replace('{pass}', counts.pass).replace('{fail}', counts.fail)
            .replace('{skipped}', counts.skipped);
        setSummary(text, counts.fail > 0);
    };

    const gate = initSaveGate(strings, () => requiredTests().every(passedNow));

    // A result for values that have since changed no longer counts: show that it needs a re-run.
    const form = button.closest('form');
    const markChanged = () => {
        let changed = false;
        rows.forEach((row) => {
            if (['pass', 'fail', 'skipped'].includes(row.dataset.status) && row.dataset.sig !== signature(row.dataset.test)) {
                setRow(row, 'idle', strings.stale);
                changed = true;
            }
        });
        if (changed) {
            showSummary();
        }
    };
    if (form) {
        form.addEventListener('input', markChanged);
        form.addEventListener('change', markChanged);
    }

    button.addEventListener('click', async() => {
        const originalLabel = button.textContent;

        // Run only what has not passed for the current values; if everything has, re-check
        // all. Any AI check needs the server check, and the backend always tests the LLM.
        const required = requiredTests();
        let torun = required.filter((test) => !passedNow(test));
        if (!torun.length) {
            torun = required.slice();
        }
        if (torun.some((test) => AI_TESTS.includes(test))) {
            torun = [...new Set(['server', 'llm', ...torun])];
        }
        torun = ALL_TESTS.filter((test) => torun.includes(test));
        // The values this run tests, per check; edits made while it runs need another run.
        const sigs = {};
        torun.forEach((test) => {
            sigs[test] = signature(test);
        });

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
        torun.forEach((test) => setRow(rowFor(test), 'pending', rowFor(test).dataset.testing));
        if (!required.includes('fallback')) {
            setRow(rowFor('fallback'), 'skipped', strings.notneeded);
            rowFor('fallback').dataset.sig = '';
        }
        startRunning();

        try {
            const formData = new URLSearchParams();
            formData.append('sesskey', M.cfg.sesskey);
            formData.append('config', JSON.stringify(collectConfiguration()));
            formData.append('tests', torun.join(','));

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

            // Rows that were not run keep their earlier result.
            torun.forEach((test) => {
                const row = rowFor(test);
                const outcome = result.results[test];
                if (outcome) {
                    setRow(row, outcome.status, outcome.message);
                    row.dataset.sig = sigs[test];
                } else {
                    setRow(row, 'idle', '');
                }
            });
            showSummary();
        } catch (error) {
            // The request itself failed, so this run's results are unknown: put those rows back.
            torun.forEach((test) => setRow(rowFor(test), 'idle', ''));
            setSummary(error.message, true);
        } finally {
            gate.update();
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
