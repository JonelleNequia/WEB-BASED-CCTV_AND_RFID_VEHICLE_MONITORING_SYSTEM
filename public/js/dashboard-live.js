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
        const empty = document.querySelector('[data-dashboard-ranking-empty]');

        if (!table || !body || !empty) {
            return;
        }

        body.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            table.hidden = true;
            empty.hidden = false;
            return;
        }

        table.hidden = false;
        empty.hidden = true;

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

    function renderState(body) {
        const metrics = body.metrics || {};

        Object.entries(metrics).forEach(function ([name, value]) {
            setMetric(name, value);
        });

        renderStream(
            'rfid',
            body.recent_rfid_scans || [],
            'No RFID scans yet',
            'Start scanning from the RFID Desk.'
        );
        renderStream(
            'events',
            body.latest_events || [],
            'No vehicle logs yet',
            'Event logs will appear after scans and manual entries.'
        );
        renderRanking(body.frequent_entry_vehicles || []);
    }

    async function refreshDashboard() {
        if (!payload.routes?.liveState) {
            return;
        }

        try {
            const response = await fetch(payload.routes.liveState, {
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
