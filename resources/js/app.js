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
        this.setManualSidebarState(! this.sidebarCollapsed);
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

Alpine.start();
