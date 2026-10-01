(function () {
    const payloadNode = document.getElementById('dashboard-live-data');

    if (!payloadNode) {
        return;
    }

    const payload = JSON.parse(payloadNode.textContent);
    const streamNodeMaps = {
        rfid: new Map(),
        events: new Map(),
    };

    function setMetric(name, value) {
        document.querySelectorAll(`[data-dashboard-metric="${name}"]`).forEach(function (node) {
            node.textContent = value ?? 0;
        });
    }

    function emptyState(title, copy) {
        const wrapper = document.createElement('div');
        const heading = document.createElement('h4');
        const paragraph = document.createElement('p');

        wrapper.className = 'empty-state';
        heading.textContent = title;
        paragraph.textContent = copy;
        wrapper.append(heading, paragraph);

        return wrapper;
    }

    function streamKey(item) {
        return [
            item.title || '',
            item.summary || '',
            item.display_time || '',
            item.badge_label || '',
        ].join('|');
    }

    function buildStreamItem(item) {
        const article = document.createElement('article');
        const body = document.createElement('div');
        const title = document.createElement('strong');
        const summary = document.createElement('p');
        const time = document.createElement('small');
        const badge = document.createElement('span');
        const badgeClass = item.badge_class || 'secondary';

        article.className = 'stream-item stream-item-compact';
        title.textContent = item.title || 'Activity';
        summary.textContent = item.summary || '';
        time.textContent = item.display_time || 'No time';
        badge.className = `badge badge-${badgeClass}`;
        badge.textContent = item.badge_label || 'LOG';

        body.append(title, summary, time);
        article.append(body, badge);

        return article;
    }

    function updateStreamItem(article, item) {
        const replacement = buildStreamItem(item);
        article.className = replacement.className;
        article.replaceChildren(...replacement.childNodes);
    }

    function renderStream(name, items, emptyTitle, emptyCopy) {
        const container = document.querySelector(`[data-dashboard-stream="${name}"]`);
        const nodeMap = streamNodeMaps[name];

        if (!container || !nodeMap) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            nodeMap.clear();
            container.replaceChildren(emptyState(emptyTitle, emptyCopy));
            return;
        }

        container.querySelectorAll('.empty-state').forEach(function (node) {
            node.remove();
        });

        if (nodeMap.size === 0) {
            container.querySelectorAll('.stream-item').forEach(function (node) {
                node.remove();
            });
        }

        const seen = new Set();

        items.forEach(function (item, index) {
            const key = streamKey(item);
            let node = nodeMap.get(key);

            if (!node) {
                node = buildStreamItem(item);
                node.dataset.dashboardItemKey = key;
                nodeMap.set(key, node);
            } else {
                updateStreamItem(node, item);
            }

            seen.add(key);

            const currentNode = container.children[index];
            if (currentNode !== node) {
                container.insertBefore(node, currentNode || null);
            }
        });

        Array.from(nodeMap.entries()).forEach(function ([key, node]) {
            if (seen.has(key)) {
                return;
            }

            node.remove();
            nodeMap.delete(key);
        });
    }

    function renderRanking(rows) {
        const table = document.querySelector('[data-dashboard-ranking-table]');
        const body = document.querySelector('[data-dashboard-ranking]');

        if (!table || !body) {
            return;
        }

        body.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            return;
        }

        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            const cells = [
                `#${row.rank}`,
                row.plate_number || 'N/A',
                row.owner_name || 'N/A',
                row.category || 'N/A',
                row.total_entries_count ?? 0,
                row.entries_today_count_from_logs ?? 0,
            ];

            cells.forEach(function (value, index) {
                const td = document.createElement('td');

                if (index === 0 || index === 1 || index === 4) {
                    const strong = document.createElement('strong');
                    strong.textContent = value;
                    td.appendChild(strong);
                } else {
                    td.textContent = value;
                }

                tr.appendChild(td);
            });

            body.appendChild(tr);
        });
    }

    function renderTrafficSummary(summary) {
        if (!summary || typeof summary !== 'object') {
            return;
        }

        Object.entries(summary).forEach(function ([period, row]) {
            const periodRow = document.querySelector(`[data-dashboard-period="${period}"]`);

            if (!periodRow) {
                return;
            }

            ['entries', 'exits', 'registered_scans', 'guest_observations'].forEach(function (metric) {
                const node = periodRow.querySelector(`[data-dashboard-period-metric="${metric}"]`);

                if (node) {
                    node.textContent = row?.[metric] ?? 0;
                }
            });
        });
    }

    /* UI Phase 4: "Needs attention" list. */
    function renderAttention(items) {
        const list = document.querySelector('[data-dashboard-attention]');

        if (!list || !Array.isArray(items)) {
            return;
        }

        if (items.length === 0) {
            const clear = document.createElement('li');
            clear.className = 'attention-clear';
            clear.textContent = 'All clear. No anomalies, overstays, lost passes or no-pass alerts.';
            list.replaceChildren(clear);
            return;
        }

        list.replaceChildren(...items.map(function (item) {
            const li = document.createElement('li');
            const badge = document.createElement('span');
            const text = document.createElement('div');
            const title = document.createElement('strong');
            const detail = document.createElement('span');
            const time = document.createElement('time');
            const action = document.createElement('a');

            badge.className = `badge badge-tone-${item.tone || 'neutral'}`;
            badge.textContent = item.label;
            title.textContent = item.title;
            detail.className = 'text-muted';
            detail.textContent = item.detail;
            text.append(title, detail);
            time.textContent = item.time;
            action.className = 'button button-secondary button-sm';
            action.href = item.action_url;
            action.textContent = item.action_label;
            li.append(badge, text, time, action);

            return li;
        }));
    }

    /* UI Phase 4: entries and exits per hour today (plain SVG, works offline). */
    function renderChart(hourly) {
        const box = document.querySelector('[data-dashboard-chart]');

        if (!box || !Array.isArray(hourly) || hourly.length === 0) {
            return;
        }

        const ns = 'http://www.w3.org/2000/svg';
        const width = 480;
        const height = 170;
        const top = 10;
        const bottom = 22;
        const left = 22;
        const plotHeight = height - top - bottom;
        const slot = (width - left) / hourly.length;
        const barWidth = Math.max(2, (slot - 3) / 2);
        const max = Math.max(1, ...hourly.map((row) => Math.max(row.entries, row.exits)));
        const svg = document.createElementNS(ns, 'svg');
        const nowHour = Number(new Intl.DateTimeFormat('en-US', { hour: 'numeric', hour12: false, timeZone: 'Asia/Manila' }).format(new Date())) % 24;

        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('preserveAspectRatio', 'none');

        [0, 0.5, 1].forEach(function (ratio) {
            const y = top + plotHeight - plotHeight * ratio;
            const line = document.createElementNS(ns, 'line');
            line.setAttribute('x1', left);
            line.setAttribute('x2', width);
            line.setAttribute('y1', y);
            line.setAttribute('y2', y);
            line.setAttribute('class', 'chart-grid');
            svg.append(line);

            const label = document.createElementNS(ns, 'text');
            label.setAttribute('x', 0);
            label.setAttribute('y', y + 3);
            label.setAttribute('class', 'chart-axis');
            label.textContent = Math.round(max * ratio);
            svg.append(label);
        });

        hourly.forEach(function (row, index) {
            const x = left + index * slot + 1;

            if (row.hour === nowHour) {
                const now = document.createElementNS(ns, 'rect');
                now.setAttribute('x', x - 1);
                now.setAttribute('y', top);
                now.setAttribute('width', slot);
                now.setAttribute('height', plotHeight);
                now.setAttribute('class', 'chart-now');
                svg.append(now);
            }

            [['entries', 0], ['exits', 1]].forEach(function ([key, offset]) {
                const value = row[key] || 0;
                const barHeight = (value / max) * plotHeight;
                const rect = document.createElementNS(ns, 'rect');
                const title = document.createElementNS(ns, 'title');

                rect.setAttribute('x', x + offset * (barWidth + 1));
                rect.setAttribute('y', top + plotHeight - barHeight);
                rect.setAttribute('width', barWidth);
                rect.setAttribute('height', Math.max(value > 0 ? 1.5 : 0, barHeight));
                rect.setAttribute('class', `chart-bar chart-${key}`);
                title.textContent = `${row.label}: ${value} ${key}`;
                rect.append(title);
                svg.append(rect);
            });

            if (row.hour % 3 === 0) {
                const label = document.createElementNS(ns, 'text');
                label.setAttribute('x', x);
                label.setAttribute('y', height - 6);
                label.setAttribute('class', 'chart-axis');
                label.textContent = row.label.replace(':00', '');
                svg.append(label);
            }
        });

        box.replaceChildren(svg);
    }

    renderChart(payload.hourly || []);

    function renderState(body) {
        const metrics = body.metrics || {};

        Object.entries(metrics).forEach(function ([name, value]) {
            setMetric(name, value);
        });

        renderTrafficSummary(body.traffic_summary || {});
        renderStream(
            'rfid',
            body.recent_rfid_scans || [],
            'No RFID scans yet',
            'Scans from the gate kiosks and readers, and Settings › Test Scan, appear here.'
        );
        renderStream(
            'events',
            body.latest_events || [],
            'No vehicle logs yet',
            'Event logs will appear after scans and manual entries.'
        );
        renderRanking(body.frequent_entry_vehicles || []);
        renderAttention(body.attention);
        renderChart(body.hourly);
    }

    async function refreshDashboard() {
        if (!payload.routes?.liveState) {
            return;
        }

        try {
            const response = await window.ui.liveFetch(payload.routes.liveState, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Dashboard state unavailable.');
            }

            renderState(await response.json());
        } catch (error) {
            // Keep the last good dashboard state visible during a transient poll failure.
        }
    }

    refreshDashboard();
    window.setInterval(refreshDashboard, 2000);
})();
