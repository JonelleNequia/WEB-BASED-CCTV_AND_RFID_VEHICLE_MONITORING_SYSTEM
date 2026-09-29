/*
 * Sidebar: UHF reader status (connected or not, last EPC, RSSI and time),
 * refreshed while the page is visible.
 */
(function () {
    const list = document.querySelector('[data-uhf-status]');
    if (!list) {
        return;
    }

    async function refresh() {
        if (document.hidden) {
            return;
        }
        try {
            const response = await fetch(list.dataset.uhfStatus, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            (data.readers || []).forEach(function (reader) {
                const item = list.querySelector(`[data-uhf-station="${reader.station}"]`);
                if (!item) {
                    return;
                }
                item.querySelector('.health-dot').className = `health-dot ${reader.ok ? 'is-ok' : 'is-down'}`;
                item.querySelector('[data-uhf-state]').textContent = reader.detail;
                item.querySelector('[data-uhf-tag]').textContent = reader.tag_line;
                item.title = `${reader.label}: ${reader.detail}${reader.epc ? ` · last tag ${reader.epc}` : ''}`;
            });
        } catch (error) {
            // Offline for a moment; the next refresh tries again.
        }
    }

    setInterval(refresh, 3000);
})();
