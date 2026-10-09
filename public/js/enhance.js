/*
 * Optional progressive enhancement. The shop is fully usable without this
 * file: every feature here has a server-rendered equivalent.
 *  1. Live search suggestions under the header search box.
 *  2. Prevents accidental double submission of forms marked data-once
 *     (the server also enforces idempotency keys).
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
})();
