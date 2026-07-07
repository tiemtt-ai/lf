import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.backendSidebar = (autoCollapse) => ({
    sidebarCollapsed: false,
    storageKey: 'lf.backend.sidebar.collapsed',

    init() {
        let storedPreference = null;

        try {
            storedPreference = window.localStorage.getItem(this.storageKey);
        } catch (error) {
            storedPreference = null;
        }

        if (autoCollapse) {
            this.sidebarCollapsed = true;
            this.storePreference(true);
        } else {
            this.sidebarCollapsed = false;
            this.storePreference(false);
        }

        this.$watch('sidebarCollapsed', (value) => {
            this.storePreference(value);
        });
    },

    toggleSidebar() {
        this.sidebarCollapsed = ! this.sidebarCollapsed;
    },

    storePreference(value) {
        try {
            window.localStorage.setItem(this.storageKey, value ? 'true' : 'false');
        } catch (error) {
            // Ignore storage failures so the backend layout remains usable.
        }
    },
});

Alpine.start();
