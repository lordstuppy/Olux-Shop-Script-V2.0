/*
 * Optional progressive enhancement. The shop is fully usable without this
 * file: every feature here has a server-rendered equivalent.
 *  1. Live search suggestions under the header search box.
 *  2. Prevents accidental double submission of forms marked data-once
 *     (the server also enforces idempotency keys).
 *  3. Show/hide button (eye) on password fields. Without scripts the fields
 *     simply stay masked: a server-side toggle would have to send the
 *     password back in a page, which is worse than no toggle.
 *  4. Scrolls a sideways section menu (narrow screens) to the current page.
 *  5. Busy indicator on the button that submitted a form, shown when the
 *     next page takes longer than 200 ms (without scripts the browser's own
 *     loading indicator does this job).
 *  6. Live updates for signed-in users: polls /live every 10 s while the tab
 *     is visible and someone used it in the last 20 minutes. Updates the
 *     unread badge and wallet balance (in every tab of this browser), shows
 *     new notifications with an optional sound, and re-fetches the page
 *     content when it changed - unless the person is filling in a form, who
 *     gets a "Show it" button instead. Without scripts pages show current
 *     data whenever they are loaded.
 */
(function () {
    'use strict';

    var input = document.querySelector('[data-live-search]');
    if (input && window.fetch) {
        var list = document.createElement('ul');
        list.className = 'suggestions';
        list.id = 'search-suggestions';
        list.hidden = true;
        input.parentNode.appendChild(list);
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('autocomplete', 'off');

        var timer = null;
        var lastQuery = '';

        var render = function (results) {
            list.textContent = '';
            results.forEach(function (item) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = item.url;
                a.textContent = item.title;
                li.appendChild(a);
                list.appendChild(li);
            });
            list.hidden = results.length === 0;
        };

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
                render([]);
                return;
            }
            timer = setTimeout(function () {
                lastQuery = q;
                fetch(input.getAttribute('data-live-search') + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.ok ? response.json() : { results: [] };
                }).then(function (data) {
                    if (q === lastQuery) {
                        render(data.results || []);
                    }
                }).catch(function () {
                    render([]);
                });
            }, 200);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                render([]);
            }
        });
        document.addEventListener('click', function (event) {
            if (!list.contains(event.target) && event.target !== input) {
                render([]);
            }
        });
    }

    var body = document.body;
    var showLabel = body.getAttribute('data-label-show-password') || 'Show password';
    var hideLabel = body.getAttribute('data-label-hide-password') || 'Hide password';
    var eye = '<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
    var eyeOpen = eye + '</svg>';
    var eyeClosed = eye + '<line x1="3" y1="3" x2="21" y2="21"/></svg>';

    // Parts of the page that live updates may replace are enhanced again afterwards.
    var enhance = function (root) {
        Array.prototype.forEach.call(root.querySelectorAll('form[data-once]'), function (form) {
            form.addEventListener('submit', function () {
                Array.prototype.forEach.call(form.querySelectorAll('button[type=submit]'), function (button) {
                    button.disabled = true;
                    button.setAttribute('aria-disabled', 'true');
                });
            });
        });

        Array.prototype.forEach.call(root.querySelectorAll('input[type=password]'), function (field) {
            var wrap = document.createElement('span');
            wrap.className = 'password-wrap';
            field.parentNode.insertBefore(wrap, field);
            wrap.appendChild(field);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'password-toggle';
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', showLabel);
            if (field.id) {
                button.setAttribute('aria-controls', field.id);
            }
            button.innerHTML = eyeOpen;
            wrap.appendChild(button);

            var hide = function () {
                field.type = 'password';
                button.setAttribute('aria-pressed', 'false');
                button.setAttribute('aria-label', showLabel);
                button.innerHTML = eyeOpen;
            };
            button.addEventListener('click', function () {
                if (field.type === 'password') {
                    // Visible text must not go to spell-check services or autocorrect.
                    field.setAttribute('spellcheck', 'false');
                    field.setAttribute('autocapitalize', 'off');
                    field.setAttribute('autocorrect', 'off');
                    field.type = 'text';
                    button.setAttribute('aria-pressed', 'true');
                    button.setAttribute('aria-label', hideLabel);
                    button.innerHTML = eyeClosed;
                } else {
                    hide();
                }
                field.focus();
            });
            // Mask again before submitting so the browser never stores the value
            // as ordinary text-field history.
            if (field.form) {
                field.form.addEventListener('submit', hide);
            }
        });
    };
    enhance(document);

    // Section menus scroll sideways on narrow screens: show the current entry.
    var current = document.querySelector('.subnav [aria-current="page"]');
    var menu = current && current.closest('.nav');
    if (menu && menu.scrollWidth > menu.clientWidth) {
        menu.scrollLeft += current.getBoundingClientRect().left - menu.getBoundingClientRect().left - 16;
    }

    var busyTimers = [];
    var clearBusy = function () {
        busyTimers.forEach(clearTimeout);
        busyTimers = [];
        Array.prototype.forEach.call(document.querySelectorAll('.is-busy'), function (el) {
            el.classList.remove('is-busy');
            el.removeAttribute('aria-busy');
        });
    };
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (event.defaultPrevented || form.hasAttribute('data-download')) {
            return;
        }
        var button = event.submitter || form.querySelector('button[type=submit], button:not([type])');
        if (!button) {
            return;
        }
        busyTimers.push(setTimeout(function () {
            button.classList.add('is-busy');
            button.setAttribute('aria-busy', 'true');
        }, 200));
        // A response that does not leave the page (an error, a download) must not leave it spinning.
        busyTimers.push(setTimeout(clearBusy, 15000));
    });
    // Back/forward cache restores the page as it was, spinner included.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            clearBusy();
        }
    });

    // Live updates for signed-in users: poll /live while the tab is visible.
    var liveUrl = body.getAttribute('data-live');
    if (liveUrl && window.fetch && window.DOMParser) {
        var label = function (name, fallback) {
            return body.getAttribute('data-label-' + name) || fallback;
        };
        var region = document.createElement('div');
        region.className = 'live-region';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        body.appendChild(region);
        var say = function (text, options) {
            options = options || {};
            if (options.id) {
                var old = region.querySelector('[data-id="' + options.id + '"]');
                if (old) {
                    old.remove();
                }
            }
            var box = document.createElement('div');
            box.className = 'live-message' + (options.warning ? ' is-warning' : '');
            if (options.id) {
                box.setAttribute('data-id', options.id);
            }
            var textNode = document.createElement(options.href ? 'a' : 'span');
            textNode.textContent = text;
            if (options.href) {
                textNode.href = options.href;
            }
            box.appendChild(textNode);
            if (options.action) {
                var act = document.createElement('button');
                act.type = 'button';
                act.textContent = options.action.text;
                act.addEventListener('click', function () {
                    box.remove();
                    options.action.run();
                });
                box.appendChild(act);
            }
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'live-close';
            close.setAttribute('aria-label', label('close', 'Close'));
            close.textContent = '\u00d7';
            close.addEventListener('click', function () {
                box.remove();
            });
            box.appendChild(close);
            region.appendChild(box);
            if (options.timeout) {
                setTimeout(function () {
                    box.remove();
                }, options.timeout);
            }
            return box;
        };
        var drop = function (id) {
            var box = region.querySelector('[data-id="' + id + '"]');
            if (box) {
                box.remove();
            }
        };

        // A short two-tone sound made in the browser (no audio file). Browsers
        // only allow sound after the person interacted with the page.
        var audio = null;
        var unlockAudio = function () {
            if (!audio && (window.AudioContext || window.webkitAudioContext)) {
                audio = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audio && audio.state === 'suspended') {
                audio.resume();
            }
        };
        ['pointerdown', 'keydown'].forEach(function (type) {
            document.addEventListener(type, unlockAudio, { once: true, passive: true });
        });
        var chime = function () {
            if (body.getAttribute('data-live-sound') !== '1' || !audio || audio.state !== 'running') {
                return;
            }
            [[880, 0], [1320, 0.12]].forEach(function (tone) {
                var osc = audio.createOscillator();
                var gain = audio.createGain();
                var start = audio.currentTime + tone[1];
                osc.frequency.value = tone[0];
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.12, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.18);
                osc.connect(gain).connect(audio.destination);
                osc.start(start);
                osc.stop(start + 0.2);
            });
        };

        var store = {
            get: function (key) {
                try {
                    return window.localStorage.getItem(key);
                } catch (e) {
                    return null;
                }
            },
            set: function (key, value) {
                try {
                    window.localStorage.setItem(key, value);
                } catch (e) {
                    // Private mode or storage blocked: live updates still work in this tab.
                }
            }
        };

        // Header: unread badge and wallet balance, in every open tab.
        var showHeader = function (data) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-live-unread]'), function (badge) {
                badge.hidden = !data.unread;
                var count = badge.querySelector('[data-live-unread-count]');
                if (count) {
                    count.textContent = data.unread > 99 ? '99+' : String(data.unread);
                }
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-live-balance]'), function (el) {
                if (data.balance) {
                    el.textContent = data.balance;
                }
            });
        };
        // Toast and sound once per new notification, in the tab that saw it first.
        var announce = function (latest) {
            if (!latest) {
                return;
            }
            var seen = parseInt(store.get('shop-live-latest') || '0', 10);
            if (latest.id > seen) {
                store.set('shop-live-latest', String(latest.id));
                if (seen > 0) {
                    say(label('live-new', 'New notification:') + ' ' + latest.title, { href: latest.url, timeout: 10000 });
                    chime();
                }
            }
        };

        // The page itself: re-fetch it and swap the content, unless the person is
        // in the middle of a form; then offer the update instead.
        var content = document.getElementById('live-content');
        var dirty = false;
        document.addEventListener('input', function (event) {
            if (content && content.contains(event.target)) {
                dirty = true;
            }
        });
        var busy = function () {
            var active = document.activeElement;
            return dirty || (active && content && content.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName));
        };
        var refreshing = false;
        var refresh = function () {
            if (!content || refreshing) {
                return;
            }
            refreshing = true;
            fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' } }).then(function (response) {
                if (response.redirected || !response.ok) {
                    // Signed out, or the page is gone: let the browser show what is there now.
                    window.location.reload();
                    return null;
                }
                return response.text();
            }).then(function (html) {
                refreshing = false;
                if (html === null || html === undefined) {
                    return;
                }
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('live-content');
                if (!fresh || fresh.innerHTML === content.innerHTML) {
                    return;
                }
                // Move the parsed nodes instead of re-serialising them through
                // innerHTML, which can turn inert markup into live markup (mXSS).
                content.replaceChildren.apply(content, Array.prototype.map.call(fresh.childNodes, function (node) {
                    return document.importNode(node, true);
                }));
                document.title = doc.title;
                dirty = false;
                enhance(content);
                drop('pending');
                say(label('live-updated', 'This page was updated.'), { id: 'updated', timeout: 4000 });
            }).catch(function () {
                refreshing = false;
            });
        };
        var pageChanged = function () {
            if (busy()) {
                say(label('live-pending', 'This page has new information.'), {
                    id: 'pending',
                    action: { text: label('live-show', 'Show it'), run: function () { dirty = false; refresh(); } }
                });
            } else {
                refresh();
            }
        };

        // Other tabs of this browser get each result without polling themselves.
        var channel = 'BroadcastChannel' in window ? new BroadcastChannel('shop-live') : null;
        if (channel) {
            channel.onmessage = function (event) {
                if (event.data && typeof event.data.unread === 'number') {
                    showHeader(event.data);
                }
            };
        }

        var version = body.getAttribute('data-live-version') || null;
        var failures = 0;
        var timer = null;
        // Stop polling after 20 idle minutes, so an open tab does not keep the
        // session alive forever; any interaction resumes it.
        var IDLE_MS = 20 * 60 * 1000;
        var INTERVAL = 10000;
        var lastActivity = Date.now();
        ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(function (type) {
            document.addEventListener(type, function () {
                var wasIdle = Date.now() - lastActivity > IDLE_MS;
                lastActivity = Date.now();
                if (wasIdle) {
                    poll();
                }
            }, { passive: true });
        });
        var schedule = function () {
            clearTimeout(timer);
            var wait = failures ? Math.min(60000, INTERVAL * Math.pow(2, failures)) : INTERVAL;
            timer = setTimeout(poll, wait);
        };
        var poll = function () {
            clearTimeout(timer);
            if (document.hidden || Date.now() - lastActivity > IDLE_MS) {
                return;
            }
            var path = window.location.pathname + window.location.search;
            fetch(liveUrl + '?path=' + encodeURIComponent(path), {
                credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (response.status === 401 || response.redirected) {
                    throw new Error('signed-out');
                }
                if (!response.ok || type.indexOf('json') === -1) {
                    throw new Error('http ' + response.status);
                }
                return response.json();
            }).then(function (data) {
                if (failures >= 2) {
                    say(label('live-back', 'Connection restored.'), { id: 'connection', timeout: 3000 });
                }
                failures = 0;
                showHeader(data);
                announce(data.latest);
                if (channel) {
                    channel.postMessage({ unread: data.unread, balance: data.balance });
                }
                if (data.version && version && data.version !== version) {
                    pageChanged();
                }
                if (data.version) {
                    version = data.version;
                }
                schedule();
            }).catch(function (error) {
                if (error && error.message === 'signed-out') {
                    return;
                }
                failures++;
                if (failures === 2) {
                    say(label('live-lost', 'Connection lost. Trying again...'), { id: 'connection', warning: true });
                }
                schedule();
            });
        };
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll();
            }
        });
        schedule();
    }
})();
