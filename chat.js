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
    const toolsButton = document.getElementById('ragassistant-tools-button');
    const charCount = document.getElementById('ragassistant-char-count');
    // True while the command menu was opened with the Study tools button rather than
    // by typing "/"; the chosen command then goes in front of the text already typed.
    let menuFromButton = false;
    let activeLoadingMessage = null;
    let requestInProgress = false;
    let runChatRequest = null;   // set below once the composer form is wired up
    let activeThinkingTimer = null;
    const MAXLEN = 4000;

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
            ensureNewChatButton();
        }
    }

    function ensureNewChatButton() {
        if (!chatApp || chatApp.querySelector('.ragassistant-new-chat-form')) {
            return;
        }

        const newChatForm = document.createElement('form');
        newChatForm.method = 'post';
        newChatForm.action = form.action;
        newChatForm.className = 'ragassistant-new-chat-form';

        const sesskeyInput = form.querySelector('input[name="sesskey"]');
        if (sesskeyInput) {
            const sesskey = document.createElement('input');
            sesskey.type = 'hidden';
            sesskey.name = 'sesskey';
            sesskey.value = sesskeyInput.value;
            newChatForm.appendChild(sesskey);
        }

        const clearInput = document.createElement('input');
        clearInput.type = 'hidden';
        clearInput.name = 'clear_history';
        clearInput.value = '1';
        newChatForm.appendChild(clearInput);

        const button = document.createElement('button');
        button.type = 'submit';
        button.className = 'ragassistant-new-chat-btn';
        button.title = 'Start a new conversation';

        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '+';

        button.append(icon, document.createTextNode(' New chat'));
        newChatForm.appendChild(button);

        chatApp.prepend(newChatForm);
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

        removeSuggestions();
        activateChatBackground();

        if (toolInput) {
            toolInput.value = '';
        }
        editor.textContent = prompt;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        form.requestSubmit();
    });

    // Hand an answer inserted by AJAX to Moodle's MathJax filter. The server already ran
    // the answer through format_text(), which wraps each equation in
    // .filter_mathjaxloader_equation; filter_mathjaxloader loads MathJax on demand and
    // typesets those spans when it receives this event. Answers without equations are
    // skipped: Moodle never loads MathJax for them, so there is nothing to wait for.
    // (Equations in the server-rendered history are typeset by the filter on page load.)
    function typesetMath(element) {
        if (!element || !element.querySelector('.filter_mathjaxloader_equation')) {
            return;
        }

        document.dispatchEvent(new CustomEvent('core_filters/contentUpdated', {
            detail: {
                nodes: [element]
            }
        }));
    }


    const commands = [
        { command: '/exam', id: 'exam_generation', color: '#270089', label: 'Generate exam questions', description: 'Create exam-style questions from course materials.' },
        { command: '/notes', id: 'note_making', color: '#270089', label: 'Make revision notes', description: 'Create structured notes for revision.' },
        { command: '/summarize', id: 'summarization', color: '#270089', label: 'Summarize material', description: 'Summarize relevant course content.' },
        { command: '/flashcards', id: 'flashcards', color: '#270089', label: 'Generate flashcards', description: 'Create question-and-answer cards.' },
        { command: '/explain', id: 'concept_explanation', color: '#270089', label: 'Explain a concept', description: 'Explain a topic step by step.' },
        { command: '/quiz', id: 'practice_quiz', color: '#270089', label: 'Practice quiz', description: 'Generate practice questions.' },
        { command: '/studyguide', id: 'study_guide', color: '#270089', label: 'Build a study guide', description: 'Organize topics for revision.' }
    ].filter(function (item) {
        // Commands the course turned off (for example /exam) are left out of the menu.
        // index.php refuses them too, so this only keeps the menu honest.
        const disabled = chatApp && chatApp.dataset.disabledCommands
            ? chatApp.dataset.disabledCommands.split(',')
            : [];
        return disabled.indexOf(item.id) === -1;
    });
    let activeIndex = -1;
    let filtered = [];

    function createMessage(role, text, isHtml = false) {
        const message = document.createElement('div');

        message.className = 'ragassistant-message ragassistant-message-' + role;

        message.className = role === 'user'
            ? 'ragassistant-message ragassistant-message-user'
            : 'ragassistant-message ragassistant-message-assistant';

        if (role === 'assistant') {
            // Header: decorative avatar and the assistant's name, above the answer.
            const header = document.createElement('div');
            header.className = 'ragassistant-assistant-header';
            const avatar = document.createElement('div');
            avatar.className = 'ragassistant-assistant-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = '✦';
            const name = document.createElement('div');
            name.className = 'ragassistant-assistant-name';
            name.textContent = transcript.dataset.assistantName || 'LearnerTutors';
            header.append(avatar, name);
            message.appendChild(header);
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

    // The user's latest question, kept near the top of the view while its answer arrives.
    let pinnedMessage = null;

    // The nearest scrolling ancestor of the chat (Moodle's page container or the window).
    function pageScroller() {
        let node = chatApp ? chatApp.parentElement : null;
        while (node && node !== document.body) {
            const overflowY = getComputedStyle(node).overflowY;
            if ((overflowY === 'auto' || overflowY === 'scroll') &&
                    node.scrollHeight > node.clientHeight) {
                return node;
            }
            node = node.parentElement;
        }
        return document.scrollingElement || document.documentElement;
    }

    // Hand wheel scrolling over to the page once the transcript reaches its start or end.
    // Chrome "latches" one continuous wheel gesture to the element it started on, so
    // without this the page only scrolls after the user pauses or moves the mouse.
    if (transcript) {
        let pageScrollTarget = null;

        transcript.addEventListener('wheel', function (event) {
            if (event.ctrlKey || event.defaultPrevented ||
                    Math.abs(event.deltaX) > Math.abs(event.deltaY) || event.deltaY === 0) {
                return;   // pinch-zoom, horizontal or empty scrolls: leave to the browser
            }

            // A short transcript does not scroll, and the browser already passes those
            // scrolls to the page.
            const maxTranscriptTop = transcript.scrollHeight - transcript.clientHeight;
            if (maxTranscriptTop <= 1) {
                return;
            }

            const goingUp = event.deltaY < 0;
            const atStart = transcript.scrollTop <= 0;
            const atEnd = transcript.scrollTop >= maxTranscriptTop - 1;
            if ((goingUp && !atStart) || (!goingUp && !atEnd)) {
                return;   // the transcript still has room: let the browser scroll it
            }

            const scroller = pageScroller();
            let delta = event.deltaY;
            if (event.deltaMode === 1) {
                delta *= 16;                       // lines -> pixels
            } else if (event.deltaMode === 2) {
                delta *= scroller.clientHeight;    // pages -> pixels
            }

            const maxPageTop = scroller.scrollHeight - scroller.clientHeight;
            const current = scroller.scrollTop;
            if ((goingUp && current <= 0) || (!goingUp && current >= maxPageTop - 1)) {
                return;   // the page is at its own limit too: nothing to do
            }

            event.preventDefault();

            // Keep adding to the target while a smooth scroll is still running, so quick
            // wheel ticks add up instead of each restarting from the current position.
            const base = pageScrollTarget !== null && Math.abs(current - pageScrollTarget) > 1
                ? pageScrollTarget
                : current;
            pageScrollTarget = Math.min(maxPageTop, Math.max(0, base + delta));
            scroller.scrollTo({ top: pageScrollTarget, behavior: 'smooth' });
        }, { passive: false });
    }

    // Top edge of the part of the transcript the user can currently see: below the New
    // chat button (the transcript's top padding), and below Moodle's fixed navbar when
    // the top of the chat is scrolled off the screen.
    function visibleTranscriptTop() {
        const rect = transcript.getBoundingClientRect();
        const paddingTop = parseFloat(getComputedStyle(transcript).paddingTop) || 0;
        const navbar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top');
        const screenTop = (navbar ? navbar.getBoundingClientRect().bottom : 0) + 8;
        // Never aim below the transcript itself, even if most of the chat is off-screen.
        return Math.min(Math.max(rect.top + paddingTop, screenTop), rect.bottom - 48);
    }

    // Scroll the transcript (never the page) so the message's top lines up with the top
    // of the current view, as far as the content allows: with a short answer the view
    // simply stops at the end of the conversation, so no blank space is added.
    function scrollMessageToViewTop(message) {
        if (!message || !transcript || !message.isConnected) return;

        const offset = message.getBoundingClientRect().top - visibleTranscriptTop();
        const maxScrollTop = transcript.scrollHeight - transcript.clientHeight;
        const top = Math.min(maxScrollTop, Math.max(0, transcript.scrollTop + offset));
        transcript.scrollTo({ top: top, behavior: 'smooth' });
    }

    // Jump straight to the latest message (used on page load).
    function scrollTranscriptToEnd() {
        if (transcript) {
            transcript.scrollTo({ top: transcript.scrollHeight, behavior: 'instant' });
        }
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

    // At most this many figures are shown per answer; further markers are removed.
    const MAX_FIGURES_PER_ANSWER = 2;

    // Figures from course documents (the chat response's optional "images" list). Each
    // "[IMGn]" marker in the rendered answer is replaced with its figure (at most
    // MAX_FIGURES_PER_ANSWER); figures the answer did not place are not shown. Images load through the Moodle proxy
    // (document_image.php), never from the backend. Caption, filename and page are document
    // text, so they are only ever set with textContent.
    const FIGURE_SKIP = 'pre, code, .ragassistant-sources-details, .ragassistant-illustration-wrap, ' +
        '.ragassistant-download, .local-ragassistant-figure';

    function figureUrl(image) {
        const proxyUrl = chatApp ? chatApp.dataset.figureUrl : '';
        if (!proxyUrl || !/^[0-9a-f]{32}$/.test(String(image.image_id || '')) ||
                !/^[1-9]\d{0,9}$/.test(String(image.document_id || ''))) {
            return '';
        }
        try {
            const url = new URL(proxyUrl, window.location.href);
            url.searchParams.set('id', image.image_id);
            url.searchParams.set('documentid', image.document_id);
            return url.href;
        } catch (error) {
            return '';
        }
    }

    // A figure much taller than it is wide is shown turned 90° clockwise, so it fits
    // the answer as a landscape image. The image comes from our own proxy (same
    // origin), so it can be redrawn on a canvas. If that fails, the original stays.
    const TALL_FIGURE_RATIO = 1.4;

    function rotateIfTall(img) {
        const width = img.naturalWidth;
        const height = img.naturalHeight;
        if (!width || !height || height < width * TALL_FIGURE_RATIO) {
            return;
        }
        try {
            const canvas = document.createElement('canvas');
            canvas.width = height;
            canvas.height = width;
            const context = canvas.getContext('2d');
            context.translate(height, 0);
            context.rotate(Math.PI / 2);
            context.drawImage(img, 0, 0, width, height);
            canvas.toBlob(function (blob) {
                if (!blob) {
                    return;
                }
                const rotatedUrl = URL.createObjectURL(blob);
                img.addEventListener('load', function () {
                    URL.revokeObjectURL(rotatedUrl);
                }, { once: true });
                const figure = img.closest('.local-ragassistant-figure');
                if (figure) {
                    figure.classList.add('local-ragassistant-figure-rotated');
                }
                img.src = rotatedUrl;
            });
        } catch (e) {
            // Keep the unrotated image.
        }
    }

    function createFigure(image) {
        const href = figureUrl(image);
        if (!href) {
            return null;
        }
        const filename = String(image.filename || '');
        const caption = String(image.caption || '');
        const page = Number(image.page) > 0 ? Number(image.page) : 0;

        const figure = document.createElement('figure');
        figure.className = 'local-ragassistant-figure';

        // Clicking the figure opens it full size in a new tab, through the same proxy.
        const link = document.createElement('a');
        link.href = href;
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = str('figureopen', null, 'Open the full-size figure in a new tab');

        const img = document.createElement('img');
        img.src = href;
        img.alt = caption || (filename ? str('figurealt', filename, 'Figure from ' + filename) : '');
        img.loading = 'lazy';
        img.decoding = 'async';
        // Deleted, disabled or re-indexed documents: a short note instead of a broken image.
        img.addEventListener('error', function () {
            const note = document.createElement('p');
            note.className = 'local-ragassistant-figure-unavailable';
            note.textContent = str('figureunavailable', null, 'This figure is no longer available.');
            figure.replaceWith(note);
        }, { once: true });
        img.addEventListener('load', function () {
            rotateIfTall(img);
        }, { once: true });
        link.appendChild(img);
        figure.appendChild(link);

        const source = filename && page
            ? str('figuresourcepage', { filename: filename, page: page }, filename + ', p. ' + page)
            : filename;
        const text = [caption, source].filter(Boolean).join(' · ');
        if (text) {
            const figcaption = document.createElement('figcaption');
            figcaption.textContent = text;
            figure.appendChild(figcaption);
        }
        return figure;
    }

    // A figure cannot sit inside a paragraph: split the paragraph around it and drop
    // the parts left empty (a marker on its own line leaves nothing else).
    function liftOutOfParagraph(figure) {
        const paragraph = figure.parentElement;
        if (!paragraph || paragraph.tagName !== 'P') {
            return;
        }
        const after = document.createElement('p');
        while (figure.nextSibling) {
            after.appendChild(figure.nextSibling);
        }
        paragraph.after(figure);
        figure.after(after);
        [paragraph, after].forEach(function (part) {
            if (part.textContent.trim() === '' && !part.querySelector(':not(br)')) {
                part.remove();
            }
        });
    }

    function appendFigures(content, images) {
        if (!content || !Array.isArray(images) || images.length === 0) {
            return;
        }

        const byMarker = new Map();
        images.forEach(function (image) {
            if (image && typeof image.marker === 'string') {
                byMarker.set(image.marker, image);
            }
        });

        // Collect first, then replace, so the walker is not disturbed.
        const nodes = [];
        const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (/\[IMG\d{1,3}\]/.test(node.nodeValue) && !node.parentElement.closest(FIGURE_SKIP)) {
                nodes.push(node);
            }
        }

        const used = new Set();
        const placed = [];
        nodes.forEach(function (node) {
            const fragment = document.createDocumentFragment();
            const text = node.nodeValue;
            let last = 0;
            for (const match of text.matchAll(/\[(IMG\d{1,3})\]/g)) {
                fragment.append(text.slice(last, match.index));
                last = match.index + match[0].length;
                const image = byMarker.get(match[1]);
                // Each figure once, at most MAX_FIGURES_PER_ANSWER; markers without a figure
                // (or over the limit) are removed, never shown raw.
                if (image && !used.has(match[1]) && placed.length < MAX_FIGURES_PER_ANSWER) {
                    const figure = createFigure(image);
                    if (figure) {
                        used.add(match[1]);
                        placed.push(figure);
                        fragment.append(figure);
                    }
                }
            }
            fragment.append(text.slice(last));
            node.replaceWith(fragment);
        });
        placed.forEach(liftOutOfParagraph);

        // Figures the answer did not place are left out: the model places a marker only
        // where the figure is relevant (backend prompts/core/figure_reminder.md), so an
        // unplaced figure is one it judged not to fit the question.
    }

    // Language strings passed from PHP with strings_for_js (English fallback just in case).
    function str(id, a, fallback) {
        try {
            if (window.M && M.util && M.util.get_string) {
                const value = M.util.get_string(id, 'local_ragassistant', a);
                if (value && value.indexOf('[[') !== 0) {
                    return value;
                }
            }
        } catch (error) {
            // Fall through to the fallback text.
        }
        return fallback;
    }

    function isExpired(expiresAt) {
        return Number(expiresAt) > 0 && Date.now() / 1000 > Number(expiresAt);
    }

    function showNotice(container, text, isError) {
        let notice = container.querySelector('.ragassistant-action-notice');
        if (!notice) {
            notice = document.createElement('p');
            notice.className = 'ragassistant-action-notice';
            notice.setAttribute('role', 'status');
            container.appendChild(notice);
        }
        notice.classList.toggle('text-danger', !!isError);
        notice.textContent = text;
    }

    // "Download PDF" button for a /notes study sheet. The file is fetched through the
    // Moodle proxy (download_sheet.php), which adds the API key and the user's id on the
    // server. 404/410 show an inline "expired" notice instead of a broken download.
    function appendDownload(content, download) {
        if (!content || !download || typeof download !== 'object' ||
                !/^[0-9a-f]{32}$/.test(String(download.file_id || ''))) {
            return;
        }

        const proxyUrl = chatApp ? chatApp.dataset.downloadUrl : '';
        const sesskeyInput = form ? form.querySelector('input[name="sesskey"]') : null;
        const filename = String(download.filename || 'study_sheet.pdf');
        const expiredText = str('downloadexpired', null,
            'This study sheet has expired. Send /notes again to create a new one.');

        const box = document.createElement('div');
        box.className = 'ragassistant-download';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary ragassistant-download-btn';
        button.setAttribute('aria-label', str('downloadpdfaria', filename, 'Download PDF: ' + filename));
        if (chatApp && chatApp.dataset.pdfIcon) {
            const icon = document.createElement('img');
            icon.className = 'ragassistant-download-icon';
            icon.src = chatApp.dataset.pdfIcon;
            icon.alt = '';
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);
        }
        const label = document.createElement('span');
        label.textContent = str('downloadpdf', null, 'Download PDF');
        button.appendChild(label);

        const meta = document.createElement('p');
        meta.className = 'ragassistant-download-meta';
        const expiresAt = Number(download.expires_at) || 0;
        const until = expiresAt > 0
            ? new Date(expiresAt * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            : '';
        meta.textContent = (until ? str('downloadavailableuntil', until, 'Available until ' + until) + ' · ' : '') +
            filename;

        box.append(button, meta);
        content.appendChild(box);

        function markExpired() {
            button.disabled = true;
            button.removeAttribute('aria-busy');
            showNotice(box, expiredText, false);
        }

        if (isExpired(expiresAt) || !proxyUrl || !sesskeyInput) {
            markExpired();
            return;
        }

        button.addEventListener('click', async function () {
            if (isExpired(expiresAt)) {
                markExpired();
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            label.textContent = str('downloadbusy', null, 'Downloading…');

            try {
                const url = new URL(proxyUrl, window.location.href);
                url.searchParams.set('id', download.file_id);
                url.searchParams.set('sesskey', sesskeyInput.value);
                const response = await fetch(url.href, { credentials: 'same-origin' });

                if (response.status === 404 || response.status === 410) {
                    markExpired();
                    return;
                }
                if (!response.ok) {
                    let message = str('downloadfailed', null,
                        'The download did not complete. Check your connection and try again.');
                    try {
                        const data = await response.json();
                        if (data && typeof data.message === 'string') {
                            message = data.message;
                        }
                    } catch (error) {
                        // Not JSON: keep the generic message.
                    }
                    const serverError = new Error(message);
                    serverError.fromServer = true;
                    throw serverError;
                }

                const blob = await response.blob();
                const objectUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 10000);

                const notice = box.querySelector('.ragassistant-action-notice');
                if (notice) {
                    notice.remove();
                }
                button.disabled = false;
            } catch (error) {
                // Show the proxy's own message when it sent one; otherwise (a network
                // error such as "Failed to fetch") show the friendly retry text.
                showNotice(box, error && error.fromServer ? error.message : str('downloadfailed', null,
                    'The download did not complete. Check your connection and try again.'), true);
                button.disabled = false;
            } finally {
                button.removeAttribute('aria-busy');
                label.textContent = str('downloadpdf', null, 'Download PDF');
            }
        });
    }

    // Yes/No buttons for a backend approve/decline question (/notes "search more widely?",
    // /quiz "broden the topic?"). A click sends "yes" or "no", exactly as if typed, so
    // index.php answers it with the token it keeps in the session. Any later request
    // disables the buttons, because the question can only be answered once.
    function appendDecision(content, pending) {
        if (!content || !pending || typeof pending !== 'object') {
            return;
        }
        const box = document.createElement('div');
        box.className = 'ragassistant-decision';

        const buttons = document.createElement('div');
        buttons.className = 'ragassistant-decision-buttons';
        [
            ['yes', String(pending.approve_label || '') || str('decisionapprove', null, 'Yes'), 'btn btn-primary'],
            ['no', String(pending.decline_label || '') || str('decisiondecline', null, 'No'), 'btn btn-outline-primary'],
        ].forEach(function ([reply, label, classes]) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = classes + ' ragassistant-decision-btn';
            button.textContent = label;
            button.addEventListener('click', function () {
                if (requestInProgress || typeof runChatRequest !== 'function') {
                    return;
                }
                runChatRequest(reply, label, {});
            });
            buttons.appendChild(button);
        });

        const hint = document.createElement('p');
        hint.className = 'ragassistant-decision-hint';
        hint.textContent = str('decisionhint', null, 'You can also type “yes” or “no”.');

        box.append(buttons, hint);
        content.appendChild(box);
    }

    function disableDecisions() {
        document.querySelectorAll('.ragassistant-decision-btn').forEach(function (button) {
            button.disabled = true;
        });
        document.querySelectorAll('.ragassistant-decision-hint').forEach(function (hint) {
            hint.remove();
        });
    }

    function shuffled(list) {
        const copy = list.slice();
        for (let i = copy.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [copy[i], copy[j]] = [copy[j], copy[i]];
        }
        return copy;
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    const QUIZ_LETTERS = ['A', 'B', 'C', 'D'];

    // /quiz multiple-choice card: one question at a time, feedback after each answer,
    // then the score and Retry (same questions, new order). After every
    // step the attempt state is saved in the chat session (quiz_progress.php), and
    // `saved` restores it after a reload. All quiz text is set with textContent.
    function appendQuiz(content, quiz, saved) {
        if (!content || !quiz || !Array.isArray(quiz.questions) || !quiz.questions.length) {
            return;
        }
        const questions = quiz.questions;
        const total = questions.length;

        const section = el('section', 'ragassistant-quiz');
        section.setAttribute('aria-label', str('quizaria', null, 'Practice quiz'));

        const header = el('div', 'ragassistant-quiz-header');
        const titleWrap = el('div', 'ragassistant-quiz-title-wrap');
        if (quiz.title) {
            titleWrap.append(el('span', 'ragassistant-quiz-title', String(quiz.title)));
        }
        const meta = el('span', 'ragassistant-quiz-meta');
        header.append(titleWrap, meta);

        const progress = el('div', 'ragassistant-quiz-progress');
        progress.setAttribute('aria-hidden', 'true');
        const segments = questions.map(function () {
            const segment = el('span', 'ragassistant-quiz-segment');
            progress.appendChild(segment);
            return segment;
        });

        const body = el('div', 'ragassistant-quiz-body');
        section.append(header, progress, body);
        content.appendChild(section);

        // order: question indices in the order asked; optionOrder[q]: option indices shown.
        let order, optionOrder, position, picked, checked, answers;

        // Save the whole attempt state; a failed save only means a reload starts over.
        function save() {
            const url = chatApp ? chatApp.dataset.quizProgressUrl : '';
            const sesskeyInput = form ? form.querySelector('input[name="sesskey"]') : null;
            if (!url || !sesskeyInput || !/^[A-Za-z0-9]{16}$/.test(String(quiz.id || ''))) {
                return;
            }
            const data = new FormData();
            data.set('sesskey', sesskeyInput.value);
            data.set('quizid', quiz.id);
            data.set('progress', JSON.stringify({
                order: order,
                optionorder: optionOrder,
                position: position,
                picked: picked,
                checked: checked,
                // Sparse array → explicit nulls, so indices stay per question.
                answers: Array.from(questions.keys(), i => answers[i] === undefined ? null : answers[i]),
            }));
            fetch(url, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true })
                .catch(function () {
                    // Ignore: the quiz keeps working, only the saved state is older.
                });
        }

        function start(shuffle) {
            const indices = Array.from(questions.keys());
            order = shuffle ? shuffled(indices) : indices;
            optionOrder = questions.map(() => shuffle ? shuffled([0, 1, 2, 3]) : [0, 1, 2, 3]);
            position = 0;
            picked = null;
            checked = false;
            answers = [];
            render();
            if (shuffle) {
                save();
            }
        }

        // Continue a saved attempt (validated again by index.php before it reached the page).
        function restore(state) {
            if (!state || !Array.isArray(state.order) || state.order.length !== total ||
                    !Array.isArray(state.optionorder) || state.optionorder.length !== total) {
                return false;
            }
            order = state.order;
            optionOrder = state.optionorder;
            position = Math.min(Math.max(Number(state.position) || 0, 0), total);
            picked = Number.isInteger(state.picked) ? state.picked : null;
            checked = state.checked === true && picked !== null;
            answers = [];
            (Array.isArray(state.answers) ? state.answers : []).forEach(function (answer, i) {
                if (Number.isInteger(answer)) {
                    answers[i] = answer;
                }
            });
            render(false, true);
            return true;
        }

        function isRight(questionIndex) {
            return answers[questionIndex] === questions[questionIndex].answer_index;
        }

        function score() {
            return Array.from(questions.keys()).filter(isRight).length;
        }

        function optionLabel(questionIndex, optionIndex) {
            const shown = optionOrder[questionIndex].indexOf(optionIndex);
            return QUIZ_LETTERS[shown] + '. ' + questions[questionIndex].options[optionIndex];
        }

        function updateProgress() {
            segments.forEach(function (segment, i) {
                const questionIndex = order[i];
                segment.className = 'ragassistant-quiz-segment';
                if (answers[questionIndex] !== undefined) {
                    segment.classList.add(isRight(questionIndex) ? 'is-right' : 'is-wrong');
                } else if (i === position && position < total) {
                    segment.classList.add('is-current');
                }
            });
            const answered = answers.filter(a => a !== undefined).length;
            meta.textContent = position >= total
                ? str('quizfinished', null, 'Finished')
                : str('quizscoresofar', { score: score(), answered: answered },
                    'Score so far: ' + score() + ' / ' + answered);
        }

        function renderQuestion(focusHeading) {
            const questionIndex = order[position];
            const item = questions[questionIndex];

            const label = el('p', 'ragassistant-quiz-count', str('quizquestionof',
                { current: position + 1, total: total },
                'Question ' + (position + 1) + ' of ' + total));
            const heading = el('h3', 'ragassistant-quiz-question', item.question);
            heading.tabIndex = -1;

            const group = el('div', 'ragassistant-quiz-options');
            group.setAttribute('role', 'group');
            group.setAttribute('aria-label', str('quizoptions', null, 'Answer options'));
            optionOrder[questionIndex].forEach(function (optionIndex, shown) {
                const button = el('button', 'ragassistant-quiz-option');
                button.type = 'button';
                button.setAttribute('aria-pressed', picked === optionIndex ? 'true' : 'false');
                button.append(el('span', 'ragassistant-quiz-letter', QUIZ_LETTERS[shown]),
                    el('span', 'ragassistant-quiz-option-text', item.options[optionIndex]));
                if (checked) {
                    button.disabled = true;
                    if (optionIndex === item.answer_index) {
                        button.classList.add('is-right');
                        button.append(el('span', 'ragassistant-quiz-mark', '✓'));
                    } else if (optionIndex === picked) {
                        button.classList.add('is-wrong');
                        button.append(el('span', 'ragassistant-quiz-mark', '✗'));
                    }
                } else if (picked === optionIndex) {
                    button.classList.add('is-picked');
                }
                button.addEventListener('click', function () {
                    if (!checked) {
                        picked = optionIndex;
                        render();
                        save();
                        const again = body.querySelectorAll('.ragassistant-quiz-option')[shown];
                        if (again) {
                            again.focus();
                        }
                    }
                });
                group.appendChild(button);
            });

            const actions = el('div', 'ragassistant-quiz-actions');
            const parts = [label, heading, group];

            if (checked) {
                const right = picked === item.answer_index;
                const feedback = el('div', 'ragassistant-quiz-feedback ' + (right ? 'is-right' : 'is-wrong'));
                feedback.setAttribute('role', 'status');
                feedback.append(el('p', 'ragassistant-quiz-feedback-title', right
                    ? str('quizcorrect', null, 'Correct!')
                    : str('quizincorrect', optionLabel(questionIndex, item.answer_index),
                        'Not quite — the answer is ' + optionLabel(questionIndex, item.answer_index) + '.')));
                if (item.explanation) {
                    feedback.append(el('p', 'ragassistant-quiz-explanation', item.explanation));
                }
                if (item.source) {
                    feedback.append(el('p', 'ragassistant-quiz-source',
                        str('quizsource', item.source, 'Source: ' + item.source)));
                }
                parts.push(feedback);

                const last = position === total - 1;
                const next = el('button', 'btn btn-primary ragassistant-quiz-btn', last
                    ? str('quizseescore', null, 'See my score')
                    : str('quiznext', null, 'Next question'));
                next.type = 'button';
                next.addEventListener('click', function () {
                    position++;
                    picked = null;
                    checked = false;
                    render(true);
                    save();
                });
                actions.appendChild(next);
            } else {
                const check = el('button', 'btn btn-primary ragassistant-quiz-btn',
                    str('quizcheck', null, 'Check answer'));
                check.type = 'button';
                check.disabled = picked === null;
                check.addEventListener('click', function () {
                    if (picked === null) {
                        return;
                    }
                    answers[questionIndex] = picked;
                    checked = true;
                    render();
                    save();
                    const nextButton = body.querySelector('.ragassistant-quiz-actions .btn');
                    if (nextButton) {
                        nextButton.focus();
                    }
                });
                actions.appendChild(check);
            }
            parts.push(actions);
            body.replaceChildren(...parts);
            if (focusHeading) {
                heading.focus();
            }
        }

        function renderResults() {
            const points = score();
            const percent = Math.round(points / total * 100);
            const verdict = percent >= 90 ? str('quizverdictexcellent', null, 'Excellent work!')
                : percent >= 70 ? str('quizverdictgood', null, 'Good job!')
                    : percent >= 50 ? str('quizverdictclose', null, 'Getting there')
                        : str('quizverdictpractise', null, 'Keep practising');

            const summary = el('div', 'ragassistant-quiz-summary');
            // The ring's colour follows the same bands as the verdict (styles.css).
            const ring = el('div', 'ragassistant-quiz-ring ' + (percent >= 70 ? 'is-good'
                : percent >= 50 ? 'is-close' : 'is-low'));
            ring.setAttribute('role', 'img');
            ring.setAttribute('aria-label', str('quizscorearia', { score: points, total: total },
                'Score ' + points + ' out of ' + total));
            ring.style.setProperty('--ragassistant-quiz-pct', percent + '%');
            const ringInner = el('div', 'ragassistant-quiz-ring-inner');
            ringInner.setAttribute('aria-hidden', 'true');
            ringInner.append(el('span', 'ragassistant-quiz-score', String(points)),
                el('span', 'ragassistant-quiz-total', ' / ' + total));
            ring.appendChild(ringInner);

            const words = el('div', 'ragassistant-quiz-verdict-wrap');
            const verdictHeading = el('h3', 'ragassistant-quiz-verdict', verdict);
            verdictHeading.tabIndex = -1;
            words.append(verdictHeading, el('p', 'ragassistant-quiz-percent', str('quizpercent', percent,
                percent + '% correct. Review the ones you missed, then try again.')));
            summary.append(ring, words);

            const actions = el('div', 'ragassistant-quiz-actions');
            const retry = el('button', 'btn btn-primary ragassistant-quiz-btn',
                str('quizretry', null, 'Retry quiz'));
            retry.type = 'button';
            retry.addEventListener('click', function () {
                start(true);
                const heading = body.querySelector('.ragassistant-quiz-question');
                if (heading) {
                    heading.focus();
                }
            });
            actions.appendChild(retry);

            body.replaceChildren(summary, actions);
            if (!restoring) {
                verdictHeading.focus();
            }
        }

        // restoring: drawing a saved attempt on page load, so focus is left alone.
        let restoring = false;
        function render(focusHeading, isRestore) {
            restoring = !!isRestore;
            updateProgress();
            if (position >= total) {
                renderResults();
            } else {
                renderQuestion(focusHeading);
            }
            restoring = false;
            typesetMath(body);
        }

        if (!restore(saved)) {
            start(false);
        }
    }

    // Saved quizzes in the server-rendered history, continued where the student left them.
    document.querySelectorAll('.ragassistant-message-assistant[data-quiz]').forEach(function (message) {
        let quiz = null;
        let saved = null;
        try {
            quiz = JSON.parse(message.dataset.quiz);
            saved = message.dataset.quizProgress ? JSON.parse(message.dataset.quizProgress) : null;
        } catch (error) {
            return;
        }
        appendQuiz(message.querySelector('.ragassistant-message-content'), quiz, saved);
    });

    // /flashcards deck: one card at a time, question on the front, answer on the back.
    // The card itself is the only flip control (click, Enter or Space). The row below it
    // moves between cards (or the arrow keys); a new card always opens on its question, and
    // on the last card "Next" becomes "Start again". All text is set with textContent.
    function appendFlashcards(content, deck) {
        if (!content || !deck || !Array.isArray(deck.cards) || !deck.cards.length) {
            return;
        }
        const cards = deck.cards;
        const total = cards.length;
        let index = 0;
        let flipped = false;
        const seen = new Set([0]);

        const section = el('section', 'ragassistant-flashcards');
        section.setAttribute('aria-label', str('flashcardsaria', null, 'Flashcards'));
        if (deck.title) {
            section.append(el('p', 'ragassistant-flashcards-title', String(deck.title)));
        }

        const progress = el('div', 'ragassistant-flashcards-progress');
        progress.setAttribute('aria-hidden', 'true');
        const segments = cards.map(function () {
            return progress.appendChild(el('span', 'ragassistant-flashcards-segment'));
        });

        const card = el('button', 'ragassistant-flashcard');
        card.type = 'button';
        const inner = el('div', 'ragassistant-flashcard-inner');

        // Front: the question, with one hint worded for mouse/keyboard or for touch (CSS picks).
        const front = el('div', 'ragassistant-flashcard-face ragassistant-flashcard-front');
        const frontText = el('p', 'ragassistant-flashcard-text');
        const hint = el('p', 'ragassistant-flashcard-hint');
        hint.append(
            el('span', 'ragassistant-flashcard-hint-pointer',
                str('flashcardfliphint', null, 'Click or press Space to flip')),
            el('span', 'ragassistant-flashcard-hint-touch', str('flashcardfliphinttouch', null, 'Tap to flip'))
        );
        front.append(el('span', 'ragassistant-flashcard-label', str('flashcardquestion', null, 'Question')),
            frontText, hint);

        // Back: the answer, and its source when the backend sent one.
        const back = el('div', 'ragassistant-flashcard-face ragassistant-flashcard-back');
        const backText = el('p', 'ragassistant-flashcard-text');
        const backSource = el('p', 'ragassistant-flashcard-source');
        back.append(el('span', 'ragassistant-flashcard-label', str('flashcardanswer', null, 'Answer')),
            backText, backSource);

        inner.append(front, back);
        card.appendChild(inner);

        // Announces flips and card changes to screen readers.
        const live = el('p', 'sr-only');
        live.setAttribute('aria-live', 'polite');

        // Previous · Card n of N · Next (or Start again on the last card).
        const nav = el('div', 'ragassistant-flashcards-nav');
        const prev = el('button', 'ragassistant-flashcards-step', '←');
        prev.type = 'button';
        prev.setAttribute('aria-label', str('flashcardprevious', null, 'Previous card'));
        const count = el('span', 'ragassistant-flashcards-count');
        const next = el('button', 'ragassistant-flashcards-step');
        next.type = 'button';
        nav.append(prev, count, next);

        section.append(progress, card);
        // A single card needs no navigation.
        if (total > 1) {
            section.append(nav);
        }
        section.append(live);
        content.appendChild(section);

        function countLabel() {
            return str('flashcardcount', { current: index + 1, total: total },
                'Card ' + (index + 1) + ' of ' + total);
        }

        function patchData(){
            const patch = { index: index, flipped: flipped, seen: Array.from(seen) };
            const url = chtApp ? chatApp.dataset.flaschcardProgressUrl : '';
            const session = form ? form.query.selector("input[name='sesskey']") : null;
            if (!url || !session) {
                return;
            }

            return fetch(url, {
                method: 'POST',
                body: JSON.stringify(patch),
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
            }).catch(function () {
                // Ignore fetch errors.
            });
        }

        function render() {
            const item = cards[index];
            const frontValue = String(item.front || '');
            const backValue = String(item.back || '');
            frontText.textContent = frontValue;
            backText.textContent = backValue;
            backSource.textContent = item.source
                ? str('flashcardsource', String(item.source), 'Source: ' + item.source) : '';
            backSource.hidden = !item.source;
            count.textContent = countLabel();

            card.classList.toggle('is-flipped', flipped);
            // Only the visible side is read out.
            front.setAttribute('aria-hidden', flipped ? 'true' : 'false');
            back.setAttribute('aria-hidden', flipped ? 'false' : 'true');
            card.setAttribute('aria-label', flipped
                ? str('flashcardarialabelanswer', backValue, 'Answer: ' + backValue + '. Show the question')
                : str('flashcardarialabelquestion', frontValue, 'Question: ' + frontValue + '. Show the answer'));

            segments.forEach(function (segment, i) {
                segment.classList.toggle('is-current', i === index);
                segment.classList.toggle('is-seen', i !== index && seen.has(i));
            });

            prev.disabled = index === 0;
            const last = index === total - 1;
            next.classList.toggle('is-restart', last);
            next.textContent = last ? '↺ ' + str('flashcardrestart', null, 'Start again') : '→';
            next.setAttribute('aria-label', last
                ? str('flashcardrestart', null, 'Start again')
                : str('flashcardnext', null, 'Next card'));
        }

        function turn() {
            flipped = !flipped;
            render();
            live.textContent = flipped
                ? str('flashcardanswershown', null, 'Answer shown')
                : str('flashcardquestionshown', null, 'Question shown');
        }

        function show(target) {
            if (target < 0 || target >= total) {
                return;
            }
            index = target;
            flipped = false;
            seen.add(index);
            render();
            live.textContent = countLabel();
        }

        card.addEventListener('click', turn);
        prev.addEventListener('click', function () {
            show(index - 1);
        });
        next.addEventListener('click', function () {
            if (index === total - 1) {
                // Start again: back to the first question, with focus on the card.
                show(0);
                card.focus();
            } else {
                show(index + 1);
            }
        });
        // Arrow keys move between cards while focus is anywhere in this deck.
        section.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                show(index - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                show(index + 1);
            }
        });

        render();
    }

    // Saved flashcard decks in the server-rendered history (they reopen on card 1).
    document.querySelectorAll('.ragassistant-message-assistant[data-flashcards]').forEach(function (message) {
        let deck = null;
        try {
            deck = JSON.parse(message.dataset.flashcards);
        } catch (error) {
            return;
        }
        appendFlashcards(message.querySelector('.ragassistant-message-content'), deck);
    });

    // Saved study sheet downloads in the server-rendered history.
    document.querySelectorAll('.ragassistant-message-assistant[data-download]').forEach(function (message) {
        let download = null;
        try {
            download = JSON.parse(message.dataset.download);
        } catch (error) {
            return;
        }
        appendDownload(message.querySelector('.ragassistant-message-content'), download);
    });

    // Saved figures in the server-rendered history, placed the same way as for a new answer.
    document.querySelectorAll('.ragassistant-message-assistant[data-images]').forEach(function (message) {
        let images = null;
        try {
            images = JSON.parse(message.dataset.images);
        } catch (error) {
            return;
        }
        appendFigures(message.querySelector('.ragassistant-message-content'), images);
    });

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

            await runChatRequest(cleanQuestion, originalQuestion, { clearEditor: true });
        });

        // Send one chat request and render the reply. options.fields adds extra form
        // fields; options.clearEditor empties the composer once the request is sent.
        runChatRequest = async function (cleanQuestion, originalQuestion, options) {
            options = options || {};
            if (requestInProgress) {
                return;
            }
            requestInProgress = true;
            // Any new message answers (or drops) an open Yes/No question.
            disableDecisions();

            // Switch to the conversation layout before measuring where the new message
            // goes (as clicking a suggestion card already does).
            removeSuggestions();
            activateChatBackground();

            const formData = new FormData(form);
            formData.set('question', cleanQuestion);
            formData.set('display_question', originalQuestion);
            formData.set('ajax', '1');
            Object.entries(options.fields || {}).forEach(function ([name, value]) {
                formData.set(name, value);
            });

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

            // Show a temporary loading indicator.
            const loadingMessage = createMessage('assistant', 'Thinking for 0s');
            activeLoadingMessage = loadingMessage;
            const loadingContent = loadingMessage.querySelector(
                '.ragassistant-message-content'
            );
            const thinkingLabel = document.createElement('span');
            thinkingLabel.className = 'ragassistant-thinking-shimmer';
            thinkingLabel.setAttribute('aria-live', 'off');
            thinkingLabel.textContent = 'Thinking for 0s';
            loadingContent.replaceChildren(thinkingLabel);

            // Bring the new question towards the top of the current view, with the
            // loading indicator underneath it.
            pinnedMessage = userMessage;
            scrollMessageToViewTop(userMessage);

            // Check a few times a second but only touch the DOM when the shown
            // second changes, so the counter never skips or stalls a second
            // because of interval drift.
            const thinkingStartedAt = Date.now();
            let shownSeconds = 0;
            const thinkingTimer = window.setInterval(function () {
                const elapsedSeconds = Math.floor(
                    (Date.now() - thinkingStartedAt) / 1000
                );
                if (elapsedSeconds !== shownSeconds) {
                    shownSeconds = elapsedSeconds;
                    thinkingLabel.textContent = 'Thinking for ' +
                        elapsedSeconds + 's';
                }
            }, 250);
            activeThinkingTimer = thinkingTimer;

            if (sendButton) {
                sendButton.disabled = true;
                sendButton.setAttribute('aria-label', 'Generating response');
                sendButton.setAttribute('aria-busy', 'true');
            }

            if (options.clearEditor) {
                editor.textContent = '';
                editor.dispatchEvent(new Event('input', { bubbles: true }));

                if (toolInput) {
                    toolInput.value = '';
                }
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

                // Figures first: they replace markers inside the rendered answer.
                appendFigures(content, result.images);
                appendIllustration(content, result.illustration_url);
                appendDownload(content, result.download);
                appendQuiz(content, result.quiz);
                appendFlashcards(content, result.flashcards);
                appendDecision(content, result.pending_action);
                appendSources(content, result.sources);

                // Show the question at the top of the view with as much of the answer as
                // fits below it. Rendered maths only changes content below the question,
                // so the question stays in place when MathJax finishes later.
                scrollMessageToViewTop(pinnedMessage);

                // Not awaited: maths renders in the background, so the Send button is
                // re-enabled as soon as the answer is shown.
                typesetMath(content);

            } catch (error) {
                loadingMessage.remove();
                activeLoadingMessage = null;

                if (error.name !== 'AbortError') {
                    const errorMessage = createMessage('assistant', error.message || 'A connection error occurred.');
                    errorMessage.classList.add('ragassistant-message-error');
                }
                scrollMessageToViewTop(pinnedMessage);

            } finally {
                window.clearInterval(thinkingTimer);
                activeThinkingTimer = null;

                if (sendButton) {
                    sendButton.innerHTML = sendButtonContent;
                    sendButton.setAttribute('aria-label', 'Send question');
                    sendButton.removeAttribute('aria-busy');
                }
                activeLoadingMessage = null;
                requestInProgress = false;
                // Send stays disabled until the (now cleared) box has a question again.
                syncComposerState();
                editor.focus();
            }
        };
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
        syncComposerState();

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

    // Send is only enabled for a question the server would accept (2+ characters) and
    // never while an answer is on its way. Near the 4000 character limit a counter
    // appears; at the limit it turns red, because typing is then blocked.
    function syncComposerState() {
        const length = getText().trim().length;
        if (sendButton && !requestInProgress) {
            sendButton.disabled = length < 2;
        }
        if (charCount) {
            const full = getText().length;
            charCount.hidden = full < MAXLEN * 0.9;
            charCount.classList.toggle('is-limit', full >= MAXLEN);
            if (!charCount.hidden) {
                charCount.textContent = str('charactercount', { count: full, max: MAXLEN },
                    full + ' / ' + MAXLEN);
            }
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
        menuFromButton = false;
        if (toolsButton) {
            toolsButton.setAttribute('aria-expanded', 'false');
        }
    }

    function chooseCommand(item) {
        const value = getText();
        const match = value.match(/(?:^|\s)(\/[^\s]*)$/);
        let next;
        if (menuFromButton) {
            // Opened from the Study tools button: replace any command already at the
            // start and keep the rest of the question.
            const rest = value.replace(/^\s*\/[^\s]*\s*/, '');
            next = item.command + ' ' + rest;
        } else if (match) {
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
        if (!match && !menuFromButton) {
            closeMenu();
            return;
        }
        // The Study tools button lists every command.
        const token = match && !menuFromButton ? match[1].toLowerCase() : '/';
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
        if (toolsButton) {
            toolsButton.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        }
    }

    // ---- Events ------------------------------------------------------------
    editor.addEventListener('input', function (event) {
        if (event.isComposing) return;          // don't touch the DOM mid-IME
        menuFromButton = false;                 // typing goes back to "/" matching
        updateHighlight();
        renderMenu();
    });

    if (toolsButton && menu) {
        // Keep the caret in the question box, so the menu's keyboard handling still works.
        toolsButton.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        toolsButton.addEventListener('click', function () {
            if (!menu.hidden) {
                closeMenu();
                editor.focus();
                return;
            }
            menuFromButton = true;
            editor.focus();
            renderMenu();
        });
    }
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
        valueInput.value = text;
    });

    editor.addEventListener('blur', function () {
        window.setTimeout(closeMenu, 120);
    });
    editor.addEventListener('focus', renderMenu);

    updateHighlight();   // highlight a prefilled question after a validation error

    // On page load, line the bottom of the chat up with the bottom of the screen so the
    // composer and the latest messages are visible without scrolling.
    if (chatApp) {
        // Stop the browser restoring the previous scroll position on reload.
        if ('scrollRestoration' in window.history) {
            window.history.scrollRestoration = 'manual';
        }

        const alignChatToViewportBottom = function () {
            chatApp.scrollIntoView({ block: 'end', inline: 'nearest' });
        };

        // Images, fonts and MathJax can change the height after this point, so align
        // again once everything has loaded, unless the user has already scrolled.
        let userScrolled = false;
        const markUserScrolled = function () {
            userScrolled = true;
        };
        ['wheel', 'touchmove', 'keydown', 'mousedown'].forEach(function (type) {
            window.addEventListener(type, markUserScrolled, { once: true, passive: true });
        });

        // Open on the latest message inside the fixed-height chat window.
        scrollTranscriptToEnd();
        alignChatToViewportBottom();
        if (document.readyState === 'complete') {
            return;
        }
        window.addEventListener('load', function () {
            if (!userScrolled) {
                scrollTranscriptToEnd();
                alignChatToViewportBottom();
            }
        }, { once: true });
    }
});