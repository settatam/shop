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

// Axios automatically reads the XSRF-TOKEN cookie and sends X-XSRF-TOKEN
// on same-origin requests (exactly what Laravel expects).
// Do NOT set X-CSRF-TOKEN from the meta tag — it goes stale and prevents
// the fresh cookie-based token from being used (Inertia v2 docs recommend
// omitting the csrf-token meta tag entirely for this reason).
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

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
