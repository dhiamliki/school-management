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

// No X-CSRF-TOKEN header on purpose: Laravel prefers it over the cookie, and
// the meta tag token goes stale the moment logout regenerates it.

// Injected rather than importing the router, which would cycle.
let onUnauthorized = null;

export function setUnauthorizedHandler(handler) {
    onUnauthorized = handler;
}

// The session probe and the login attempt handle their own 401.
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
