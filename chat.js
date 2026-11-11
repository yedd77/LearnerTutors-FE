document.addEventListener('DOMContentLoaded', function () {
    const editor = document.getElementById('ragassistant-question');
    const valueInput = document.getElementById('ragassistant-question-value');
    const menu = document.getElementById('ragassistant-command-menu');
    const toolInput = document.getElementById('ragassistant-tool-id');
    const question = document.getElementById('ragassistant-question');
    const transcript = document.getElementById('ragassistant-chat-transcript');
    const chatApp = editor ? editor.closest('.ragassistant-chat-app') : null;
    const form = editor ? editor.closest('form') : null;
    const sendButton = form ? form.querySelector('.ragassistant-send-button') : null;
    const sendButtonContent = sendButton ? sendButton.innerHTML : '';
    let activeLoadingMessage = null;
    let requestInProgress = false;
    let activeThinkingTimer = null;
    const MAXLEN = 4000;
    let mathJaxTypesetQueue = Promise.resolve();

    if (!editor) {
        return;
    }

    function removeSuggestions() {
        document.querySelectorAll('.ragassistant-suggestions').forEach(function (suggestions) {
            suggestions.remove();
        });
    }

    function activateChatBackground() {
        if (chatApp) {
            chatApp.classList.add('ragassistant-chat-active');
        }
    }

    // Clicking a suggestion sends that stored prompt through the normal chat flow.
    document.addEventListener('click', function (event) {
        const card = event.target.closest('.ragassistant-suggestion-card');
        if (!card || !form) {
            return;
        }

        const prompt = card.dataset.prompt || '';
        if (!prompt.trim()) {
            return;
        }

        if (toolInput) {
            toolInput.value = '';
        }
        editor.textContent = prompt;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        form.requestSubmit();
    });

    // Resolve with the MathJax object once it is loaded and initialised,
    // or null if it never shows up. Moodle loads MathJax asynchronously, so
    // window.MathJax can exist (as a config object) before the library is ready.
    function waitForMathJax(timeout) {
        return new Promise(function (resolve) {
            const started = Date.now();

            (function check() {
                const mj = window.MathJax;

                if (mj && typeof mj.typesetPromise === 'function' && mj.startup && mj.startup.promise) {
                    mj.startup.promise.then(function () { resolve(mj); }, function () { resolve(null); });
                    return;
                }
                if (mj && mj.Hub && typeof mj.Hub.Queue === 'function') {
                    resolve(mj); // MathJax v2.
                    return;
                }
                if (Date.now() - started > timeout) {
                    resolve(null);
                    return;
                }
                window.setTimeout(check, 200);
            })();
        });
    }

    function typesetMath(element) {
        if (!element) {
            return Promise.resolve();
        }

        // Use Moodle's filter event so filter_mathjaxloader can typeset
        // dynamically inserted filtered content.
        document.dispatchEvent(new CustomEvent('core_filters/contentUpdated', {
            detail: {
                nodes: [element]
            }
        }));

        mathJaxTypesetQueue = mathJaxTypesetQueue.then(async function () {
            const mj = await waitForMathJax(15000);

            if (!mj) {
                console.warn('RAG Assistant: MathJax is not available.');
                return;
            }

            if (typeof mj.typesetPromise === 'function') {
                await mj.typesetPromise([element]);
            } else {
                mj.Hub.Queue(['Typeset', mj.Hub, element]);
            }
        }).catch(function (error) {
            console.error('RAG Assistant MathJax rendering failed.', error);
        });

        return mathJaxTypesetQueue;
    }


    const commands = [
        { command: '/exam', id: 'exam_generation', color: '#1e88e5', label: 'Generate exam questions', description: 'Create exam-style questions from course materials.' },
        { command: '/notes', id: 'note_making', color: '#1e88e5', label: 'Make revision notes', description: 'Create structured notes for revision.' },
        { command: '/summarize', id: 'summarization', color: '#1e88e5', label: 'Summarize material', description: 'Summarize relevant course content.' },
        { command: '/flashcards', id: 'flashcards', color: '#1e88e5', label: 'Generate flashcards', description: 'Create question-and-answer cards.' },
        { command: '/explain', id: 'concept_explanation', color: '#1e88e5', label: 'Explain a concept', description: 'Explain a topic step by step.' },
        { command: '/quiz', id: 'practice_quiz', color: '#1e88e5', label: 'Practice quiz', description: 'Generate practice questions.' },
        { command: '/studyguide', id: 'study_guide', color: '#1e88e5', label: 'Build a study guide', description: 'Organize topics for revision.' },
        { command: '/extract', id: 'exact_text_extraction', color: '#1e88e5', label: 'Extract exact text', description: 'Find or quote wording from course material.' }
    ];
    let activeIndex = -1;
    let filtered = [];

    function createMessage(role, text, isHtml = false) {
        const message = document.createElement('div');

        message.className = 'ragassistant-message ragassistant-message-' + role;

        message.className = role === 'user'
            ? 'ragassistant-message ragassistant-message-user'
            : 'ragassistant-message ragassistant-message-assistant';

        if (role === 'assistant') {
            const avatar = document.createElement('div');
            avatar.className = 'ragassistant-assistant-avatar';
            avatar.textContent = '✦';
            message.appendChild(avatar);
        }

        const content = document.createElement('div');
        content.className = 'ragassistant-message-content';
        if (isHtml) {
            content.innerHTML = text;
        } else {
            content.textContent = text;
            if (role === 'user') {
                highlightMessageCommand(content);
            }
        }

        message.appendChild(content);
        transcript.appendChild(message);

        return message;
    }

    function centerMessage(message) {
        if (!message) return;

        message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function appendSources(content, sources) {
        if (!content || !Array.isArray(sources) || sources.length === 0) {
            return;
        }

        const details = document.createElement('details');
        details.className = 'ragassistant-sources-details';

        const summary = document.createElement('summary');
        summary.textContent = 'Sources';
        details.appendChild(summary);

        const list = document.createElement('ul');
        list.className = 'ragassistant-sources-list';

        sources.forEach(source => {
            const item = document.createElement('li');
            const title = document.createElement('strong');
            title.textContent = source.filename || 'Unknown Source';

            if (source.page) {
                title.textContent += ' (Page ' + source.page + ')';
            }

            item.appendChild(title);

            if (source.excerpt) {
                const excerpt = document.createElement('p');
                excerpt.textContent = source.excerpt;
                item.appendChild(excerpt);
            }

            list.appendChild(item);
        });
        details.appendChild(list);
        content.appendChild(details);
    }

    function appendIllustration(content, imageUrl) {
        if (!content || typeof imageUrl !== 'string' || imageUrl.trim() === '') {
            return;
        }

        let parsedUrl;
        try {
            parsedUrl = new URL(imageUrl, window.location.href);
        } catch (error) {
            console.warn('RAG Assistant: invalid illustration URL.');
            return;
        }

        if (parsedUrl.protocol !== 'https:' && parsedUrl.protocol !== 'http:') {
            console.warn('RAG Assistant: unsupported illustration URL protocol.');
            return;
        }

        const wrapper = document.createElement('figure');
        wrapper.className = 'ragassistant-illustration-wrap';

        const image = document.createElement('img');
        image.className = 'ragassistant-explain-illustration';
        image.src = parsedUrl.href;
        image.alt = 'Educational illustration for this explanation';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', function () {
            wrapper.remove();
        }, { once: true });

        const caption = document.createElement('figcaption');
        caption.textContent = 'Illustration';

        wrapper.append(image, caption);
        content.appendChild(wrapper);
    }

    function highlightMessageCommand(content) {
        const text = content.textContent;
        const lead = text.match(/^(\s*)(\/[^\s]*)(?=\s|$)/);
        const item = lead
            ? commands.find(function (command) {
                return command.command === lead[2].toLowerCase();
            })
            : null;

        if (!item) {
            return;
        }

        const mark = document.createElement('span');
        mark.className = 'ragassistant-command-mark';
        mark.dataset.cmd = item.command;
        mark.textContent = lead[2];
        mark.style.color = item.color;
        mark.style.backgroundColor = item.color + '26';
        mark.style.boxShadow = '0 0 0 2px ' + item.color + '26';

        content.replaceChildren(
            document.createTextNode(lead[1]),
            mark,
            document.createTextNode(text.slice(lead[0].length))
        );
    }

    document.querySelectorAll(
        '.ragassistant-message-user .ragassistant-message-content'
    ).forEach(highlightMessageCommand);

    document.querySelectorAll(
        '.ragassistant-message-assistant .ragassistant-message-content'
    ).forEach(typesetMath);


    //intercept the form submission to handle it via AJAX
    if (form && transcript) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            // Ignore keyboard or programmatic submissions while a request is running.
            if (requestInProgress) {
                return;
            }

            const originalQuestion = getText().trim();

            if (!originalQuestion || originalQuestion.length < 2) {
                question.focus();
                return;
            }

            // Treat the selected slash command as a tool selection,
            // not part of the actual question sent to the model.
            const selectedCommand = commands.find(item =>
                item.id === (toolInput ? toolInput.value : '') &&
                new RegExp(
                    '^' + item.command.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') +
                    '(?=\\s|$)',
                    'i'
                ).test(originalQuestion)
            );

            const cleanQuestion = selectedCommand
                ? originalQuestion.slice(selectedCommand.command.length).trim()
                : originalQuestion;

            if (cleanQuestion.length < 2) {
                question.focus();
                return;
            }

            requestInProgress = true;
            const formData = new FormData(form);
            formData.set('question', cleanQuestion);
            formData.set('display_question', originalQuestion);
            formData.set('ajax', '1');

            // Remove the welcome message when the first real question is sent.
            const welcome = transcript.querySelector(
                '.ragassistant-message-assistant'
            );

            if (transcript.children.length === 1 && welcome &&
                !welcome.querySelector('.ragassistant-sources-details')) {
                welcome.remove();
            }

            // Show the user's question immediately.
            const userMessage = createMessage('user', originalQuestion);
            centerMessage(userMessage);

            // Show a temporary loading indicator.
            const loadingMessage = createMessage('assistant', 'Thinking for 0s');
            activeLoadingMessage = loadingMessage;
            const loadingContent = loadingMessage.querySelector(
                '.ragassistant-message-content'
            );
            const thinkingStartedAt = Date.now();
            const thinkingTimer = window.setInterval(function () {
                const elapsedSeconds = Math.floor(
                    (Date.now() - thinkingStartedAt) / 1000
                );
                if (loadingContent) {
                    loadingContent.textContent = 'Thinking for ' +
                        elapsedSeconds + 's';
                }
            }, 1000);
            activeThinkingTimer = thinkingTimer;

            if (sendButton) {
                sendButton.disabled = true;
                sendButton.setAttribute('aria-label', 'Generating response');
                sendButton.setAttribute('aria-busy', 'true');
            }

            editor.textContent = '';
            editor.dispatchEvent(new Event('input', { bubbles: true }));

            if (toolInput) {
                toolInput.value = '';
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.error || 'The assistant could not answer.'
                    );
                }

                // Replace the loading indicator with the actual answer.
                loadingMessage.remove();
                activeLoadingMessage = null;

                const assistantMessage = createMessage(
                    'assistant',
                    result.answer_html || 'The assistant returned an empty answer.',
                    true
                );

                const content = assistantMessage.querySelector(
                    '.ragassistant-message-content'
                );

                appendIllustration(content, result.illustration_url);
                appendSources(content, result.sources);

                await typesetMath(content);

                // Keep the new answer in the center of the visible transcript.
                centerMessage(assistantMessage);

            } catch (error) {
                loadingMessage.remove();
                activeLoadingMessage = null;

                if (error.name !== 'AbortError') {
                    const errorMessage = createMessage('assistant', error.message || 'A connection error occurred.');
                    errorMessage.classList.add('ragassistant-message-error');
                    centerMessage(errorMessage);
                }

            } finally {
                window.clearInterval(thinkingTimer);
                activeThinkingTimer = null;

                if (sendButton) {
                    sendButton.disabled = false;
                    sendButton.innerHTML = sendButtonContent;
                    sendButton.setAttribute('aria-label', 'Send question');
                    sendButton.removeAttribute('aria-busy');
                }
                activeLoadingMessage = null;
                requestInProgress = false;
                editor.focus();
            }
        });
    }

    // ---- Text and caret helpers -------------------------------------------
    function getText() {
        return editor.textContent;
    }

    function getCaret() {
        const sel = window.getSelection();
        if (!sel.rangeCount || !editor.contains(sel.anchorNode)) {
            return null;
        }
        const range = sel.getRangeAt(0).cloneRange();
        const pre = range.cloneRange();
        pre.selectNodeContents(editor);
        pre.setEnd(range.endContainer, range.endOffset);
        return pre.toString().length;
    }

    function setCaret(offset) {
        const range = document.createRange();
        const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
        let remaining = offset;
        let node;
        while ((node = walker.nextNode())) {
            if (remaining <= node.length) {
                range.setStart(node, remaining);
                range.collapse(true);
                break;
            }
            remaining -= node.length;
        }
        if (!node) {
            range.selectNodeContents(editor);
            range.collapse(false);
        }
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    // ---- Highlighting ------------------------------------------------------
    // Colors a fully typed command at the start of the message and keeps tool_id in sync.
    function updateHighlight() {
        const text = getText();
        editor.dataset.empty = text === '' ? 'true' : 'false';

        const lead = text.match(/^(\s*)(\/[^\s]*)(?=\s|$)/);
        const item = lead ? commands.find(function (c) { return c.command === lead[2].toLowerCase(); }) : null;

        if (toolInput) {
            toolInput.value = item ? item.id : '';
        }

        // Skip the rebuild when the DOM already matches, so the caret is left alone.
        const marks = editor.querySelectorAll('.ragassistant-command-mark');
        if (!item && marks.length === 0) {
            return;
        }
        if (item && marks.length === 1 && marks[0].textContent === lead[2] && marks[0].dataset.cmd === item.command) {
            return;
        }

        const caret = document.activeElement === editor ? getCaret() : null;
        if (item) {
            const mark = document.createElement('span');
            mark.className = 'ragassistant-command-mark';
            mark.dataset.cmd = item.command;
            mark.textContent = lead[2];
            mark.style.color = item.color;
            mark.style.backgroundColor = item.color + '26';   // ~15% alpha
            mark.style.boxShadow = '0 0 0 2px ' + item.color + '26';
            editor.replaceChildren(
                document.createTextNode(lead[1]),
                mark,
                document.createTextNode(text.slice(lead[0].length))
            );
        } else {
            editor.replaceChildren(document.createTextNode(text));
        }
        if (caret !== null) {
            setCaret(caret);
        }
    }

    // ---- Command menu ------------------------------------------------------
    function closeMenu() {
        if (menu) {
            menu.hidden = true;
            menu.replaceChildren();
        }
        activeIndex = -1;
        filtered = [];
    }

    function chooseCommand(item) {
        const value = getText();
        const match = value.match(/(?:^|\s)(\/[^\s]*)$/);
        let next;
        if (match) {
            const start = match.index + (match[0].startsWith(' ') ? 1 : 0);
            next = value.slice(0, start) + item.command + ' ';
        } else {
            next = item.command + ' ';
        }
        editor.textContent = next;
        updateHighlight();
        closeMenu();
        editor.focus();
        setCaret(next.length);
    }

    function renderMenu() {
        if (!menu) return;
        const match = getText().match(/(?:^|\s)(\/[^\s]*)$/);
        if (!match) {
            closeMenu();
            return;
        }
        const token = match[1].toLowerCase();
        filtered = commands.filter(function (item) {
            return item.command.startsWith(token) || item.label.toLowerCase().includes(token.slice(1));
        });
        menu.replaceChildren();
        activeIndex = filtered.length ? 0 : -1;
        filtered.forEach(function (item, index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ragassistant-command-option';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            const title = document.createElement('span');
            title.className = 'ragassistant-command-title';
            title.textContent = item.command + ' · ' + item.label;
            const description = document.createElement('span');
            description.className = 'ragassistant-command-description';
            description.textContent = item.description;
            button.append(title, description);
            button.addEventListener('mousedown', function (event) { event.preventDefault(); });
            button.addEventListener('click', function () { chooseCommand(item); });
            menu.appendChild(button);
        });
        menu.hidden = filtered.length === 0;
    }

    // ---- Events ------------------------------------------------------------
    editor.addEventListener('input', function (event) {
        if (event.isComposing) return;          // don't touch the DOM mid-IME
        updateHighlight();
        renderMenu();
    });
    editor.addEventListener('compositionend', function () {
        updateHighlight();
        renderMenu();
    });

    // Enforce the 4000 character limit (a div has no maxlength).
    editor.addEventListener('beforeinput', function (event) {
        if (!event.inputType.startsWith('insert') || event.inputType === 'insertCompositionText') return;
        const incoming = event.data ? event.data.length : (event.dataTransfer ? event.dataTransfer.getData('text').length : 0);
        const sel = window.getSelection();
        const selected = sel.rangeCount ? sel.toString().length : 0;
        if (getText().length - selected + incoming > MAXLEN) {
            event.preventDefault();
        }
    });

    editor.addEventListener('keydown', function (event) {
        if (menu && !menu.hidden && filtered.length && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            activeIndex = event.key === 'ArrowDown'
                ? (activeIndex + 1) % filtered.length
                : (activeIndex - 1 + filtered.length) % filtered.length;
            Array.from(menu.children).forEach(function (option, index) {
                option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            });
            return;
        }
        if (menu && !menu.hidden && event.key === 'Escape') {
            closeMenu();
            return;
        }
        if (menu && !menu.hidden && event.key === 'Enter' && !event.shiftKey && filtered.length) {
            event.preventDefault();
            removeSuggestions();
            activateChatBackground();
            chooseCommand(filtered[Math.max(activeIndex, 0)]);
            return;
        }
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();               // Shift+Enter still inserts a newline
        form.requestSubmit();
    });

    // Copy the editor text into the hidden input that PHP reads.
    form.addEventListener('submit', function (event) {
        const text = getText().trim();
        if (text.length < 2) {
            event.preventDefault();
            editor.focus();
            return;
        }
        removeSuggestions();
        activateChatBackground();
        valueInput.value = text;
    });

    editor.addEventListener('blur', function () {
        window.setTimeout(closeMenu, 120);
    });
    editor.addEventListener('focus', renderMenu);

    updateHighlight();   // highlight a prefilled question after a validation error
});