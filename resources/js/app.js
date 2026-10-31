import './bootstrap';

import Alpine from 'alpinejs';
import './ai-authoring';

window.Alpine = Alpine;

window.tenantRegistrationForm = (initialSlug = '') => ({
    slug: initialSlug,
    slugify(value) {
        return String(value ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[đĐ]/g, 'd')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-+/g, '-')
            .slice(0, 50)
            .replace(/-+$/g, '');
    },
});

const formPlaceholderControlSelector = [
    '.lf-admin-page select.lf-form-control',
    '.lf-admin-page input.lf-form-control[type="date"]',
    '.lf-admin-page input.lf-form-control[type="datetime-local"]',
    '.lf-admin-page input.lf-form-control[type="month"]',
    '.lf-admin-page input.lf-form-control[type="time"]',
    '.public-page select.public-form-control',
    '.public-page input.public-form-control[type="date"]',
    '.public-page input.public-form-control[type="datetime-local"]',
    '.public-page input.public-form-control[type="month"]',
    '.public-page input.public-form-control[type="time"]',
    '.auth-page select.auth-input',
    '.auth-page input.auth-input[type="date"]',
    '.auth-page input.auth-input[type="datetime-local"]',
    '.auth-page input.auth-input[type="month"]',
    '.auth-page input.auth-input[type="time"]',
].join(',');

const syncFormPlaceholderControl = (control) => {
    if (! (control instanceof Element) || ! control.matches(formPlaceholderControlSelector)) {
        return;
    }

    const placeholderValues = (control.dataset.placeholderValues || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
    const isPlaceholder = control.value === '' || placeholderValues.includes(control.value);

    control.classList.toggle('is-lf-placeholder', isPlaceholder);
};

const initializeFormPlaceholderControls = (root = document) => {
    if (root.matches?.(formPlaceholderControlSelector)) {
        syncFormPlaceholderControl(root);
    }

    root.querySelectorAll?.(formPlaceholderControlSelector)
        .forEach(syncFormPlaceholderControl);
};

const bootFormPlaceholderControls = () => {
    initializeFormPlaceholderControls();

    document.addEventListener('input', (event) => syncFormPlaceholderControl(event.target));
    document.addEventListener('change', (event) => syncFormPlaceholderControl(event.target));
    document.addEventListener('reset', (event) => {
        window.setTimeout(() => initializeFormPlaceholderControls(event.target), 0);
    });

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initializeFormPlaceholderControls(node);
                }
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootFormPlaceholderControls, { once: true });
} else {
    bootFormPlaceholderControls();
}

window.backendSidebar = () => ({
    isMobile: false,
    drawerOpen: false,
    mediaQuery: null,
    mediaChange: null,
    sidebarCollapsed: false,
    storageKey: 'lf.backend.sidebar.collapsed',
    manualStorageKey: 'lf.backend.sidebar.manual',
    groupStorageKey: 'lf.backend.sidebar.groups',
    hasManualPreference: false,
    sidebarGroups: {},
    sidebarTooltip: { visible: false, label: '', top: 0, left: 0 },

    init() {
        let storedPreference = null;
        let storedManualPreference = null;

        try {
            storedPreference = window.localStorage.getItem(this.storageKey);
            storedManualPreference = window.localStorage.getItem(this.manualStorageKey);
            this.sidebarGroups = JSON.parse(window.localStorage.getItem(this.groupStorageKey) || '{}') || {};
        } catch (error) {
            storedPreference = null;
            storedManualPreference = null;
            this.sidebarGroups = {};
        }

        const hasStoredPreference = storedPreference === 'true' || storedPreference === 'false';

        this.hasManualPreference = storedManualPreference === 'true';
        this.sidebarCollapsed = this.resolveInitialSidebarState(hasStoredPreference, storedPreference);

        this.$watch('sidebarCollapsed', (value) => {
            if (this.hasManualPreference) {
                this.storePreference(value);
            }
        });

        this.mediaQuery = window.matchMedia('(max-width: 680px)');
        this.isMobile = this.mediaQuery.matches;
        this.mediaChange = (event) => {
            this.isMobile = event.matches;
            this.hideSidebarTooltip();
            this.closeDrawer();
        };
        this.mediaQuery.addEventListener('change', this.mediaChange);

        this.$nextTick(() => {
            document.documentElement.classList.remove(
                'is-backend-sidebar-collapsed',
                'is-backend-sidebar-initializing',
            );
        });
    },

    destroy() {
        this.mediaQuery?.removeEventListener('change', this.mediaChange);
        this.closeDrawer();
    },

    toggleSidebar() {
        this.hideSidebarTooltip();
        if (this.isMobile) {
            this.openDrawer();
            return;
        }
        this.setManualSidebarState(! this.sidebarCollapsed);
    },

    openDrawer() {
        const { drawer, sidebar, drawerClose } = this.$refs;
        if (drawer.open) return;
        Alpine.mutateDom(() => drawer.append(sidebar));
        this.drawerOpen = true;
        drawer.showModal();
        this.$nextTick(() => drawerClose.focus());
    },

    closeDrawer() {
        if (this.$refs.drawer?.open) this.$refs.drawer.close();
    },

    restoreSidebar() {
        // A close event can arrive after the same dialog has reopened.
        if (this.$refs.drawer.open) return;
        Alpine.mutateDom(() => this.$refs.shell.prepend(this.$refs.sidebar));
        this.drawerOpen = false;
        this.$refs.menuToggle.focus();
    },

    closeDrawerOnBackdrop(event) {
        const drawer = this.$refs.drawer;
        if (event.target !== drawer) return;
        const bounds = drawer.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            this.closeDrawer();
        }
    },

    showSidebarTooltip(event, label) {
        if (! this.sidebarCollapsed || this.isMobile) {
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();

        this.sidebarTooltip = {
            visible: true,
            label,
            top: rect.top + (rect.height / 2),
            left: rect.right + 10,
        };
    },

    hideSidebarTooltip() {
        this.sidebarTooltip.visible = false;
    },

    registerSidebarGroup(groupKey, isActive) {
        if (isActive) {
            this.sidebarGroups[groupKey] = true;
        } else if (this.sidebarGroups[groupKey] === undefined) {
            this.sidebarGroups[groupKey] = false;
        }
    },

    toggleSidebarGroup(groupKey) {
        if (this.sidebarCollapsed && ! this.isMobile) {
            this.setManualSidebarState(false);
            this.setSidebarGroupOpen(groupKey, true);
            return;
        }
        this.setSidebarGroupOpen(groupKey, ! this.isSidebarGroupOpen(groupKey));
    },

    setSidebarGroupOpen(groupKey, isOpen) {
        this.sidebarGroups[groupKey] = Boolean(isOpen);
        this.storeSidebarGroups();
    },

    isSidebarGroupOpen(groupKey) {
        return this.sidebarGroups[groupKey] === true;
    },

    resolveInitialSidebarState(hasStoredPreference, storedPreference) {
        if (hasStoredPreference) {
            return storedPreference === 'true';
        }

        // Tablet widths start as the icon rail so the content keeps its room; a manual choice still wins.
        return window.matchMedia('(min-width: 681px) and (max-width: 1024px)').matches;
    },

    setManualSidebarState(value) {
        this.hasManualPreference = true;
        this.sidebarCollapsed = value;
        this.storeManualPreference(value);
    },

    storePreference(value) {
        try {
            window.localStorage.setItem(this.storageKey, value ? 'true' : 'false');
        } catch (error) {
            // Ignore storage failures so the backend layout remains usable.
        }
    },

    storeManualPreference(value) {
        try {
            window.localStorage.setItem(this.manualStorageKey, 'true');
            window.localStorage.setItem(this.storageKey, value ? 'true' : 'false');
        } catch (error) {
            // Ignore storage failures so the backend layout remains usable.
        }
    },

    storeSidebarGroups() {
        try {
            window.localStorage.setItem(this.groupStorageKey, JSON.stringify(this.sidebarGroups));
        } catch (error) {
            // Ignore storage failures so the backend layout remains usable.
        }
    },
});

// Shared by the section and lesson folds of the course template Content tab.
const courseTemplateFold = (storageKey, expandedByDefault) => ({
    expanded: expandedByDefault,
    storageKey,

    init() {
        let storedState = null;

        try {
            storedState = window.localStorage.getItem(this.storageKey);
        } catch (error) {
            storedState = null;
        }

        if (storedState === 'true' || storedState === 'false') {
            this.expanded = storedState === 'true';
        }

        // A save redirects to an anchor inside the fold: keep it visible.
        const target = window.location.hash
            ? document.getElementById(decodeURIComponent(window.location.hash.slice(1)))
            : null;

        if (target && this.$el.contains(target)) {
            this.expanded = true;
        }
    },

    toggle() {
        this.expanded = ! this.expanded;
        this.setExpanded(this.expanded, true);
    },

    setExpanded(value, persist = false) {
        this.expanded = value;

        if (! persist) {
            return;
        }

        try {
            window.localStorage.setItem(
                this.storageKey,
                this.expanded ? 'true' : 'false',
            );
        } catch (error) {
            // Keep the current-page state when storage is unavailable.
        }
    },
});

window.courseTemplateSectionCollapse = (tenantId, templateId, sectionId) => courseTemplateFold(
    `lf.course-template.section.${tenantId}.${templateId}.${sectionId}.expanded`,
    true,
);

window.courseTemplateLessonCollapse = (tenantId, templateId, lessonId, expandedByDefault) => courseTemplateFold(
    `lf.course-template.lesson.${tenantId}.${templateId}.${lessonId}.expanded`,
    expandedByDefault,
);

window.courseTemplateStructure = (initialTab, storageKey) => ({
    activeStructureTab: initialTab,
    storageKey,

    init() {
        let savedTab = null;

        try {
            savedTab = window.localStorage.getItem(this.storageKey);
        } catch (error) {
            savedTab = null;
        }

        if (['direct', 'sections'].includes(savedTab)) {
            this.activeStructureTab = savedTab;
        }
    },

    selectStructureTab(tab) {
        this.activeStructureTab = tab;

        try {
            window.localStorage.setItem(this.storageKey, tab);
        } catch (error) {
            // The tab still switches for this page view.
        }
    },

    revealSection(sectionId) {
        const target = document.getElementById(`course-template-section-${sectionId}`);

        if (! target) {
            return;
        }

        for (let node = target; node; node = node.parentElement?.closest('.course-template-outline-section')) {
            Alpine.$data(node).setExpanded(true);
        }

        this.$nextTick(() => {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.classList.add('is-flash');
            window.setTimeout(() => target.classList.remove('is-flash'), 1200);
        });
    },
});

// Search and fold-all of one structure panel (direct lessons or sections).
window.courseTemplateContentTools = (foldSelector) => ({
    query: '',
    noResults: false,

    filter() {
        const query = this.query.trim().toLocaleLowerCase();
        const matches = (node) => ! query || node.textContent.toLocaleLowerCase().includes(query);
        const isShown = (node) => node.style.display !== 'none';

        this.$root.querySelectorAll('.course-template-lesson-item').forEach((lesson) => {
            const hit = matches(lesson);
            lesson.style.display = hit ? '' : 'none';

            if (query && hit) {
                Alpine.$data(lesson).setExpanded(true);
            }
        });

        // Deepest sections first, so a parent sees whether a child stayed visible.
        [...this.$root.querySelectorAll('.course-template-outline-section')].reverse().forEach((section) => {
            const title = section.querySelector(':scope > header .course-template-outline-section-title');
            const branch = section.querySelector(':scope > .course-template-section-branch');
            const childShown = branch
                ? [...branch.querySelectorAll('.course-template-lesson-item, .course-template-outline-section')].some(isShown)
                : false;
            const shown = ! query || (title && matches(title)) || childShown;
            section.style.display = shown ? '' : 'none';

            if (query && shown) {
                Alpine.$data(section).setExpanded(true);
            }
        });

        const items = [...this.$root.querySelectorAll('.course-template-lesson-item, .course-template-outline-section')];
        this.noResults = query !== '' && items.length > 0 && ! items.some(isShown);
    },

    toggleAll() {
        const folds = [...this.$root.querySelectorAll(foldSelector)];
        const expand = folds.some((fold) => ! Alpine.$data(fold).expanded);

        folds.forEach((fold) => Alpine.$data(fold).setExpanded(expand, true));
    },
});

const bootLfConfirmDialog = () => {
    const dialog = document.querySelector('[data-lf-confirm-dialog]');

    if (! (dialog instanceof HTMLDialogElement)) {
        return;
    }

    const title = dialog.querySelector('[data-lf-confirm-title]');
    const message = dialog.querySelector('[data-lf-confirm-message]');
    const cancel = dialog.querySelector('[data-lf-confirm-cancel]');
    const accept = dialog.querySelector('[data-lf-confirm-accept]');
    let resolvePending = null;
    let returnFocus = null;

    const finish = (confirmed) => {
        if (! resolvePending) return;
        const resolve = resolvePending;
        resolvePending = null;
        dialog.close();
        resolve(confirmed);
        window.setTimeout(() => returnFocus?.focus?.(), 0);
    };

    window.LFConfirm = {
        open(options = {}) {
            if (resolvePending) finish(false);

            title.textContent = options.title || dialog.dataset.defaultTitle || '';
            message.textContent = options.message || '';
            accept.textContent = options.confirmLabel || accept.dataset.defaultLabel || '';
            dialog.classList.toggle('is-danger', options.tone === 'danger');
            accept.classList.toggle('btn-danger', options.tone === 'danger');
            accept.classList.toggle('btn-primary', options.tone !== 'danger');
            returnFocus = options.trigger || document.activeElement;
            dialog.showModal();
            window.setTimeout(() => cancel.focus(), 0);

            return new Promise((resolve) => { resolvePending = resolve; });
        },
    };

    cancel.addEventListener('click', () => finish(false));
    accept.addEventListener('click', () => finish(true));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); finish(false); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) finish(false); });

    const bypassedForms = new WeakSet();
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (! (form instanceof HTMLFormElement) || ! form.dataset.lfConfirm || bypassedForms.has(form)) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        const confirmed = await window.LFConfirm.open({
            title: form.dataset.lfConfirmTitle,
            message: form.dataset.lfConfirm,
            confirmLabel: form.dataset.lfConfirmLabel,
            tone: form.dataset.lfConfirmTone,
            trigger: submitter,
        });
        if (! confirmed) return;

        bypassedForms.add(form);
        form.requestSubmit(submitter || undefined);
        window.setTimeout(() => bypassedForms.delete(form), 0);
    }, true);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootLfConfirmDialog, { once: true });
} else {
    bootLfConfirmDialog();
}

Alpine.start();

// Alpine can populate a date/time control after the initial placeholder scan.
// Reconcile once more so a real model value always uses the normal text style.
window.requestAnimationFrame(() => initializeFormPlaceholderControls());

// Canonical admin tables (`.admin-table-wrap`) overflow horizontally on
// narrow viewports/zoom levels. Let users click-and-drag (mouse) to reveal
// the rest of the columns instead of hunting for the thin scrollbar; touch
// devices already get native swipe-to-scroll for free from overflow-x:auto.
const ADMIN_TABLE_WRAP_SELECTOR = '.admin-table-wrap';

const refreshAdminTableWrapScrollable = (wrap) => {
    if (! (wrap instanceof Element)) return;
    wrap.classList.toggle('is-scrollable', wrap.scrollWidth > wrap.clientWidth + 1);
};

const refreshAdminTableWraps = (root = document) => {
    if (root instanceof Element && root.matches?.(ADMIN_TABLE_WRAP_SELECTOR)) {
        refreshAdminTableWrapScrollable(root);
    }

    root.querySelectorAll?.(ADMIN_TABLE_WRAP_SELECTOR).forEach(refreshAdminTableWrapScrollable);
};

const bootAdminTableDragScroll = () => {
    refreshAdminTableWraps();

    window.addEventListener('resize', () => refreshAdminTableWraps());

    if (typeof ResizeObserver !== 'undefined') {
        const resizeObserver = new ResizeObserver((entries) => {
            entries.forEach((entry) => refreshAdminTableWrapScrollable(entry.target));
        });
        const observeWrap = (wrap) => resizeObserver.observe(wrap);

        document.querySelectorAll(ADMIN_TABLE_WRAP_SELECTOR).forEach(observeWrap);

        new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (! (node instanceof Element)) return;

                    if (node.matches?.(ADMIN_TABLE_WRAP_SELECTOR)) observeWrap(node);
                    node.querySelectorAll?.(ADMIN_TABLE_WRAP_SELECTOR).forEach(observeWrap);
                    refreshAdminTableWraps(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    let drag = null;

    document.addEventListener('mousedown', (event) => {
        if (event.button !== 0) return;

        const wrap = event.target.closest?.(ADMIN_TABLE_WRAP_SELECTOR);
        if (! wrap || ! wrap.classList.contains('is-scrollable')) return;

        drag = { wrap, startX: event.pageX, startScrollLeft: wrap.scrollLeft, moved: false };
    });

    document.addEventListener('mousemove', (event) => {
        if (! drag) return;

        const dx = event.pageX - drag.startX;
        if (! drag.moved && Math.abs(dx) < 4) return;

        if (! drag.moved) {
            drag.moved = true;
            drag.wrap.classList.add('is-dragging');
        }

        event.preventDefault();
        drag.wrap.scrollLeft = drag.startScrollLeft - dx;
    });

    const endDrag = () => {
        if (! drag) return;

        const { wrap, moved } = drag;
        wrap.classList.remove('is-dragging');
        drag = null;

        if (! moved) return;

        // A drag just ended under the pointer: swallow the click it would
        // otherwise fire so links/buttons touched mid-drag don't activate.
        const suppressClick = (event) => {
            event.preventDefault();
            event.stopPropagation();
        };
        wrap.addEventListener('click', suppressClick, { capture: true, once: true });
        window.setTimeout(() => wrap.removeEventListener('click', suppressClick, { capture: true }), 0);
    };

    document.addEventListener('mouseup', endDrag);
    window.addEventListener('blur', endDrag);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAdminTableDragScroll, { once: true });
} else {
    bootAdminTableDragScroll();
}

// Attachment writes keep the browser on the current course page.
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-lf-mapping-async]') || event.defaultPrevented) return;
    event.preventDefault();
    if (form.dataset.saving === 'true') return;
    const error = form.querySelector('[data-lf-mapping-error]');
    if (!form.hasAttribute('data-lf-mapping-remove') && !form.querySelector('input[name="learning_node_ids[]"]:checked')) return;
    form.dataset.saving = 'true';
    const submit = form.querySelector('button[type="submit"]');
    const originalLabel = submit.textContent;
    if (submit.dataset.loadingLabel) submit.textContent = submit.dataset.loadingLabel;
    form.dispatchEvent(new CustomEvent('mapping-saving', { detail: true }));
    submit.disabled = true;
    form.setAttribute('aria-busy', 'true');
    error.hidden = true;
    let savedMessage = '';
    try {
        const response = await fetch(form.action, {
            method: 'POST', body: new FormData(form), credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (!response.ok) {
            error.textContent = Object.values(data.errors || {}).flat().join(' ') || data.message || form.dataset.errorMessage;
            error.hidden = false;
            return;
        }
        savedMessage = data.message;
        const snapshot = await fetch(data.refresh_url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!snapshot.ok) throw new Error('refresh');
        const page = new DOMParser().parseFromString(await snapshot.text(), 'text/html');
        const replacement = page.querySelector('#course-learning');
        const current = document.querySelector('#course-learning');
        if (!replacement || !current) throw new Error('refresh');
        const rowId = form.querySelector('input[name="mapping_picker"]').value + '-row';
        form.closest('dialog')?.close();
        current.replaceWith(replacement);
        // The swapped-in markup is new: its UI Kit selects need their adapter again (claimOnce skips the others).
        import('./admin-ui-kit-controls.js').then(({ initUiKitSelect }) => replacement.querySelectorAll('select[data-ui-kit-select]').forEach(initUiKitSelect)).catch(() => {});
        replacement.querySelector(`#${rowId} button[type=\"button\"]`)?.focus({ preventScroll: true });
    } catch {
        error.textContent = savedMessage ? `${savedMessage} ${form.dataset.refreshError}` : form.dataset.errorMessage;
        error.hidden = false;
    } finally {
        form.dataset.saving = 'false';
        submit.textContent = originalLabel;
        form.dispatchEvent(new CustomEvent('mapping-saving', { detail: false }));
        submit.disabled = !form.hasAttribute('data-lf-mapping-remove') && !form.querySelector('input[name="learning_node_ids[]"]:checked');
        form.removeAttribute('aria-busy');
    }
});
