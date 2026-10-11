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

    Array.prototype.forEach.call(document.querySelectorAll('form[data-once]'), function (form) {
        form.addEventListener('submit', function () {
            Array.prototype.forEach.call(form.querySelectorAll('button[type=submit]'), function (button) {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            });
        });
    });

    var body = document.body;
    var showLabel = body.getAttribute('data-label-show-password') || 'Show password';
    var hideLabel = body.getAttribute('data-label-hide-password') || 'Hide password';
    var eye = '<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
    var eyeOpen = eye + '</svg>';
    var eyeClosed = eye + '<line x1="3" y1="3" x2="21" y2="21"/></svg>';

    Array.prototype.forEach.call(document.querySelectorAll('input[type=password]'), function (field) {
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
})();
