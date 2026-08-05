import './bootstrap';

import Alpine from 'alpinejs';

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
    sidebarCollapsed: false,
    storageKey: 'lf.backend.sidebar.collapsed',
    manualStorageKey: 'lf.backend.sidebar.manual',
    groupStorageKey: 'lf.backend.sidebar.groups',
    hasManualPreference: false,
    sidebarGroups: {},
    activeSidebarGroups: {},
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

        this.$nextTick(() => {
            document.documentElement.classList.remove(
                'is-backend-sidebar-collapsed',
                'is-backend-sidebar-initializing',
            );
        });
    },

    toggleSidebar() {
        this.hideSidebarTooltip();
        this.setManualSidebarState(! this.sidebarCollapsed);
    },

    showSidebarTooltip(event, label) {
        if (! this.sidebarCollapsed) {
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
            this.activeSidebarGroups[groupKey] = true;
        } else if (this.sidebarGroups[groupKey] === undefined) {
            this.sidebarGroups[groupKey] = false;
        }
    },

    toggleSidebarGroup(groupKey) {
        this.setSidebarGroupOpen(groupKey, ! this.isSidebarGroupOpen(groupKey));
    },

    setSidebarGroupOpen(groupKey, isOpen) {
        this.sidebarGroups[groupKey] = Boolean(isOpen);
        this.storeSidebarGroups();
    },

    isSidebarGroupOpen(groupKey) {
        return this.activeSidebarGroups[groupKey] || this.sidebarGroups[groupKey] === true;
    },

    resolveInitialSidebarState(hasStoredPreference, storedPreference) {
        if (hasStoredPreference) {
            return storedPreference === 'true';
        }

        return false;
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

window.courseTemplateSectionCollapse = (tenantId, templateId, sectionId) => ({
    expanded: true,
    storageKey: `lf.course-template.section.${tenantId}.${templateId}.${sectionId}.expanded`,

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
    },

    toggle() {
        this.expanded = ! this.expanded;

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
