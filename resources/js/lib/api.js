import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    // Let axios echo Sanctum's XSRF-TOKEN cookie back as X-XSRF-TOKEN.
    withCredentials: true,
    withXSRFToken: true,
});

// No X-CSRF-TOKEN header is sent on purpose. Laravel prefers that header
// over the XSRF-TOKEN cookie, and the token baked into the page's meta tag
// goes stale the moment logout calls regenerateToken() - which would then
// break logging back in without a full page reload. Sanctum's cookie, which
// useAuth refreshes via /sanctum/csrf-cookie before each login, is the one
// source of truth instead.

/**
 * Set by app.js. Kept as an injected callback rather than importing the
 * router here, which would create a cycle (router -> useAuth -> api).
 */
let onUnauthorized = null;

export function setUnauthorizedHandler(handler) {
    onUnauthorized = handler;
}

/**
 * Requests that are allowed to answer 401 on their own: the session probe
 * and the login attempt itself. Redirecting on those would fight the
 * router guard and the login form.
 */
function handlesOwn401(config) {
    const url = config?.url ?? '';

    return url === '/user' || url === '/login';
}

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && !handlesOwn401(error.config)) {
            onUnauthorized?.();
        }

        return Promise.reject(error);
    },
);

export default api;
