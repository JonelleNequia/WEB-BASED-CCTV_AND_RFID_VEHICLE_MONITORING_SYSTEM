/*
 * Calibration editor: the zone (polygon) and trigger line of one gate.
 *
 * No DOM here (tests run it in Node): the page passes pointer positions in
 * canvas pixels and the "view", the rectangle where the camera picture is
 * shown inside the canvas (letterboxed). Shapes are kept normalized (0-1)
 * to the camera picture, the frame the detector scales them to, so they
 * are the same at every screen size.
 *
 *   const editor = new CalibrationEditor.Editor({ mask, line });
 *   editor.pointerDown(point, view, { touch, tool });  // tool: 'mask' | 'line'
 *   editor.pointerMove(point, view); editor.pointerUp();
 *   CalibrationEditor.draw(ctx, editor, view);
 *
 * Side +1 of the line is to the right of its direction in image
 * coordinates (below a line drawn left to right), as in the detector.
 */
(function (root) {
    const HIT_MOUSE = 12;
    const HIT_TOUCH = 22;
    const MIN_LINE_PX = 8;
    const HISTORY_LIMIT = 100;

    const clamp = (value) => Math.min(Math.max(Number.isFinite(value) ? value : 0, 0), 1);
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const copy = (value) => (value === null || value === undefined ? null : JSON.parse(JSON.stringify(value)));

    function toPx(point, view) {
        return { x: view.x + point.x * view.width, y: view.y + point.y * view.height };
    }

    function toNorm(point, view) {
        return {
            x: clamp(view.width ? (point.x - view.x) / view.width : 0),
            y: clamp(view.height ? (point.y - view.y) / view.height : 0),
        };
    }

    function lineEnds(line) {
        return line ? [{ x: line.x1, y: line.y1 }, { x: line.x2, y: line.y2 }] : [];
    }

    function distanceToSegment(p, a, b) {
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const lengthSq = dx * dx + dy * dy;
        const t = lengthSq ? Math.min(Math.max(((p.x - a.x) * dx + (p.y - a.y) * dy) / lengthSq, 0), 1) : 0;
        const nearest = { x: a.x + t * dx, y: a.y + t * dy };

        return { distance: distance(p, nearest), point: nearest, t };
    }

    function cross(o, a, b) {
        return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
    }

    /* Proper or touching intersection of segments ab and cd. */
    function segmentsIntersect(a, b, c, d) {
        const d1 = cross(c, d, a);
        const d2 = cross(c, d, b);
        const d3 = cross(a, b, c);
        const d4 = cross(a, b, d);
        if (((d1 > 0 && d2 < 0) || (d1 < 0 && d2 > 0)) && ((d3 > 0 && d4 < 0) || (d3 < 0 && d4 > 0))) {
            return true;
        }
        const onSegment = (p, q, r) => Math.min(p.x, r.x) <= q.x && q.x <= Math.max(p.x, r.x)
            && Math.min(p.y, r.y) <= q.y && q.y <= Math.max(p.y, r.y);

        return (d1 === 0 && onSegment(c, a, d)) || (d2 === 0 && onSegment(c, b, d))
            || (d3 === 0 && onSegment(a, c, b)) || (d4 === 0 && onSegment(a, d, b));
    }

    function pointInPolygon(p, polygon) {
        let inside = false;
        for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            const a = polygon[i];
            const b = polygon[j];
            if ((a.y > p.y) !== (b.y > p.y) && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x) {
                inside = !inside;
            }
        }

        return inside;
    }

    /* Two edges that are not neighbours cross: the detector cannot use such a zone. */
    function selfIntersects(polygon) {
        const n = Array.isArray(polygon) ? polygon.length : 0;
        if (n < 4) {
            return false;
        }
        for (let i = 0; i < n; i++) {
            for (let j = i + 1; j < n; j++) {
                if (j === i + 1 || (i === 0 && j === n - 1)) {
                    continue; // neighbours share a point
                }
                if (segmentsIntersect(polygon[i], polygon[(i + 1) % n], polygon[j], polygon[(j + 1) % n])) {
                    return true;
                }
            }
        }

        return false;
    }

    /* 'crosses' | 'inside' (both ends inside, never reaches an edge) | 'partial' | 'outside'. */
    function lineAgainstZone(line, polygon) {
        if (!line || !Array.isArray(polygon) || polygon.length < 3) {
            return null;
        }
        const [a, b] = lineEnds(line);
        let edgeHits = 0;
        for (let i = 0; i < polygon.length; i++) {
            if (segmentsIntersect(a, b, polygon[i], polygon[(i + 1) % polygon.length])) {
                edgeHits++;
            }
        }
        const insideA = pointInPolygon(a, polygon);
        const insideB = pointInPolygon(b, polygon);

        if (edgeHits === 0) {
            return insideA && insideB ? 'inside' : 'outside';
        }

        return !insideA && !insideB && edgeHits >= 2 ? 'crosses' : 'partial';
    }

    /* The IN arrow, in pixels: from the middle of the line toward the IN side. */
    function arrowGeometry(a, b, inSide) {
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const length = Math.hypot(dx, dy);
        if (length < 4) {
            return null;
        }
        const size = Math.max(28, Math.min(70, length * 0.25));
        const nx = (-dy / length) * inSide;
        const ny = (dx / length) * inSide;
        const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };

        return { mid, tip: { x: mid.x + nx * size, y: mid.y + ny * size }, nx, ny };
    }

    class Editor {
        constructor(saved) {
            this.load(saved || {});
            this.tool = 'mask';
            this.hover = null;
            this.selected = null;
            this.drag = null;
            this.cursor = null;
        }

        /* Shapes as saved (normalized). A saved zone is a closed polygon. */
        load(saved) {
            const mask = Array.isArray(saved.mask) && saved.mask.length >= 3 ? saved.mask : null;
            this.mask = mask ? mask.map((p) => ({ x: clamp(Number(p.x)), y: clamp(Number(p.y)) })) : [];
            this.closed = mask !== null;
            this.line = saved.line ? {
                x1: clamp(Number(saved.line.x1)), y1: clamp(Number(saved.line.y1)),
                x2: clamp(Number(saved.line.x2)), y2: clamp(Number(saved.line.y2)),
                in_side: Number(saved.line.in_side) < 0 ? -1 : 1,
            } : null;
            this.undoStack = [];
            this.redoStack = [];
            this.selected = null;
            this.drag = null;
        }

        snapshot() {
            return { mask: copy(this.mask), closed: this.closed, line: copy(this.line) };
        }

        restore(state) {
            this.mask = copy(state.mask) || [];
            this.closed = state.closed;
            this.line = copy(state.line);
            this.selected = null;
            this.drag = null;
        }

        /* Remember the shapes before a change (for undo). */
        record(before) {
            this.undoStack.push(before || this.snapshot());
            if (this.undoStack.length > HISTORY_LIMIT) {
                this.undoStack.shift();
            }
            this.redoStack = [];
        }

        change(mutate) {
            const before = this.snapshot();
            mutate();
            if (JSON.stringify(before) !== JSON.stringify(this.snapshot())) {
                this.record(before);
                return true;
            }

            return false;
        }

        canUndo() {
            return this.undoStack.length > 0;
        }

        canRedo() {
            return this.redoStack.length > 0;
        }

        undo() {
            if (!this.canUndo()) {
                return false;
            }
            this.redoStack.push(this.snapshot());
            this.restore(this.undoStack.pop());

            return true;
        }

        redo() {
            if (!this.canRedo()) {
                return false;
            }
            this.undoStack.push(this.snapshot());
            this.restore(this.redoStack.pop());

            return true;
        }

        /* What is under the pointer (pixels), the most precise target first. */
        hitTest(p, view, touch) {
            const radius = touch ? HIT_TOUCH : HIT_MOUSE;
            const points = this.mask.map((q) => toPx(q, view));

            let best = null;
            points.forEach((q, index) => {
                const d = distance(p, q);
                if (d <= radius && (!best || d < best.d)) {
                    best = { type: 'vertex', index, d };
                }
            });
            if (best) {
                return best;
            }

            if (this.line) {
                const [a, b] = lineEnds(this.line).map((q) => toPx(q, view));
                const ends = [[1, distance(p, a)], [2, distance(p, b)]].filter(([, d]) => d <= radius).sort((x, y) => x[1] - y[1]);
                if (ends.length) {
                    return { type: 'line-end', end: ends[0][0] };
                }
                const arrow = arrowGeometry(a, b, this.line.in_side);
                if (arrow && (distance(p, arrow.tip) <= radius + 6 || distanceToSegment(p, arrow.mid, arrow.tip).distance <= radius / 2)) {
                    return { type: 'arrow' };
                }
            }

            if (this.closed) {
                for (let i = 0; i < points.length; i++) {
                    const a = points[i];
                    const b = points[(i + 1) % points.length];
                    const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
                    if (distance(p, mid) <= radius) {
                        return { type: 'midpoint', index: i };
                    }
                }
            }

            if (this.line) {
                const [a, b] = lineEnds(this.line).map((q) => toPx(q, view));
                if (distanceToSegment(p, a, b).distance <= radius) {
                    return { type: 'line-body' };
                }
            }

            if (this.closed) {
                for (let i = 0; i < points.length; i++) {
                    const hit = distanceToSegment(p, points[i], points[(i + 1) % points.length]);
                    if (hit.distance <= radius / 2) {
                        return { type: 'edge', index: i, point: hit.point };
                    }
                }
                if (pointInPolygon(p, points)) {
                    return { type: 'mask-body' };
                }
            }

            return null;
        }

        pointerDown(p, view, options = {}) {
            const tool = options.tool || this.tool;
            const hit = this.hitTest(p, view, options.touch);
            const before = this.snapshot();
            const start = toNorm(p, view);

            // Drawing the zone: point 1 closes it once there are three points.
            if (hit?.type === 'vertex' && !this.closed && hit.index === 0 && this.mask.length >= 3) {
                this.closeMask();
                return { action: 'close' };
            }

            if (hit?.type === 'arrow') {
                this.flip();
                return { action: 'flip' };
            }

            if (hit?.type === 'vertex') {
                this.selected = { type: 'vertex', index: hit.index };
                this.drag = { kind: 'vertex', index: hit.index, before };
                return { action: 'drag' };
            }

            if (hit?.type === 'line-end') {
                this.selected = { type: 'line' };
                this.drag = { kind: 'line-end', end: hit.end, before };
                return { action: 'drag' };
            }

            if (hit?.type === 'midpoint') {
                // "+" in the middle of an edge: a new point there, dragged right away.
                const a = this.mask[hit.index];
                const b = this.mask[(hit.index + 1) % this.mask.length];
                this.mask.splice(hit.index + 1, 0, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
                this.selected = { type: 'vertex', index: hit.index + 1 };
                this.drag = { kind: 'vertex', index: hit.index + 1, before, inserted: true };
                return { action: 'insert' };
            }

            if (hit?.type === 'line-body') {
                this.selected = { type: 'line' };
                this.drag = { kind: 'line-move', start, origin: copy(this.line), before };
                return { action: 'drag' };
            }

            if (tool === 'line') {
                // A new line, also inside the zone (replaces the old one, keeps its IN side).
                this.selected = { type: 'line' };
                this.drag = { kind: 'line-new', start, inSide: this.line ? this.line.in_side : 1, before, previous: copy(this.line) };
                this.line = { x1: start.x, y1: start.y, x2: start.x, y2: start.y, in_side: this.drag.inSide };
                return { action: 'draw-line' };
            }

            if (hit?.type === 'edge' || hit?.type === 'mask-body') {
                this.selected = null;
                this.drag = { kind: 'mask-move', start, origin: copy(this.mask), before };
                return { action: 'drag' };
            }

            if (tool === 'mask' && !this.closed) {
                const last = this.mask[this.mask.length - 1];
                // A double click must not add the same point twice.
                if (last && distance(toPx(last, view), p) < 4) {
                    return { action: 'none' };
                }
                this.change(() => this.mask.push(start));
                this.selected = { type: 'vertex', index: this.mask.length - 1 };
                return { action: 'add-point' };
            }

            this.selected = null;
            return { action: 'none' };
        }

        pointerMove(p, view, options = {}) {
            this.cursor = p;
            if (!this.drag) {
                this.hover = this.hitTest(p, view, options.touch);
                return false;
            }

            const here = toNorm(p, view);
            const drag = this.drag;

            if (drag.kind === 'vertex') {
                this.mask[drag.index] = here;
            } else if (drag.kind === 'line-end') {
                this.line = drag.end === 1 ? { ...this.line, x1: here.x, y1: here.y } : { ...this.line, x2: here.x, y2: here.y };
            } else if (drag.kind === 'line-new') {
                this.line = { x1: drag.start.x, y1: drag.start.y, x2: here.x, y2: here.y, in_side: drag.inSide };
            } else if (drag.kind === 'line-move') {
                const o = drag.origin;
                const dx = Math.min(Math.max(here.x - drag.start.x, -Math.min(o.x1, o.x2)), 1 - Math.max(o.x1, o.x2));
                const dy = Math.min(Math.max(here.y - drag.start.y, -Math.min(o.y1, o.y2)), 1 - Math.max(o.y1, o.y2));
                this.line = { ...o, x1: o.x1 + dx, y1: o.y1 + dy, x2: o.x2 + dx, y2: o.y2 + dy };
            } else if (drag.kind === 'mask-move') {
                // The whole zone moves, but never past the edge of the picture.
                const xs = drag.origin.map((q) => q.x);
                const ys = drag.origin.map((q) => q.y);
                const dx = Math.min(Math.max(here.x - drag.start.x, -Math.min(...xs)), 1 - Math.max(...xs));
                const dy = Math.min(Math.max(here.y - drag.start.y, -Math.min(...ys)), 1 - Math.max(...ys));
                this.mask = drag.origin.map((q) => ({ x: q.x + dx, y: q.y + dy }));
            }

            return true;
        }

        pointerUp(view) {
            const drag = this.drag;
            this.drag = null;
            if (!drag) {
                return false;
            }

            if (drag.kind === 'line-new' && view) {
                const [a, b] = lineEnds(this.line).map((q) => toPx(q, view));
                if (distance(a, b) < MIN_LINE_PX) {
                    this.line = drag.previous; // a click, not a line
                    return false;
                }
            }

            if (JSON.stringify(drag.before) !== JSON.stringify(this.snapshot())) {
                this.record(drag.before);
                return true;
            }

            return false;
        }

        /* Pointer lost (cancelled, window left): finish the drag as it is. */
        pointerCancel() {
            return this.pointerUp(null);
        }

        /* Double click on an edge: a new point exactly there. */
        doubleClick(p, view, options = {}) {
            const hit = this.hitTest(p, view, options.touch);
            if (hit?.type !== 'edge') {
                return false;
            }

            return this.change(() => {
                this.mask.splice(hit.index + 1, 0, toNorm(hit.point, view));
                this.selected = { type: 'vertex', index: hit.index + 1 };
            });
        }

        closeMask() {
            if (this.closed || this.mask.length < 3) {
                return false;
            }

            return this.change(() => {
                this.closed = true;
            });
        }

        canRemoveVertex() {
            return !this.closed || this.mask.length > 3;
        }

        removeVertex(index) {
            if (index < 0 || index >= this.mask.length || !this.canRemoveVertex()) {
                return false;
            }

            return this.change(() => {
                this.mask.splice(index, 1);
                this.selected = null;
            });
        }

        /* Delete / Backspace: the selected point (or the selected line). */
        removeSelected() {
            if (this.selected?.type === 'vertex') {
                return this.removeVertex(this.selected.index);
            }
            if (this.selected?.type === 'line') {
                return this.change(() => {
                    this.line = null;
                    this.selected = null;
                });
            }

            return false;
        }

        flip() {
            if (!this.line) {
                return false;
            }

            return this.change(() => {
                this.line = { ...this.line, in_side: this.line.in_side < 0 ? 1 : -1 };
            });
        }

        clear() {
            return this.change(() => {
                this.mask = [];
                this.closed = false;
                this.line = null;
                this.selected = null;
            });
        }

        /* Errors block saving; warnings do not. */
        problems() {
            const errors = [];
            const warnings = [];

            if (!this.closed && this.mask.length > 0) {
                errors.push(this.mask.length < 3
                    ? 'The zone needs at least 3 points.'
                    : 'Close the zone: click point 1 or press Done.');
            }
            if (this.closed && selfIntersects(this.mask)) {
                errors.push('The zone\'s edges cross each other. Drag the points so the edges do not cross.');
            }
            if (this.closed && this.line && errors.length === 0) {
                const where = lineAgainstZone(this.line, this.mask);
                if (where === 'outside') {
                    warnings.push('The line is outside the zone: vehicles are counted only where the line is inside the zone.');
                } else if (where === 'inside') {
                    warnings.push('The line does not reach the zone\'s edges: a vehicle may pass beside it.');
                }
            }

            return { errors, warnings };
        }

        /* What the save request sends. */
        serialize() {
            return {
                calibration_mask: this.closed && this.mask.length >= 3 ? copy(this.mask) : null,
                calibration_line: copy(this.line),
            };
        }
    }

    /* ---------- Drawing ---------- */

    const COLORS = {
        zone: '#f59e0b',
        zoneFill: 'rgba(245, 158, 11, 0.16)',
        invalid: '#ef4444',
        invalidFill: 'rgba(239, 68, 68, 0.18)',
        line: '#22c55e',
        arrow: '#38bdf8',
        handle: '#ffffff',
        selected: '#fde047',
    };

    function handle(ctx, p, radius, fill, stroke, width = 2) {
        ctx.beginPath();
        ctx.arc(p.x, p.y, radius, 0, Math.PI * 2);
        ctx.fillStyle = fill;
        ctx.fill();
        ctx.lineWidth = width;
        ctx.strokeStyle = stroke;
        ctx.stroke();
    }

    function label(ctx, text, x, y) {
        ctx.font = '700 12px system-ui, sans-serif';
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
        ctx.strokeText(text, x, y);
        ctx.fillStyle = '#ffffff';
        ctx.fillText(text, x, y);
    }

    function draw(ctx, editor, view, options = {}) {
        const touch = !!options.touch;
        const size = touch ? 11 : 7;
        const hover = editor.drag ? null : editor.hover;
        const invalid = editor.closed && selfIntersects(editor.mask);
        const points = editor.mask.map((q) => toPx(q, view));
        const isSelected = (index) => editor.selected?.type === 'vertex' && editor.selected.index === index;

        // Zone
        if (points.length) {
            ctx.beginPath();
            points.forEach((q, i) => (i ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y)));
            if (editor.closed) {
                ctx.closePath();
                ctx.fillStyle = invalid ? COLORS.invalidFill : COLORS.zoneFill;
                ctx.fill();
            }
            ctx.lineWidth = hover?.type === 'mask-body' || hover?.type === 'edge' ? 4 : 3;
            ctx.strokeStyle = invalid ? COLORS.invalid : COLORS.zone;
            ctx.stroke();

            // Still drawing: a dashed line from the last point to the pointer.
            if (!editor.closed && editor.cursor && options.drawingMask) {
                const last = points[points.length - 1];
                ctx.save();
                ctx.setLineDash([6, 6]);
                ctx.beginPath();
                ctx.moveTo(last.x, last.y);
                ctx.lineTo(editor.cursor.x, editor.cursor.y);
                ctx.lineWidth = 2;
                ctx.strokeStyle = COLORS.zone;
                ctx.stroke();
                ctx.restore();
            }

            // "+" in the middle of every edge (adds a point).
            if (editor.closed) {
                points.forEach((a, i) => {
                    const b = points[(i + 1) % points.length];
                    const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
                    const active = hover?.type === 'midpoint' && hover.index === i;
                    handle(ctx, mid, active ? size : size - 2, active ? COLORS.zone : 'rgba(17, 24, 39, 0.75)', COLORS.zone, 1.5);
                    ctx.beginPath();
                    ctx.moveTo(mid.x - 3, mid.y);
                    ctx.lineTo(mid.x + 3, mid.y);
                    ctx.moveTo(mid.x, mid.y - 3);
                    ctx.lineTo(mid.x, mid.y + 3);
                    ctx.lineWidth = 1.5;
                    ctx.strokeStyle = active ? '#111827' : '#ffffff';
                    ctx.stroke();
                });
            }

            points.forEach((q, i) => {
                const active = hover?.type === 'vertex' && hover.index === i;
                const closable = !editor.closed && i === 0 && points.length >= 3;
                const fill = isSelected(i) ? COLORS.selected : (active || closable ? COLORS.zone : COLORS.handle);
                handle(ctx, q, active || isSelected(i) || closable ? size + 2 : size, fill, invalid ? COLORS.invalid : COLORS.zone, 2.5);
                label(ctx, String(i + 1), q.x + size + 4, q.y - size - 2);
            });
        }

        // Trigger line and the IN arrow
        if (editor.line) {
            const [a, b] = lineEnds(editor.line).map((q) => toPx(q, view));
            const lineActive = editor.selected?.type === 'line' || hover?.type === 'line-body';
            ctx.beginPath();
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.lineWidth = lineActive ? 6 : 4;
            ctx.strokeStyle = COLORS.line;
            ctx.stroke();

            const arrow = arrowGeometry(a, b, editor.line.in_side);
            if (arrow) {
                const { mid, tip, nx, ny } = arrow;
                const head = hover?.type === 'arrow' ? 13 : 10;
                ctx.strokeStyle = COLORS.arrow;
                ctx.fillStyle = COLORS.arrow;
                ctx.lineWidth = hover?.type === 'arrow' ? 5 : 4;
                ctx.beginPath();
                ctx.moveTo(mid.x, mid.y);
                ctx.lineTo(tip.x, tip.y);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(tip.x + nx * head, tip.y + ny * head);
                ctx.lineTo(tip.x - ny * head, tip.y + nx * head);
                ctx.lineTo(tip.x + ny * head, tip.y - nx * head);
                ctx.closePath();
                ctx.fill();
                label(ctx, 'IN', tip.x + nx * (head + 8) - 8, tip.y + ny * (head + 8) + 5);
                handle(ctx, mid, size - 2, lineActive ? COLORS.line : 'rgba(17, 24, 39, 0.75)', COLORS.line, 1.5);
            }

            [a, b].forEach((q, i) => {
                const active = hover?.type === 'line-end' && hover.end === i + 1;
                handle(ctx, q, active ? size + 2 : size, active ? COLORS.line : COLORS.handle, COLORS.line, 2.5);
            });
        }
    }

    /* Pointer cursor for what is under it. */
    function cursorFor(hit, tool, closed) {
        switch (hit?.type) {
            case 'vertex':
            case 'line-end':
                return 'grab';
            case 'midpoint':
            case 'arrow':
                return 'pointer';
            case 'line-body':
            case 'mask-body':
            case 'edge':
                return 'move';
            default:
                return tool === 'line' || !closed ? 'crosshair' : 'default';
        }
    }

    root.CalibrationEditor = {
        Editor, draw, cursorFor, toPx, toNorm, selfIntersects, lineAgainstZone, pointInPolygon, arrowGeometry,
    };
})(typeof window !== 'undefined' ? window : globalThis);
