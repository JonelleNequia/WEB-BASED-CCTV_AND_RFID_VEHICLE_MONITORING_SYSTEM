/*
 * B1 (Settings › Gates): the gate cards' "⋯" menus.
 * - Rename / Change login: one shared dialog, filled with that gate's values.
 * - Test camera: asks the camera now and shows the answer as a toast.
 * - Test reader: "hold a tag near the reader" and shows the tag it reads.
 */
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let readerPoll = null;

    function closeMenu(item) {
        item.closest('details.menu')?.removeAttribute('open');
    }

    document.addEventListener('click', function (event) {
        const item = event.target.closest('[data-setup-dialog]');
        if (!item) {
            return;
        }
        closeMenu(item);
        const id = `setup-${item.dataset.setupDialog}`;
        const form = document.querySelector(`#${id} [data-setup-form]`);
        if (!form) {
            return;
        }
        form.action = item.dataset.actionUrl;
        const value = form.querySelector('[data-setup-value]');
        if (value) {
            value.value = item.dataset.value || '';
        }
        window.ui.openDrawer(id);
        window.setTimeout(() => value?.focus(), 50);
    });

    document.addEventListener('click', async function (event) {
        const item = event.target.closest('[data-camera-test]');
        if (!item) {
            return;
        }
        closeMenu(item);
        window.ui.toast('Testing the camera…', 'info', { timeout: 2500 });
        try {
            const response = await fetch(item.dataset.cameraTest, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const result = await response.json();
            window.ui.toast(result.message, result.ok ? 'success' : 'error', { timeout: 8000 });
        } catch (error) {
            window.ui.toast('The test could not run. Try again.', 'error');
        }
    });

    // Reader test: the newest tag this gate's reader reads after the dialog opened.
    document.addEventListener('click', function (event) {
        const item = event.target.closest('[data-reader-test]');
        if (!item) {
            return;
        }
        closeMenu(item);
        const box = document.querySelector('[data-reader-test-box]');
        const result = box.querySelector('[data-reader-test-result]');
        const station = item.dataset.readerTest;
        const openedAt = Date.now();
        box.querySelector('[data-reader-test-gate]').textContent = item.dataset.label || station;
        result.textContent = 'Waiting for a tag…';
        result.className = 'reader-test-result';
        window.ui.openDrawer('setup-reader-test');

        window.clearInterval(readerPoll);
        readerPoll = window.setInterval(async function () {
            if (!document.getElementById('setup-reader-test')?.classList.contains('is-open')) {
                window.clearInterval(readerPoll);
                return;
            }
            try {
                const response = await fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const reader = (data.readers || []).find((row) => row.station === station);
                if (!reader) {
                    result.textContent = 'This reader is not connected yet.';
                    result.className = 'reader-test-result is-bad';
                } else if (!reader.ok) {
                    result.textContent = `The reader is not connected (${reader.detail}). Check its LAN cable and power.`;
                    result.className = 'reader-test-result is-bad';
                } else if (reader.epc && reader.read_at && Date.parse(reader.read_at) >= openedAt - 1000) {
                    result.textContent = `✓ Tag read: …${String(reader.epc).slice(-8)}. The reader works.`;
                    result.className = 'reader-test-result is-good';
                } else {
                    result.textContent = 'Connected. Waiting for a tag…';
                    result.className = 'reader-test-result';
                }
            } catch (error) {
                // The next poll tries again.
            }
        }, 1000);
    });

    // A camera preview that fails to load is retried (the detector may be starting).
    document.querySelectorAll('[data-setup-preview]').forEach(function (img) {
        const base = img.getAttribute('src');
        img.addEventListener('error', function () {
            window.setTimeout(function () {
                img.src = base + (base.includes('?') ? '&' : '?') + 'retry=' + Date.now();
            }, 5000);
        });
    });
})();
