/*
 * UI Phase 3: Registry › Vehicles.
 * - Tag picker: scan (USB/NFC reader types + Enter) or choose an available tag.
 * - Row click: side panel with details and the last 10 movements.
 * - Edit / Replace Tag drawers filled from the same JSON.
 */
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const dataNode = document.getElementById('registry-vehicle-data');
    const options = dataNode ? JSON.parse(dataNode.textContent || '{}') : {};

    /* ---------- "Others" free-text fields ---------- */

    function syncOther(select) {
        const field = document.getElementById(select.dataset.otherTarget);
        if (!field) {
            return;
        }
        const show = ['others', 'Others'].includes(select.value);
        field.hidden = !show;
        field.toggleAttribute('required', show);
    }

    document.querySelectorAll('[data-other-select]').forEach(function (select) {
        select.addEventListener('change', function () {
            syncOther(select);
            if (!document.getElementById(select.dataset.otherTarget)?.hidden) {
                document.getElementById(select.dataset.otherTarget).focus();
            }
        });
        syncOther(select);
    });

    /* ---------- Tag picker ---------- */

    function setResult(picker, text, state) {
        const result = picker.querySelector('[data-tag-result]');
        if (!result) {
            return;
        }
        result.textContent = text;
        result.dataset.state = state || '';
    }

    async function lookup(picker, rawUid) {
        const uid = String(rawUid || '').replace(/\s+/g, '').toUpperCase();
        const hidden = picker.querySelector('[data-tag-uid]');
        const select = picker.querySelector('[data-tag-select]');

        if (!uid) {
            hidden.value = '';
            setResult(picker, 'Waiting for a tag…', '');
            return;
        }

        select.value = '';
        hidden.value = '';
        setResult(picker, `Checking ${uid}…`, 'pending');

        try {
            const body = new FormData();
            body.append('uid', uid);
            if (picker.dataset.vehicleId) {
                body.append('vehicle_id', picker.dataset.vehicleId);
            }

            const response = await fetch(picker.dataset.lookupUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: body,
            });
            const result = await response.json();

            if (!response.ok) {
                setResult(picker, result.message || 'Could not check this tag.', 'error');
                return;
            }

            if (result.ok) {
                hidden.value = uid;
            }
            setResult(picker, `${uid}: ${result.message}`, result.ok ? 'ok' : 'error');
        } catch (error) {
            setResult(picker, 'Could not reach the server to check this tag.', 'error');
        }
    }

    function initPicker(picker) {
        const scan = picker.querySelector('[data-tag-scan]');
        const select = picker.querySelector('[data-tag-select]');
        const hidden = picker.querySelector('[data-tag-uid]');

        scan?.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                // The reader ends each UID with Enter; do not submit the form.
                event.preventDefault();
                lookup(picker, scan.value);
            }
        });
        scan?.addEventListener('change', function () {
            lookup(picker, scan.value);
        });

        select?.addEventListener('change', function () {
            if (select.value) {
                scan.value = '';
                hidden.value = '';
                setResult(picker, `${select.options[select.selectedIndex].text} will be assigned.`, 'ok');
            } else {
                setResult(picker, 'Waiting for a tag…', '');
            }
        });

        // Re-check a value restored after a validation error.
        if (scan?.value) {
            lookup(picker, scan.value);
        } else if (select?.value) {
            select.dispatchEvent(new Event('change'));
        }

        picker.closest('form')?.addEventListener('submit', function (event) {
            if (!hidden.value && !select.value) {
                event.preventDefault();
                setResult(picker, 'Scan a tag or choose an available one first.', 'error');
                scan?.focus();
            }
        });
    }

    function resetPicker(picker, vehicleId) {
        picker.dataset.vehicleId = vehicleId || '';
        picker.querySelector('[data-tag-scan]').value = '';
        picker.querySelector('[data-tag-uid]').value = '';
        picker.querySelector('[data-tag-select]').value = '';
        setResult(picker, 'Waiting for a tag…', '');
    }

    document.querySelectorAll('[data-tag-picker]').forEach(initPicker);

    /* ---------- Vehicle JSON → panel and drawers ---------- */

    async function loadVehicle(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            throw new Error('Vehicle could not be loaded.');
        }
        return response.json();
    }

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

    function badge(status, label) {
        const tone = {
            inside: 'success', active: 'success', assigned: 'success',
            outside: 'neutral', inactive: 'neutral', available: 'neutral', disabled: 'neutral',
            lost: 'critical', entry: 'info', exit: 'neutral',
        }[String(status).toLowerCase()] || 'neutral';
        return el('span', `badge badge-tone-${tone}`, label || status);
    }

    function renderPanel(vehicle) {
        const panel = document.querySelector('[data-vehicle-panel]');
        document.getElementById('vehicle-panel-title').textContent = vehicle.plate_number;

        const head = el('div', 'vehicle-panel-head');
        head.append(
            badge(vehicle.current_state, vehicle.current_state === 'inside' ? 'Inside' : 'Outside'),
            badge(vehicle.status, vehicle.status === 'active' ? 'Active' : 'Inactive')
        );

        const details = el('dl', 'vehicle-panel-details');
        [
            ['Owner', vehicle.vehicle_owner_name || '—'],
            ['Category', vehicle.category_label],
            ['Vehicle type', vehicle.vehicle_type],
            ['RFID tag', vehicle.tag ? `#${vehicle.tag.tag_number ?? '—'} · ${vehicle.tag.uid}` : 'No tag'],
            ['Last seen', vehicle.last_seen],
            ['Today', `${vehicle.entries_today} in · ${vehicle.exits_today} out`],
        ].forEach(function ([label, value]) {
            details.append(el('dt', null, label), el('dd', null, value));
        });

        if (vehicle.previous_tags?.length) {
            details.append(
                el('dt', null, 'Old tags'),
                el('dd', null, vehicle.previous_tags.map((tag) => `#${tag.tag_number ?? '—'} · ${tag.uid} (${tag.status})`).join(', '))
            );
        }

        const actions = el('div', 'button-row');
        const edit = el('button', 'button button-secondary button-sm', 'Edit');
        edit.type = 'button';
        edit.addEventListener('click', () => openEdit(vehicle));
        const replace = el('button', 'button button-secondary button-sm', vehicle.tag ? 'Replace Tag' : 'Assign Tag');
        replace.type = 'button';
        replace.addEventListener('click', () => openReplace(vehicle));
        actions.append(edit, replace);

        const movementsTitle = el('h3', 'vehicle-panel-subtitle', 'Last 10 movements');
        const list = el('ul', 'vehicle-movements');
        if (!vehicle.movements?.length) {
            list.append(el('li', 'text-muted', 'No movements yet.'));
        } else {
            vehicle.movements.forEach(function (movement) {
                const item = el('li');
                const link = el('a', null);
                link.href = movement.url;
                link.append(badge(movement.event_type.toLowerCase(), movement.event_type));
                item.append(link, el('span', null, movement.time), el('span', 'text-muted', movement.note || movement.source));
                list.append(item);
            });
        }

        panel.replaceChildren(head, details, actions, movementsTitle, list);
    }

    function setField(form, name, value) {
        const input = form.querySelector(`[data-field="${name}"]`);
        if (input) {
            input.value = value ?? '';
        }
    }

    function openEdit(vehicle) {
        const form = document.querySelector('[data-vehicle-form="edit"]');
        form.action = vehicle.urls.update;
        setField(form, 'id', vehicle.id);
        setField(form, 'tag_id', vehicle.tag ? vehicle.tag.id : '');
        setField(form, 'plate_number', vehicle.plate_number);
        setField(form, 'vehicle_owner_name', vehicle.vehicle_owner_name);

        const knownCategory = (options.categories || []).includes(vehicle.category);
        setField(form, 'category', knownCategory ? vehicle.category : 'others');
        setField(form, 'category_other', knownCategory ? '' : vehicle.category);

        const knownType = (options.vehicleTypes || []).includes(vehicle.vehicle_type);
        setField(form, 'vehicle_type', knownType ? vehicle.vehicle_type : 'Others');
        setField(form, 'vehicle_type_other', knownType ? '' : vehicle.vehicle_type);

        form.querySelectorAll('[data-other-select]').forEach(syncOther);
        form.querySelector('[data-edit-tag-note]').textContent = vehicle.tag
            ? `Tag #${vehicle.tag.tag_number ?? '—'} (${vehicle.tag.uid}). Change it with Replace Tag.`
            : 'This vehicle has no tag. Use Assign Tag first.';

        window.ui.closeDrawer('vehicle-panel');
        window.ui.openDrawer('edit-vehicle-drawer');
    }

    function openReplace(vehicle) {
        const form = document.querySelector('[data-vehicle-form="replace"]');
        form.action = vehicle.urls.replace_tag;
        setField(form, 'id', vehicle.id);
        form.querySelector('[data-replace-current]').textContent = `${vehicle.plate_number} · current tag ${vehicle.tag ? `#${vehicle.tag.tag_number ?? '—'} · ${vehicle.tag.uid}` : 'none'}`;
        form.querySelector('[data-old-tag-fieldset]').hidden = !vehicle.tag;
        document.getElementById('replace-tag-drawer-title').textContent = vehicle.tag ? 'Replace Tag' : 'Assign Tag';
        resetPicker(form.querySelector('[data-tag-picker]'), vehicle.id);

        window.ui.closeDrawer('vehicle-panel');
        window.ui.openDrawer('replace-tag-drawer');
    }

    async function withVehicle(url, callback) {
        try {
            callback(await loadVehicle(url));
        } catch (error) {
            window.ui.toast(error.message, 'error');
        }
    }

    document.querySelectorAll('[data-vehicle-row]').forEach(function (row) {
        function open(event) {
            if (event.target.closest('button, a, form, input, select')) {
                return;
            }
            window.ui.openDrawer('vehicle-panel');
            withVehicle(row.dataset.vehicleUrl, renderPanel);
        }

        row.addEventListener('click', open);
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open(event);
            }
        });
    });

    document.querySelectorAll('[data-vehicle-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            withVehicle(button.dataset.vehicleUrl, button.dataset.vehicleAction === 'edit' ? openEdit : openReplace);
        });
    });

    // Focus the scan field whenever a tag picker drawer opens.
    document.addEventListener('drawer:open', function (event) {
        event.target.querySelector('[data-tag-scan]')?.focus({ preventScroll: true });
    });
})();
