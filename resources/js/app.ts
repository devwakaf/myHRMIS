import { createInertiaApp, router } from '@inertiajs/vue3';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import '../css/app.css';
import { initializeTheme } from '@/composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'MyHRMIS';

// PHP translation files (lang/ms, lang/en) are compiled to lang/php_{locale}.json
// by the laravel-vue-i18n Vite plugin (spec-00 A3).
const langFiles = import.meta.glob<{ default: Record<string, string> }>('../../lang/*.json');

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const initialLocale = (props.initialPage.props.locale as string) || 'ms';

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18nVue, {
                lang: initialLocale,
                fallbackLang: 'en',
                resolve: async (lang: string) => {
                    const file = langFiles[`../../lang/php_${lang}.json`];

                    return file ? await file() : { default: {} };
                },
            })
            .mount(el);

        // Keep the client language in step with the server (toggle / login).
        router.on('navigate', (event) => {
            const locale = event.detail.page.props.locale as string | undefined;
            if (locale && document.documentElement.lang !== locale) {
                document.documentElement.lang = locale;
                loadLanguageAsync(locale);
            }
        });
    },
    progress: {
        color: '#0f766e',
    },
});

initializeTheme();
