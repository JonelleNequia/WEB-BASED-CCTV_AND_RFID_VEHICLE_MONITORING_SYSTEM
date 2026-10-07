/*
 * Delete device work: "Delete device" on a camera or reader. Shows what goes
 * (the hardware's own settings) and what stays (every IN/OUT record), then
 * deletes after a clear "Delete device" click.
 *   <button data-device-delete data-summary-url data-delete-url>
 *   or window.deviceDelete.open(summaryUrl, deleteUrl)
 */
(function () {
    const modal = document.getElementById('device-delete');
    if (!modal) {
        return;
    }
    const body = modal.querySelector('[data-device-delete-body]');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    async function open(summaryUrl, deleteUrl) {
        body.innerHTML = '<p class="text-muted">Loading…</p>';
        window.ui.openDrawer('device-delete');
        let summary;
        try {
            const response = await fetch(summaryUrl, { headers: { Accept: 'application/json' } });
            summary = await response.json();
            if (!response.ok) {
                throw new Error(summary.message || 'not found');
            }
        } catch (error) {
            body.innerHTML = '<p class="field-error">This device was not found. Refresh the page.</p>';
            return;
        }

        const gates = (summary.gates || []).map((gate) => `${gate.name} (${gate.role === 'camera' ? 'camera' : 'reader'})`).join(', ');
        body.innerHTML = `
            <p><strong>Delete ${escape(summary.name)}${gates ? ` from ${escape(gates)}` : ''}?</strong></p>
            <p>This deletes:</p>
            <ul class="device-delete-list">${(summary.removes || []).map((item) => `<li>${escape(item)}</li>`).join('')}</ul>
            <p class="device-delete-kept">Kept: ${summary.records_kept ? `${summary.records_kept} IN/OUT and other record${summary.records_kept === 1 ? '' : 's'}` : 'all IN/OUT and other records'} made with it. They will show “${escape(summary.name)} (removed)”.</p>
            ${gates ? `<p class="field-help">The gate shows “+ Add ${summary.kind === 'reader' ? 'reader' : 'camera'}” afterwards. If the device is still plugged in, it appears again in the device list as not assigned.</p>` : ''}
            <div class="button-row button-row-end">
                <button type="button" class="button button-secondary button-lg" data-drawer-close>Cancel</button>
                <button type="button" class="button button-danger button-lg" data-device-delete-yes>Delete device</button>
            </div>`;

        body.querySelector('[data-device-delete-yes]').addEventListener('click', async (event) => {
            event.target.disabled = true;
            event.target.textContent = 'Deleting…';
            try {
                const response = await fetch(deleteUrl, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ confirm: true }),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(result.message || 'The device was not deleted.');
                }
                window.ui.closeDrawer('device-delete');
                window.ui.toast(result.message, 'success');
                window.setTimeout(() => window.location.reload(), 900);
            } catch (error) {
                event.target.disabled = false;
                event.target.textContent = 'Delete device';
                window.ui.toast(error.message, 'error');
            }
        });
    }

    window.deviceDelete = { open };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-device-delete]');
        if (button) {
            event.preventDefault();
            button.closest('details')?.removeAttribute('open');
            open(button.dataset.summaryUrl, button.dataset.deleteUrl);
        }
    });
})();
