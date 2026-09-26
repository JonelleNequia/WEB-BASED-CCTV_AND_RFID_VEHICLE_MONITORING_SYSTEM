/*
 * UI Phase 4: Activity Logs › All Events.
 * Details modal, snapshot viewer, toolbar print reports and a live refresh
 * of page 1 that keeps the current filters.
 */
(function () {
    const dataNode = document.getElementById('event-log-modal-data');
    const reportNode = document.getElementById('event-log-report-data');
    const realtimeNode = document.getElementById('event-log-realtime-data');
    const list = document.querySelector('[data-event-log-list]');
    const printSheet = document.querySelector('[data-event-log-print-sheet]');
    const realtime = realtimeNode ? JSON.parse(realtimeNode.textContent || '{}') : {};
    const reports = reportNode ? JSON.parse(atob(reportNode.dataset.payload || 'e30=')) : {};
    let logs = dataNode ? JSON.parse(dataNode.textContent || '[]') : [];
    let activeLog = null;

    const detailFields = [
        ['Owner', 'owner_name'],
        ['Vehicle', 'vehicle_type'],
        ['Color', 'vehicle_color'],
        ['Category', 'category_label'],
        ['Log type', 'log_type_label'],
        ['Source', 'source_label'],
        ['Station / Camera', 'station_label'],
        ['RFID Tag', 'rfid_tag_uid'],
        ['State', 'state_label'],
        ['Status', 'status_label'],
        ['Match', 'match_label'],
        ['Alert', 'alert_reason'],
        ['Time', 'display_time'],
    ];

    const tone = {
        entry: 'info', exit: 'neutral', guest: 'brand', rfid: 'neutral',
    };

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    /* ---------- Rows (same markup as logs/partials/event-row) ---------- */

    function buildRow(log, index) {
        const tr = el('tr', log.is_alert ? 'is-alert-row' : '');

        tr.append(el('td', 'nowrap', log.display_time));

        const snap = el('td');
        if (log.image_url) {
            const button = el('button', 'thumb-button');
            button.type = 'button';
            button.dataset.snapshot = log.image_url;
            button.setAttribute('aria-label', `Enlarge snapshot of ${log.plate_number || ''}`);
            const img = el('img', 'thumb thumb-xs');
            img.src = log.image_url;
            img.alt = '';
            img.loading = 'lazy';
            button.append(img);
            snap.append(button);
        } else {
            snap.append(el('span', 'thumb thumb-xs thumb-empty'));
        }
        tr.append(snap);

        const plate = el('td');
        plate.append(el('strong', null, log.plate_number || '—'));
        if (log.owner_name && log.owner_name !== 'N/A') {
            plate.append(el('div', 'table-subtext', log.owner_name));
        }
        tr.append(plate);

        tr.append(el('td', null, `${log.vehicle_type || ''}${log.vehicle_color && log.vehicle_color !== 'N/A' ? ' · ' + log.vehicle_color : ''}`));

        const movement = el('td');
        movement.append(el('span', `badge badge-tone-${tone[String(log.event_type).toLowerCase()] || 'neutral'}`, log.event_type));
        tr.append(movement);

        const type = el('td');
        type.append(el('span', log.is_alert ? 'log-type log-type-alert' : 'log-type', log.log_type_label || ''));
        if (log.is_alert && log.alert_reason) {
            type.append(el('div', 'table-subtext', log.alert_reason.length > 60 ? log.alert_reason.slice(0, 57) + '...' : log.alert_reason));
        }
        tr.append(type);

        tr.append(el('td', null, log.station_label));

        const status = el('td');
        status.append(el('span', `badge badge-${log.status_badge_class || 'secondary'}`, log.status_label));
        tr.append(status);

        const actions = el('td', 'row-actions');
        const details = el('button', 'button button-secondary button-sm', 'Details');
        details.type = 'button';
        details.dataset.eventLogView = String(index);
        actions.append(details);
        tr.append(actions);

        return tr;
    }

    function renderRows(items) {
        if (!list || !Array.isArray(items)) {
            return;
        }

        logs = items;

        if (items.length === 0) {
            const tr = el('tr');
            const td = el('td');
            td.colSpan = 9;
            const empty = el('div', 'empty-block');
            empty.append(el('strong', null, 'No records matched the current filters'), el('p', null, 'Adjust the filters to widen the list.'));
            td.append(empty);
            tr.append(td);
            list.replaceChildren(tr);
            return;
        }

        list.replaceChildren(...items.map(buildRow));
    }

    /* ---------- Details modal and snapshot viewer ---------- */

    function openDetails(log) {
        activeLog = log;
        document.getElementById('log-details-title').textContent = `${log.event_type} · ${log.plate_number || '—'} (${log.record_type_label} #${log.id})`;

        const grid = document.querySelector('[data-log-details-grid]');
        grid.replaceChildren();
        detailFields.forEach(function ([label, key]) {
            if (!log[key]) {
                return;
            }
            grid.append(el('dt', null, label), el('dd', null, log[key]));
        });

        const wrap = document.querySelector('[data-log-details-image-wrap]');
        const image = document.querySelector('[data-log-details-image]');
        if (log.image_url) {
            image.src = log.image_url;
            wrap.hidden = false;
        } else {
            image.removeAttribute('src');
            wrap.hidden = true;
        }

        document.querySelector('[data-log-details-link]').href = log.detail_url || '#';
        document.querySelector('[data-log-details-export]').href = log.export_url || '#';
        window.ui.openDrawer('log-details');
    }

    function openSnapshot(url) {
        document.querySelector('[data-snapshot-full]').src = url;
        window.ui.openDrawer('snapshot-viewer');
    }

    /* ---------- Print ---------- */

    function startPrint() {
        printSheet.hidden = false;
        document.body.classList.add('is-printing-event-log');

        const cleanup = function () {
            document.body.classList.remove('is-printing-event-log');
            printSheet.hidden = true;
            printSheet.replaceChildren();
            window.removeEventListener('afterprint', cleanup);
        };

        window.addEventListener('afterprint', cleanup);
        window.print();
    }

    function printHeader(title, meta) {
        const header = el('div', 'event-log-print-header');
        header.append(el('span', null, 'PHILCST Vehicle Monitoring'), el('h2', null, title), el('p', null, meta));
        return header;
    }

    function printLog(log) {
        if (!log) {
            return;
        }

        printSheet.replaceChildren(printHeader(`${log.event_type} - ${log.plate_number || '—'}`, `${log.record_type_label} #${log.id}`));

        const grid = el('div', 'event-log-print-grid');
        [['Log type', 'log_type_label'], ['Plate', 'plate_number'], ...detailFields].forEach(function ([label, key]) {
            const item = el('div');
            item.append(el('span', null, label), el('strong', null, log[key] || 'N/A'));
            grid.append(item);
        });
        printSheet.append(grid);

        if (log.image_url) {
            const box = el('div', 'event-log-print-image');
            const img = el('img');
            img.src = log.image_url;
            img.alt = 'Snapshot';
            box.append(img);
            printSheet.append(box);
        }

        startPrint();
    }

    function printReport(report) {
        if (!report) {
            return;
        }

        const rows = Array.isArray(report.rows) ? report.rows : [];
        printSheet.replaceChildren(printHeader(`Activity Logs - ${report.label}`, `${rows.length} record${rows.length === 1 ? '' : 's'}`));

        if (rows.length === 0) {
            printSheet.append(el('p', 'event-log-print-empty', 'No records found for this report.'));
        } else {
            const columns = [
                ['timestamp', 'Timestamp'], ['log_type', 'Log Type'], ['source', 'Source'], ['plate_number', 'Plate Number'],
                ['owner_name', 'Owner Name'], ['state', 'State'], ['status', 'Status'], ['rfid_tag', 'RFID Tag'],
            ];
            const table = el('table', 'event-log-print-table');
            const headRow = el('tr');
            columns.forEach(([, label]) => headRow.append(el('th', null, label)));
            const thead = el('thead');
            thead.append(headRow);
            const tbody = el('tbody');
            rows.forEach(function (row) {
                const tr = el('tr');
                columns.forEach(([key]) => tr.append(el('td', null, row[key] || 'N/A')));
                tbody.append(tr);
            });
            table.append(thead, tbody);
            printSheet.append(table);
        }

        startPrint();
    }

    /* ---------- Events ---------- */

    document.addEventListener('click', function (event) {
        const report = event.target.closest('[data-event-log-report-print]');
        if (report) {
            report.closest('details')?.removeAttribute('open');
            printReport(reports[report.dataset.eventLogReportPrint]);
            return;
        }

        const snapshot = event.target.closest('[data-snapshot]');
        if (snapshot) {
            openSnapshot(snapshot.dataset.snapshot);
            return;
        }

        const view = event.target.closest('[data-event-log-view]');
        if (view) {
            const log = logs[Number.parseInt(view.dataset.eventLogView, 10)];
            if (log) {
                openDetails(log);
            }
        }
    });

    document.querySelector('[data-log-details-print]')?.addEventListener('click', function () {
        printLog(activeLog);
    });

    // Close the Print menu when clicking elsewhere.
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details.menu[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    /* ---------- Live refresh (page 1, same filters) ---------- */

    let inFlight = false;

    async function refresh() {
        if (!realtime.refreshUrl || inFlight || document.querySelector('[data-drawer].is-open')) {
            return;
        }

        inFlight = true;
        try {
            const response = await fetch(realtime.refreshUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const body = await response.json();
            renderRows(body.logs || []);
            Object.entries(body.summary || {}).forEach(function ([key, value]) {
                const node = document.querySelector(`[data-log-summary="${key}"] .stat-value`);
                if (node) {
                    node.textContent = value;
                }
            });
        } catch (error) {
            // Keep the current rows during short network hiccups.
        } finally {
            inFlight = false;
        }
    }

    window.setInterval(refresh, 4000);
})();
