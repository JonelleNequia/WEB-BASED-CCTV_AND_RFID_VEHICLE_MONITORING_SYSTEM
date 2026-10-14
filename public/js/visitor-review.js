/*
 * Gate direction work, Phase D: Visitors › Review, one card at a time.
 * Each button posts its action (JSON); a finished card goes away and the
 * next one shows. Keys (not while typing): C confirm, E type the plate,
 * 1-3 pick a known plate, I / O set IN / OUT, N name, X no plate visible,
 * D not a vehicle, U duplicate, J / K next / previous. Enter in a text box
 * saves it; Esc leaves it.
 */
(function () {
    const queue = document.querySelector('[data-review-queue]');
    if (!queue) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const nav = queue.querySelector('[data-review-nav]');
    const keys = queue.querySelector('[data-review-keys]');
    const position = queue.querySelector('[data-review-position]');
    const remaining = queue.querySelector('[data-review-remaining]');
    const flash = queue.querySelector('[data-review-flash]');
    let cards = Array.from(queue.querySelectorAll('[data-review-card]'));
    let current = 0;
    let busy = false;

    function show(index) {
        if (cards.length === 0) {
            if (nav) {
                nav.hidden = true;
            }
            if (!queue.querySelector('[data-review-done]')) {
                const done = document.createElement('p');
                done.className = 'review-done';
                done.dataset.reviewDone = '';
                done.textContent = 'All done: nothing left to review.';
                queue.append(done);
            }
            return;
        }
        current = (index + cards.length) % cards.length;
        cards.forEach((card, i) => {
            card.hidden = i !== current;
        });
        if (position) {
            position.textContent = `${current + 1} of ${cards.length}`;
        }
    }

    function say(text, isError) {
        flash.hidden = !text;
        flash.textContent = text || '';
        flash.classList.toggle('is-error', !!isError);
    }

    async function send(card, submitter) {
        if (busy) {
            return;
        }
        const form = card.querySelector('[data-review-form]');
        const data = new FormData(form);
        data.set('action', submitter.value);
        const error = card.querySelector('[data-review-error]');
        error.hidden = true;
        busy = true;
        card.classList.add('is-busy');
        try {
            // getAttribute: the buttons named "action" hide form.action.
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: data,
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = result.message || (response.status === 419 ? 'This page was open too long. Reload it.' : `Could not save (error ${response.status}).`);
                error.textContent = message;
                error.hidden = false;
                return;
            }
            say(result.message);
            if (remaining) {
                remaining.textContent = result.remaining;
            }
            const needs = result.needs || {};
            if (!needs.plate && !needs.direction) {
                const index = cards.indexOf(card);
                card.remove();
                cards = cards.filter((item) => item !== card);
                show(index);
                return;
            }
            // Part done: hide what was answered, keep the card.
            card.querySelector('[data-review-plate]').hidden = !needs.plate;
            card.querySelector('[data-review-direction-actions]').hidden = !needs.direction;
        } catch (exception) {
            error.textContent = 'This PC could not reach the system. Try again.';
            error.hidden = false;
        } finally {
            busy = false;
            card.classList.remove('is-busy');
        }
    }

    queue.addEventListener('submit', (event) => {
        const card = event.target.closest('[data-review-card]');
        if (!card) {
            return;
        }
        event.preventDefault();
        const submitter = event.submitter || card.querySelector('[data-review-enter]');
        send(card, submitter);
    });

    function typing(target) {
        return target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    }

    document.addEventListener('keydown', (event) => {
        const card = cards[current];
        if (!card || card.hidden || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        if (typing(event.target)) {
            if (event.key === 'Escape') {
                event.target.blur();
            } else if (event.key === 'Enter' && event.target.matches('[data-review-name-input]')) {
                event.preventDefault();
                send(card, card.querySelector('button[value="set_name"]'));
            }
            return;
        }
        if (document.querySelector('[data-drawer].is-open')) {
            return;
        }
        const key = event.key.toLowerCase();
        if (key === 'j' || key === 'arrowright') {
            show(current + 1);
        } else if (key === 'k' || key === 'arrowleft') {
            show(current - 1);
        } else if (key === 'e') {
            card.querySelector('[data-review-plate-input]')?.select();
        } else if (key === 'n') {
            card.querySelector('[data-review-name-input]')?.focus();
        } else {
            const button = Array.from(card.querySelectorAll('[data-review-key]'))
                .find((item) => item.dataset.reviewKey === key && !item.closest('[hidden]'));
            if (!button) {
                return;
            }
            send(card, button);
        }
        event.preventDefault();
    });

    if (cards.length) {
        nav.hidden = false;
        keys.hidden = false;
        show(0);
    }
})();
