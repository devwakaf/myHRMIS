import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import { defineConfig } from 'vite';

// PHP_BINARY lets Windows/Laragon shells without `php` on PATH run the build;
// on Linux production `php` is on PATH and the default is used.
const php = process.env.PHP_BINARY ? `"${process.env.PHP_BINARY}"` : 'php';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
            command: `${php} artisan wayfinder:generate`,
        }),
        i18n(),
    ],
});
