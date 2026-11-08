import test from 'node:test';
import assert from 'node:assert/strict';
import { centredView, fitScales } from '../../resources/js/liveclass-whiteboard/board-view.js';

// LF-Core-LiveClass § Whiteboard: 100% of a document is the whole page on screen.

const slide = { width: 1600, height: 900 };

test('a landscape slide on a wide screen is fitted by height, as before', () => {
    const scales = fitScales({ width: 1400, height: 800 }, slide, 24);

    assert.equal(scales.height, (800 - 48) / 900);
    assert.equal(scales.page, scales.height);
    assert.ok(scales.width > scales.height);
});

test('a landscape slide on a portrait phone is fitted by width, so the whole page shows', () => {
    const scales = fitScales({ width: 330, height: 620 }, slide, 24);

    assert.equal(scales.width, (330 - 48) / 1600);
    assert.equal(scales.page, scales.width);
    assert.ok(scales.height > scales.width * 3, 'fit-to-height would overflow the phone several times over');
});

test('the whole page view is centred on both axes and stays inside the screen', () => {
    const screen = { width: 330, height: 620 };
    const view = centredView(screen, slide, fitScales(screen, slide, 24).page);

    assert.equal(view.x, 24);
    assert.ok(view.y > 24);
    assert.ok(view.x + slide.width * view.scale <= screen.width);
    assert.ok(view.y + slide.height * view.scale <= screen.height);
});

test('a page fitted by height keeps the top margin, as the old fit-to-height did', () => {
    const screen = { width: 1400, height: 800 };
    const view = centredView(screen, slide, fitScales(screen, slide, 24).page);

    assert.equal(view.y, 24);
});

test('a missing page or screen size falls back to 1', () => {
    assert.deepEqual(fitScales({ width: 0, height: 0 }, slide, 24), { height: 1, width: 1, page: 1 });
    assert.deepEqual(fitScales({ width: 300, height: 600 }, null, 24), { height: 1, width: 1, page: 1 });
});
