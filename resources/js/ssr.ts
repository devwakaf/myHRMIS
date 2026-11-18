import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { i18nVue } from 'laravel-vue-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

const appName = import.meta.env.VITE_APP_NAME || 'MyHRMIS';
const langFiles = import.meta.glob<{ default: Record<string, string> }>('../../lang/*.json', { eager: true });

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} - ${appName}` : appName),
            resolve: (name) =>
                resolvePageComponent(
                    `./pages/${name}.vue`,
                    import.meta.glob<DefineComponent>('./pages/**/*.vue'),
                ),
            setup: ({ App, props, plugin }) =>
                createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(i18nVue, {
                        lang: (page.props.locale as string) || 'ms',
                        resolve: (lang: string) => langFiles[`../../lang/php_${lang}.json`]?.default ?? {},
                    }),
        }),
    { cluster: true },
);
