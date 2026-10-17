import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const app = fs.readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('window.backendSidebar ='), app.indexOf('window.courseTemplateSectionCollapse ='));

function shell({ mobile = false, saved = null, unavailableStorage = false } = {}) {
    const stored = new Map(saved === null ? [] : [['lf.backend.sidebar.collapsed', saved]]);
    const listeners = new Set();
    const query = { matches: mobile, addEventListener: (_, callback) => listeners.add(callback), removeEventListener: (_, callback) => listeners.delete(callback) };
    const context = {
        window: { matchMedia: () => query, localStorage: {
            getItem: key => { if (unavailableStorage) throw new Error('blocked'); return stored.get(key) ?? null; },
            setItem: (key, value) => { if (unavailableStorage) throw new Error('blocked'); stored.set(key, value); },
        } },
        document: { documentElement: { classList: { remove() {} } } },
        Alpine: { mutateDom: callback => callback() },
    };
    vm.runInNewContext(source, context);
    const state = context.window.backendSidebar();
    let location = 'shell';
    let focused = null;
    const sidebar = {};
    state.$watch = () => {};
    state.$nextTick = callback => callback();
    state.$refs = {
        sidebar,
        shell: { prepend: element => { assert.equal(element, sidebar); location = 'shell'; } },
        drawer: {
            open: false,
            append: element => { assert.equal(element, sidebar); location = 'drawer'; },
            showModal() { this.open = true; },
            close() { this.open = false; state.restoreSidebar(); },
            getBoundingClientRect: () => ({ left: 0, right: 280, top: 0, bottom: 800 }),
        },
        drawerClose: { focus: () => { focused = 'close'; } },
        menuToggle: { focus: () => { focused = 'toggle'; } },
    };
    state.init();
    return { state, stored, listeners, location: () => location, focused: () => focused, resize: mobile => listeners.forEach(fn => fn({ matches: mobile })) };
}

test('desktop preference persists across navigation and storage failure stays usable', () => {
    const first = shell();
    assert.equal(first.state.sidebarCollapsed, false);
    first.state.toggleSidebar();
    assert.equal(first.stored.get('lf.backend.sidebar.collapsed'), 'true');
    assert.equal(shell({ saved: 'true' }).state.sidebarCollapsed, true);
    const blocked = shell({ unavailableStorage: true });
    blocked.state.toggleSidebar();
    assert.equal(blocked.state.sidebarCollapsed, true);
});

test('mobile drawer moves one sidebar, focuses close, and preserves desktop preference', () => {
    const view = shell({ mobile: true, saved: 'true' });
    view.state.toggleSidebar();
    assert.equal(view.location(), 'drawer');
    assert.equal(view.focused(), 'close');
    assert.equal(view.state.drawerOpen, true);
    view.state.closeDrawer();
    assert.equal(view.location(), 'shell');
    assert.equal(view.focused(), 'toggle');
    assert.equal(view.stored.get('lf.backend.sidebar.collapsed'), 'true');
});

test('resize closes drawer and restores navigation; destroy removes media listener', () => {
    const view = shell({ mobile: true });
    view.state.toggleSidebar();
    view.resize(false);
    assert.equal(view.state.drawerOpen, false);
    assert.equal(view.location(), 'shell');
    assert.equal(view.state.isMobile, false);
    view.state.destroy();
    assert.equal(view.listeners.size, 0);
});

test('a queued close event cannot detach a reopened mobile sidebar', () => {
    const view = shell({ mobile: true });
    const drawer = view.state.$refs.drawer;
    drawer.close = function () { this.open = false; };
    view.state.openDrawer();
    view.state.closeDrawer();
    view.state.openDrawer();
    view.state.restoreSidebar();
    assert.equal(view.location(), 'drawer');
    assert.equal(view.state.drawerOpen, true);
    assert.equal(view.focused(), 'close');
    view.state.closeDrawer();
    view.state.restoreSidebar();
    assert.equal(view.location(), 'shell');
    assert.equal(view.focused(), 'toggle');
});

test('backdrop closes drawer but clicking its content does not', () => {
    const view = shell({ mobile: true });
    view.state.toggleSidebar();
    const drawer = view.state.$refs.drawer;
    view.state.closeDrawerOnBackdrop({ target: drawer, clientX: 100, clientY: 200 });
    assert.equal(view.state.drawerOpen, true);
    view.state.closeDrawerOnBackdrop({ target: drawer, clientX: 300, clientY: 200 });
    assert.equal(view.state.drawerOpen, false);
});

test('active submenu can close and collapsed group expands on activation', () => {
    const view = shell();
    view.state.registerSidebarGroup('courses', true);
    view.state.toggleSidebarGroup('courses');
    assert.equal(view.state.isSidebarGroupOpen('courses'), false);
    view.state.toggleSidebar();
    view.state.toggleSidebarGroup('courses');
    assert.equal(view.state.sidebarCollapsed, false);
    assert.equal(view.state.isSidebarGroupOpen('courses'), true);
});
