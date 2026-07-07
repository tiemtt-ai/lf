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

    handleSidebarNavigation(event) {
        if (! this.sidebarCollapsed) {
            return;
        }

        this.expandSidebarFromNavigation();
    },

    handleBreadcrumbNavigation(event) {
        this.handleSidebarNavigation(event);
    },

    registerSidebarGroup(groupKey, isActive) {
        if (isActive) {
            this.activeSidebarGroups[groupKey] = true;
        } else if (this.sidebarGroups[groupKey] === undefined) {
            this.sidebarGroups[groupKey] = false;
        }
    },

    toggleSidebarGroup(groupKey) {
        if (this.sidebarCollapsed) {
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
        return this.activeSidebarGroups[groupKey] || this.sidebarGroups[groupKey] === true;
    },

    expandSidebarFromNavigation() {
        if (! this.sidebarCollapsed) {
            return;
        }

        this.setManualSidebarState(false);
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

Alpine.start();
