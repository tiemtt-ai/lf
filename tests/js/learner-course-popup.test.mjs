import test from 'node:test';
import assert from 'node:assert/strict';
import { fullscreenFor, fullscreenOff, installCoursePopup, toggleFullscreen } from '../../resources/js/learner-course-popup.js';

// The self-paced course popup (course.blade.php, ADR-0030): the frame its full screen button takes, the order it tries,
// and the "Tiếp tục xem" question, run on stand-in elements.

function el(tagName = 'DIV', extra = {}) {
    const on = {};

    return Object.assign({
        tagName, hidden: false, disabled: false, textContent: '', className: '', dataset: {}, on, children: [],
        addEventListener(type, handler) { (on[type] ??= []).push(handler); },
        fire(type, event = {}) { (on[type] ?? []).forEach((handler) => handler({ target: this, ...event })); },
        replaceChildren(...nodes) { this.children = nodes; },
        append(...nodes) { this.children.push(...nodes); },
        setAttribute() {},
        focus() { this.focused = true; },
        querySelector: () => null,
        querySelectorAll: () => [],
    }, extra);
}

const unlocked = { contains: () => false };
const locked = { contains: (name) => name === 'is-locked' };
const shown = (tagName, extra = {}) => ({ tagName, offsetParent: {}, ...extra });

test('the course popup takes the shown player full screen, else its stage, and the video alone where only that works', () => {
    const stage = { tagName: 'DIV' };
    const picks = [shown('VIDEO'), shown('IFRAME'), shown('AUDIO'), { tagName: 'VIDEO', offsetParent: null }, null]
        .map((media) => fullscreenFor(media, stage).map((part) => part?.tagName ?? null));
    assert.deepEqual(picks, [['VIDEO', 'VIDEO'], ['IFRAME', null], ['DIV', null], ['DIV', 'VIDEO'], ['DIV', null]]);
});

test('the course popup button leaves full screen first, else enters, else plays a video not loaded yet on iPhone', () => {
    const steps = [];
    const stage = { tagName: 'DIV', requestFullscreen: () => { steps.push('stage'); } };
    const resume = { hidden: false };
    const playFullscreen = () => { steps.push('play'); return true; };

    toggleFullscreen({ media: null, stage, resume, state: { playFullscreen }, doc: { fullscreenElement: {}, exitFullscreen: () => { steps.push('leave'); } } });
    assert.deepEqual(steps.splice(0), ['leave']);
    assert.equal(resume.hidden, false);

    toggleFullscreen({ media: null, stage, resume, state: { playFullscreen }, doc: {} });
    assert.deepEqual(steps.splice(0), ['stage']);

    // Safari on iPhone: only the video goes full screen, and a video without a loaded source refuses.
    const video = shown('VIDEO', { webkitEnterFullscreen: () => { throw new Error('not loaded'); } });
    toggleFullscreen({ media: video, stage, resume, state: { playFullscreen }, doc: {} });
    assert.deepEqual(steps.splice(0), ['play']);
    assert.equal(resume.hidden, true, 'the "Tiếp tục xem" question goes once the video plays');

    // Nothing to play: the question stays.
    const asked = { hidden: false };
    toggleFullscreen({ media: video, stage, resume: asked, state: { playFullscreen: () => false }, doc: {} });
    toggleFullscreen({ media: video, stage, resume: asked, state: null, doc: {} });
    assert.equal(asked.hidden, false);
});

test('the course popup button is off on a locked lesson, and where full screen can do nothing', () => {
    const stage = { tagName: 'DIV', requestFullscreen() {} };
    const main = { item: { classList: unlocked } };
    assert.equal(fullscreenOff(null, null, stage, {}), true, 'no lesson open');
    assert.equal(fullscreenOff({ item: { classList: locked } }, shown('VIDEO', { requestFullscreen() {} }), stage, {}), true);
    assert.equal(fullscreenOff(main, null, stage, {}), false);
    // Safari on iPhone: an embedded YouTube/Vimeo player has no full screen of its own here, a video has.
    const iphone = { tagName: 'DIV' };
    assert.equal(fullscreenOff(main, shown('IFRAME'), iphone, {}), true);
    assert.equal(fullscreenOff(main, shown('VIDEO', { webkitEnterFullscreen() {} }), iphone, {}), false);
    // An app's WebView that says it has no full screen for page elements.
    assert.equal(fullscreenOff(main, shown('IFRAME', { requestFullscreen() {} }), stage, { fullscreenEnabled: false }), true);
});

/** The popup wired on stand-in elements, with one lesson link whose page holds `items`. */
function coursePopup({ items = [], doc = {} } = {}) {
    const names = ['stage', 'more', 'more-list', 'progress', 'restart', 'resume', 'fullscreen', 'previous', 'next', 'no', 'title', 'viewed',
        'seconds', 'percent', 'resume-time', 'resume-restart', 'resume-continue', 'close'];
    const parts = Object.fromEntries(names.map((name) => [name, el()]));
    parts.resume.hidden = true;
    const modal = el('DIALOG', {
        open: false,
        dataset: { learnMessageLoading: 'Loading', learnMessageFailed: 'Failed' },
        showModal() { this.open = true; },
        close() { this.open = false; this.fire('close'); },
        querySelector: (selector) => parts[selector.match(/^\[data-learn-modal-(.+)\]$/)?.[1]] ?? null,
    });
    const link = { href: '/lessons/1', dataset: { learnOpenNo: '1', learnOpenTitle: 'Bài 1' } };
    const root = el('DIV', { querySelectorAll: (selector) => (selector === '[data-learn-open]' ? [link] : []) });
    const fetched = [];
    const page = { querySelector: (selector) => (selector === '.lf-learn-activities' && items ? { children: items } : null) };
    const document = {
        querySelector: (selector) => ({ '.lf-learn-course': root, '[data-learn-modal]': modal })[selector] ?? null,
        createElement: (tag) => el(tag.toUpperCase()),
        importNode: (node) => node,
        ...doc,
    };
    installCoursePopup(document, {
        location: { href: '/course' },
        setTimeout: (run) => run(),
        fetch: async (url) => { fetched.push(url); return { ok: true, text: async () => '' }; },
        DOMParser: class { parseFromString() { return page; } },
    });
    const open = async () => {
        root.fire('click', { target: { closest: () => link }, button: 0, preventDefault() {} });
        await new Promise((resolve) => setTimeout(resolve, 0));
    };

    return { parts, modal, open, fetched };
}

function videoLesson({ classList = unlocked, resume = '30', video = shown('VIDEO', { paused: true }) } = {}) {
    const play = el('BUTTON', { click() { this.clicked = (this.clicked ?? 0) + 1; } });
    const item = el('LI', {
        classList,
        dataset: { learnActivityType: 'video', learnActivityDuration: '100', learnActivityResume: resume, learnActivitySeconds: '20', learnActivityPercent: '20' },
        querySelector: (selector) => (selector === '[data-learn-play]' ? play : selector.startsWith('video') ? video : null),
    });

    return { item, play, video };
}

test('a video stopped part-way asks "Tiếp tục xem"; playing it by any button, the stage\'s own in full screen too, answers', async () => {
    const entered = [];
    const lesson = videoLesson({ video: shown('VIDEO', { paused: true, requestFullscreen: () => { entered.push('video'); } }) });
    const { parts, open } = coursePopup({ items: [lesson.item] });
    await open();
    assert.equal(parts.stage.children[0], lesson.item);
    assert.equal(parts.resume.hidden, false);
    assert.equal(parts['resume-time'].textContent, '00:00:30');
    assert.equal(parts['resume-continue'].focused, true);
    assert.equal(lesson.play.clicked, undefined, 'it waits for the answer');
    assert.equal(parts.fullscreen.disabled, false);

    parts.fullscreen.fire('click');
    assert.deepEqual(entered, ['video']);
    assert.equal(parts.resume.hidden, false, 'full screen alone does not answer');
    // The question sits outside the stage, so in full screen the stage's own play button starts the video instead.
    parts.stage.fire('play');
    assert.equal(parts.resume.hidden, true);
    assert.equal(parts.viewed.hidden, false);
});

test('a video not stopped part-way plays at once; a locked one neither plays nor asks, and its buttons are off', async () => {
    const fresh = videoLesson({ resume: '0' });
    const first = coursePopup({ items: [fresh.item] });
    await first.open();
    assert.equal(fresh.play.clicked, 1);
    assert.equal(first.parts.resume.hidden, true);

    const closed = videoLesson({ classList: locked });
    const second = coursePopup({ items: [closed.item] });
    await second.open();
    assert.equal(closed.play.clicked, undefined);
    assert.equal(second.parts.resume.hidden, true);
    assert.equal(second.parts.fullscreen.disabled, true);
    assert.equal(second.parts.restart.disabled, true);
});

test('closing the popup leaves full screen and reads the course figures again', async () => {
    const left = [];
    const { modal, open, fetched } = coursePopup({ items: [videoLesson().item],
        doc: { webkitFullscreenElement: {}, webkitExitFullscreen: () => { left.push('webkit'); } } });
    await open();
    modal.close();
    assert.deepEqual(left, ['webkit']);
    assert.deepEqual(fetched, ['/lessons/1', '/course']);
});

test('a lesson page that fails to load says so on the stage', async () => {
    const { parts, open } = coursePopup({ items: null });
    await open();
    assert.equal(parts.stage.children[0].textContent, 'Failed');
});
