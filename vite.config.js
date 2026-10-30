import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/backend.css',
                'resources/css/admin/admin-ui-kit.css',
                'resources/js/admin-ui-kit-dialog.js',
                'resources/js/admin-ui-kit-controls.js',
                'resources/js/admin-ui-kit-dialog-form.js',
                'resources/js/admin-ui-kit-row-actions.js',
                'resources/js/admin-ui-kit-cleanup.js',
                'resources/js/admin-ui-kit-multiselect.js',
                'resources/js/course-template-activity-status.js',
                'resources/css/auth.css',
                'resources/css/tenant-site.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});
