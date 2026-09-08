import { createApp } from 'vue';
import AppLayout from './components/AppLayout.vue';
import { useAuth } from './composables/useAuth';
import { setUnauthorizedHandler } from './lib/api';
import router from './router';
import '../css/spa.css';

const { clear } = useAuth();

// A 401 means the session expired: clear it and return to login, carrying the
// page being left as ?redirect= so the user lands back where they were.
setUnauthorizedHandler(() => {
    clear();

    const current = router.currentRoute.value;

    if (current.path !== '/login') {
        router.replace({
            path: '/login',
            query: current.fullPath === '/' ? {} : { redirect: current.fullPath },
        });
    }
});

createApp(AppLayout).use(router).mount('#app');
