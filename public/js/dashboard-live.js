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
        const heading = document.createElement('strong');
        const paragraph = document.createElement('p');

        // Same look as <x-empty-state>.
        wrapper.className = 'empty-block';
        heading.textContent = title;
        paragraph.textContent = copy;
        wrapper.append(heading, paragraph);

        return wrapper;
    }

    function streamKey(item, index) {
        // Source IDs retain distinct movements even when all visible text is identical.
        return item.key || `${item.record_type || 'row'}:${item.id ?? index}`;
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

        container.querySelectorAll('.empty-block').forEach(function (node) {
            node.remove();
        });

        if (nodeMap.size === 0) {
            container.querySelectorAll('.stream-item').forEach(function (node) {
                node.remove();
            });
        }

        const seen = new Set();

        items.forEach(function (item, index) {
            const key = streamKey(item, index);
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
        const empty = document.querySelector('[data-dashboard-ranking-empty]');
        if (empty) {
            empty.hidden = Array.isArray(rows) && rows.length > 0;
        }

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

    // Phase 7 (visitor model): IN / OUT per period, category and gate; inside per category.
    function renderMovementCounts(counts, inside) {
        if (counts && typeof counts === 'object') {
            document.querySelectorAll('[data-movement]').forEach(function (node) {
                const value = node.dataset.movement.split('.').reduce(function (current, key) {
                    return current && typeof current === 'object' ? current[key] : undefined;
                }, counts);
                if (value !== undefined && value !== null) {
                    node.textContent = String(value);
                }
            });
            Object.entries(counts).forEach(function ([period, row]) {
                const body = document.querySelector(`[data-dashboard-gates="${period}"]`);
                if (!body) return;
                const gates = Object.entries(row.gates || {});
                body.replaceChildren(...gates.map(function ([code, gate]) {
                    const tr = document.createElement('tr');
                    const label = document.createElement('th');
                    label.scope = 'row';
                    label.textContent = gate.label;
                    tr.append(label);
                    ['in', 'out'].forEach(function (direction) {
                        const td = document.createElement('td');
                        td.dataset.movement = `${period}.gates.${code}.${direction}`;
                        td.textContent = gate[direction] ?? 0;
                        tr.append(td);
                    });
                    return tr;
                }));
                if (!gates.length) {
                    const td = document.createElement('td');
                    td.colSpan = 3;
                    td.className = 'text-muted';
                    td.textContent = 'No operational gates configured.';
                    const tr = document.createElement('tr');
                    tr.append(td);
                    body.append(tr);
                }
            });
        }

        if (inside && typeof inside === 'object') {
            Object.entries(inside).forEach(function ([category, row]) {
                const node = document.querySelector(`[data-dashboard-inside="${category}"]`);
                if (node) {
                    node.textContent = String(row.inside ?? 0);
                }
            });
        }
    }

    // Phase 6 (visitor model): Visitor Ranking (plates not in the Registry).
    function renderVisitorRanking(rows) {
        const body = document.querySelector('[data-dashboard-visitor-ranking]');
        const empty = document.querySelector('[data-dashboard-visitor-ranking-empty]');

        if (!body || !Array.isArray(rows)) {
            return;
        }

        body.innerHTML = '';
        if (empty) {
            empty.hidden = rows.length > 0;
        }

        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            const cell = function (content) {
                const td = document.createElement('td');
                if (content instanceof Node) {
                    td.appendChild(content);
                } else {
                    td.textContent = content;
                }
                tr.appendChild(td);
                return td;
            };
            const link = function (href, text, className) {
                const a = document.createElement('a');
                a.href = href;
                a.textContent = text;
                if (className) {
                    a.className = className;
                }
                return a;
            };
            const rank = document.createElement('strong');
            rank.textContent = `#${row.rank}`;
            cell(rank);
            const plate = link(row.profile_url, '');
            const plateText = document.createElement('strong');
            plateText.textContent = row.plate_number;
            plate.appendChild(plateText);
            cell(plate);
            const entries = cell('');
            const entriesText = document.createElement('strong');
            entriesText.textContent = row.entries_count;
            const seen = document.createElement('span');
            seen.className = 'table-subtext';
            seen.textContent = ` of ${row.visit_count} seen`;
            entries.append(entriesText, seen);
            cell(String(row.entries_today_count));
            cell(row.last_seen || '—');
            cell(link(row.register_url, 'Register this vehicle', 'button button-secondary button-sm')).className = 'row-actions';
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
            clear.textContent = 'No anomalies or unknown tags.';
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
            'No vehicles have passed yet',
            'Vehicles from both gates appear here.'
        );
        renderRanking(body.frequent_entry_vehicles || []);
        renderVisitorRanking(body.frequent_unregistered_visitors || []);
        renderMovementCounts(body.movement_counts || null, body.inside_by_category || null);
        renderAttention(body.attention);
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
