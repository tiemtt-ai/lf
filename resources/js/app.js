import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.backendSidebar = (autoCollapse) => ({
    sidebarCollapsed: false,
    storageKey: 'lf.backend.sidebar.collapsed',
    manualStorageKey: 'lf.backend.sidebar.manual',
    pageModeStorageKey: 'lf.backend.sidebar.pageMode',
    workspaceMode: 'workspace',
    standardMode: 'standard',
    hasManualPreference: false,

    init() {
        let storedPreference = null;
        let storedManualPreference = null;
        let storedPageMode = null;
        const currentMode = autoCollapse ? this.workspaceMode : this.standardMode;

        try {
            storedPreference = window.localStorage.getItem(this.storageKey);
            storedManualPreference = window.localStorage.getItem(this.manualStorageKey);
            storedPageMode = window.localStorage.getItem(this.pageModeStorageKey);
        } catch (error) {
            storedPreference = null;
            storedManualPreference = null;
            storedPageMode = null;
        }

        const hasStoredPreference = storedPreference === 'true' || storedPreference === 'false';
        const isEnteringWorkspace = autoCollapse && storedPageMode !== this.workspaceMode;

        this.hasManualPreference = storedManualPreference === 'true';
        this.sidebarCollapsed = this.resolveInitialSidebarState(
            isEnteringWorkspace,
            hasStoredPreference,
            storedPreference,
            autoCollapse,
        );

        if (isEnteringWorkspace || (! hasStoredPreference && autoCollapse)) {
            this.storePreference(this.sidebarCollapsed);
        }

        this.storePageMode(currentMode);

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

    expandSidebarFromNavigation() {
        if (! this.sidebarCollapsed) {
            return;
        }

        this.setManualSidebarState(false);
    },

    resolveInitialSidebarState(isEnteringWorkspace, hasStoredPreference, storedPreference, autoCollapse) {
        if (isEnteringWorkspace) {
            return true;
        }

        if (hasStoredPreference) {
            return storedPreference === 'true';
        }

        return autoCollapse;
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

    storePageMode(value) {
        try {
            window.localStorage.setItem(this.pageModeStorageKey, value);
        } catch (error) {
            // Ignore storage failures so the backend layout remains usable.
        }
    },
});

Alpine.start();
