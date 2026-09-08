import { createApp } from 'vue';
import AppLayout from './components/AppLayout.vue';
import { useAuth } from './composables/useAuth';
import { setUnauthorizedHandler } from './lib/api';
import router from './router';
import '../css/spa.css';

const { clear } = useAuth();

// A 401 on any regular API call means the session expired underneath us:
// drop the local user and send the browser back to the login screen.
//
// The page being left is carried along as ?redirect=, which the login form
// already reads and the router guard already sets when it turns a signed-out
// visitor away. Without it an expiry in the middle of a task dropped the user
// on the dashboard afterwards, with no way back to where they had been.
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
