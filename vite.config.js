import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/auth/auth.css',
                'resources/css/auth/auth_login.css',
                'resources/css/auth/forms.css',
                'resources/css/layout/app_layout.css',
                'resources/css/layout/sidebar.css',
                'resources/css/layout/topbar.css',
                'resources/css/pages/dashboard.css',
                'resources/css/pages/inventory.css',
                'resources/css/pages/logs.css',
                'resources/css/pages/medicines.css',
                'resources/css/pages/medicines_create.css',
                'resources/css/pages/medicines_edit.css',
                'resources/css/pages/patients.css',
                'resources/css/pages/pharmacies.css',
                'resources/css/pages/pharmacies_create.css',
                'resources/css/pages/pharmacy_dashboard.css',
                'resources/css/pages/pharmacy_medicine_create.css',
                'resources/css/pages/settings.css',
                'resources/css/pages/statistics.css',
                'resources/css/pages/users.css',
                'resources/css/pages/users_create.css',
                'resources/js/pharmacy_dashboard.js',
                'resources/css/pages/pharmacy_hub.css',
                'resources/js/pharmacy_hub.js',
            ],
            refresh: true,
            fonts: [
                bunny('Cairo', {
                    weights: [400, 500, 600, 700],
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
