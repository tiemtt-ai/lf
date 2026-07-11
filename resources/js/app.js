import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

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
