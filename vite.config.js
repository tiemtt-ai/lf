import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/backend.css',
                'resources/css/auth.css',
                'resources/css/tenant-site.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});
