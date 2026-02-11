import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'Shopmata';

window.axios = axios;

// ---- Axios + CSRF setup (Passport + CreateFreshApiToken) ----
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Tell Axios which cookie/header to use for XSRF.
// Axios will automatically read the XSRF-TOKEN cookie and send X-XSRF-TOKEN
// on same-origin requests (exactly what Laravel expects).
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// Fallback: if you prefer sending X-CSRF-TOKEN (unencrypted), pull from <meta>
const metaCsrf = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');
if (metaCsrf) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = metaCsrf;
}

// Safety net: if neither header is present for some reason, add the meta token.
axios.interceptors.request.use((config) => {
    const hasXsrf = (config.headers as any)?.['X-XSRF-TOKEN'];
    const hasCsrf = (config.headers as any)?.['X-CSRF-TOKEN'];
    if (!hasXsrf && !hasCsrf && metaCsrf) {
        (config.headers as any)['X-CSRF-TOKEN'] = metaCsrf;
    }
    return config;
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
