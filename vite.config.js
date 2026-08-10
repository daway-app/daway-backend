import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
    'resources/css/app.css',
    'resources/css/app_layout.css',
    'resources/css/auth.css',
    'resources/css/auth_login.css',
    'resources/css/dashboard.css',
    'resources/css/forms.css',
    'resources/css/inventory.css',
    'resources/css/logs.css',
    'resources/css/medicines.css',
    'resources/css/medicines_create.css',
    'resources/css/medicines_edit.css',
    'resources/css/patients.css',
    'resources/css/pharmacies.css',
    'resources/css/pharmacies_create.css',
    'resources/css/settings.css',
    'resources/css/sidebar.css',
    'resources/css/statistics.css',
    'resources/css/topbar.css',
    'resources/css/users.css',
    'resources/css/users_create.css',
    'resources/js/app.js',
],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
