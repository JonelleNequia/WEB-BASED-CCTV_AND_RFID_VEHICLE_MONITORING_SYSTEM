// Calibration editor (public/js/calibration-editor.js), run with: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync(new URL('../../public/js/calibration-editor.js', import.meta.url), 'utf8'), context);
const { Editor, toPx, selfIntersects } = context.window.CalibrationEditor;

// A 16:9 picture letterboxed in two different canvases.
const WIDE = { x: 0, y: 60, width: 800, height: 450 };
const SMALL = { x: 100, y: 0, width: 400, height: 225 };
const SQUARE = [{ x: 0.2, y: 0.2 }, { x: 0.8, y: 0.2 }, { x: 0.8, y: 0.8 }, { x: 0.2, y: 0.8 }];
const LINE = { x1: 0.1, y1: 0.5, x2: 0.9, y2: 0.5, in_side: 1 };

const plain = (value) => JSON.parse(JSON.stringify(value));
const near = (actual, expected, digits = 6) => assert.equal(Number(actual.toFixed(digits)), Number(expected.toFixed(digits)));
const px = (point, view) => toPx(point, view);
const drag = (editor, from, to, view, options = {}) => {
    editor.pointerDown(from, view, options);
    editor.pointerMove({ x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 }, view);
    editor.pointerMove(to, view);
    return editor.pointerUp(view);
};

test('a saved zone and line load closed and save back unchanged', () => {
    const editor = new Editor({ mask: SQUARE, line: LINE });
    assert.equal(editor.closed, true);
    assert.deepEqual(plain(editor.serialize()), { calibration_mask: SQUARE, calibration_line: LINE });
    assert.equal(editor.canUndo(), false);
});

test('a zone is drawn point by point and closed by clicking point 1', () => {
    const editor = new Editor({});
    editor.tool = 'mask';
    const clicks = [{ x: 100, y: 100 }, { x: 500, y: 100 }, { x: 500, y: 400 }];
    clicks.forEach((p) => editor.pointerDown(p, WIDE));
    editor.pointerDown({ x: 501, y: 401 }, WIDE); // the second click of a double click
    assert.equal(editor.mask.length, 3);
    assert.equal(editor.closed, false);
    assert.match(editor.problems().errors[0], /Close the zone/);

    assert.equal(editor.pointerDown({ x: 103, y: 98 }, WIDE).action, 'close');
    assert.equal(editor.closed, true);
    near(editor.mask[1].x, 500 / 800);
    near(editor.mask[1].y, (100 - 60) / 450);
    assert.deepEqual(plain(editor.problems().errors), []);
});

test('dragging a point moves it, and undo / redo bring it back and forth', () => {
    const editor = new Editor({ mask: SQUARE, line: null });
    assert.equal(drag(editor, px(SQUARE[2], WIDE), { x: 720, y: 420 }, WIDE), true);
    near(editor.mask[2].x, 720 / 800);
    near(editor.mask[2].y, (420 - 60) / 450);

    assert.equal(editor.undo(), true);
    assert.deepEqual(plain(editor.mask[2]), SQUARE[2]);
    assert.equal(editor.redo(), true);
    near(editor.mask[2].x, 0.9);
    assert.equal(editor.canRedo(), false);
});

test('the same drag on the picture gives the same saved point at any screen size', () => {
    const results = [WIDE, SMALL].map((view) => {
        const editor = new Editor({ mask: SQUARE, line: LINE });
        // Point 1 to 30% / 40% of the picture, wherever the picture is on screen.
        drag(editor, px(SQUARE[0], view), px({ x: 0.3, y: 0.4 }, view), view);
        return plain(editor.serialize());
    });
    assert.deepEqual(results[0], results[1]);
    near(results[0].calibration_mask[0].x, 0.3);
    near(results[0].calibration_mask[0].y, 0.4);
});

test('a point cannot be dragged off the picture', () => {
    const editor = new Editor({ mask: SQUARE });
    drag(editor, px(SQUARE[0], WIDE), { x: -50, y: 10 }, WIDE); // into the letterbox
    assert.deepEqual(plain(editor.mask[0]), { x: 0, y: 0 });
});

test('the "+" on an edge adds a point there; a double click on an edge adds one where clicked', () => {
    const editor = new Editor({ mask: SQUARE });
    const mid = px({ x: 0.5, y: 0.2 }, WIDE);
    drag(editor, mid, { x: mid.x, y: mid.y - 40 }, WIDE);
    assert.equal(editor.mask.length, 5);
    near(editor.mask[1].x, 0.5);
    assert.ok(editor.mask[1].y < 0.2);

    const onEdge = px({ x: 0.8, y: 0.65 }, WIDE); // right edge, not its middle
    assert.equal(editor.doubleClick(onEdge, WIDE), true);
    assert.equal(editor.mask.length, 6);
    near(editor.mask[3].x, 0.8);
    near(editor.mask[3].y, 0.65);

    editor.undo();
    editor.undo();
    assert.deepEqual(plain(editor.mask), SQUARE);
});

test('a point is removed, but a zone keeps at least 3 points', () => {
    const editor = new Editor({ mask: SQUARE });
    editor.pointerDown(px(SQUARE[1], WIDE), WIDE);
    editor.pointerUp(WIDE);
    assert.deepEqual(plain(editor.selected), { type: 'vertex', index: 1 });
    assert.equal(editor.removeSelected(), true);
    assert.equal(editor.mask.length, 3);
    assert.equal(editor.canRemoveVertex(), false);
    assert.equal(editor.removeVertex(0), false);
    assert.equal(editor.mask.length, 3);
    editor.undo();
    assert.equal(editor.mask.length, 4);
});

test('dragging inside the zone moves all of it, never past the picture', () => {
    const editor = new Editor({ mask: SQUARE });
    drag(editor, px({ x: 0.5, y: 0.5 }, WIDE), px({ x: 0.6, y: 0.45 }, WIDE), WIDE);
    near(editor.mask[0].x, 0.3);
    near(editor.mask[0].y, 0.15);
    near(editor.mask[2].x, 0.9);

    drag(editor, px({ x: 0.6, y: 0.5 }, WIDE), px({ x: 1.5, y: 0.5 }, WIDE), WIDE);
    near(Math.max(...editor.mask.map((p) => p.x)), 1);
    near(Math.min(...editor.mask.map((p) => p.x)), 0.4);
});

test('the line: drawn, ends dragged, moved whole, flipped by its arrow', () => {
    const editor = new Editor({ mask: SQUARE });
    editor.tool = 'line';
    // A click is not a line.
    editor.pointerDown(px({ x: 0.5, y: 0.5 }, WIDE), WIDE);
    assert.equal(editor.pointerUp(WIDE), false);
    assert.equal(editor.line, null);

    drag(editor, px({ x: 0.1, y: 0.5 }, WIDE), px({ x: 0.9, y: 0.5 }, WIDE), WIDE, { tool: 'line' });
    near(editor.line.x1, 0.1);
    near(editor.line.x2, 0.9);
    assert.equal(editor.line.in_side, 1);

    drag(editor, px({ x: 0.9, y: 0.5 }, WIDE), px({ x: 0.9, y: 0.6 }, WIDE), WIDE);
    near(editor.line.y2, 0.6);
    near(editor.line.y1, 0.5);

    // The middle moves the whole line (away from the arrow, which sits there).
    drag(editor, px({ x: 0.3, y: 0.525 }, WIDE), px({ x: 0.3, y: 0.425 }, WIDE), WIDE);
    near(editor.line.y1, 0.4);
    near(editor.line.y2, 0.5);

    const a = px({ x: editor.line.x1, y: editor.line.y1 }, WIDE);
    const b = px({ x: editor.line.x2, y: editor.line.y2 }, WIDE);
    const tip = context.window.CalibrationEditor.arrowGeometry(a, b, 1).tip;
    assert.equal(editor.pointerDown(tip, WIDE, { tool: 'mask' }).action, 'flip');
    assert.equal(editor.line.in_side, -1);
    editor.undo();
    assert.equal(editor.line.in_side, 1);
});

test('a new line keeps the IN side of the old one', () => {
    const editor = new Editor({ mask: SQUARE, line: { ...LINE, in_side: -1 } });
    drag(editor, px({ x: 0.5, y: 0.1 }, WIDE), px({ x: 0.5, y: 0.9 }, WIDE), WIDE, { tool: 'line' });
    near(editor.line.x1, 0.5);
    assert.equal(editor.line.in_side, -1);
});

test('crossing edges block saving; a line outside the zone only warns', () => {
    assert.equal(selfIntersects(SQUARE), false);
    const bow = [{ x: 0.2, y: 0.2 }, { x: 0.8, y: 0.8 }, { x: 0.8, y: 0.2 }, { x: 0.2, y: 0.8 }];
    assert.equal(selfIntersects(bow), true);
    const crossed = new Editor({ mask: bow });
    assert.match(crossed.problems().errors[0], /edges cross/);

    assert.deepEqual(plain(new Editor({ mask: SQUARE, line: LINE }).problems()), { errors: [], warnings: [] });
    const outside = new Editor({ mask: SQUARE, line: { x1: 0.1, y1: 0.95, x2: 0.9, y2: 0.95, in_side: 1 } });
    assert.deepEqual(plain(outside.problems().errors), []);
    assert.match(outside.problems().warnings[0], /outside the zone/);
    const short = new Editor({ mask: SQUARE, line: { x1: 0.4, y1: 0.5, x2: 0.6, y2: 0.5, in_side: 1 } });
    assert.match(short.problems().warnings[0], /does not reach/);
});

test('clear can be undone', () => {
    const editor = new Editor({ mask: SQUARE, line: LINE });
    editor.clear();
    assert.deepEqual(plain(editor.serialize()), { calibration_mask: null, calibration_line: null });
    editor.undo();
    assert.deepEqual(plain(editor.serialize()), { calibration_mask: SQUARE, calibration_line: LINE });
});

test('"Discard changes" / "Reset to saved" put the saved shapes back, and can be undone', () => {
    const saved = { mask: SQUARE, line: LINE };
    const editor = new Editor(saved);
    assert.equal(editor.matches(saved), true);
    drag(editor, px(SQUARE[0], WIDE), px({ x: 0.1, y: 0.1 }, WIDE), WIDE);
    assert.equal(editor.matches(saved), false);

    assert.equal(editor.replace(saved), true);
    assert.equal(editor.matches(saved), true);
    assert.equal(editor.replace(saved), false); // nothing to change
    editor.undo();
    near(editor.mask[0].x, 0.1);
    editor.redo();
    assert.equal(editor.matches(saved), true);
});

test('a new edit after undo drops the redo steps', () => {
    const editor = new Editor({ mask: SQUARE, line: LINE });
    editor.flip();
    editor.undo();
    assert.equal(editor.canRedo(), true);
    editor.clear();
    assert.equal(editor.canRedo(), false);
});
