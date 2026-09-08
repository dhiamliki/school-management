import { computed, reactive } from 'vue';
import api from '../lib/api';

const state = reactive({
    user: null,
    // True once the server has been asked, so the guard knows not to re-probe.
    resolved: false,
    loading: false,
    errors: {},
    failure: '',
});

const user = computed(() => state.user);
const isAuthenticated = computed(() => state.user !== null);
const isResolved = computed(() => state.resolved);

function clear() {
    state.user = null;
    state.resolved = true;
}

function csrfCookie() {
    return api.get('/sanctum/csrf-cookie', { baseURL: '/' });
}

async function fetchUser() {
    try {
        const { data } = await api.get('/user');
        state.user = data;
    } catch (error) {
        state.user = null;
    } finally {
        state.resolved = true;
    }

    return state.user;
}

/** @returns {Promise<boolean>} whether the session was opened */
async function login(credentials) {
    state.loading = true;
    state.errors = {};
    state.failure = '';

    try {
        await csrfCookie();

        const { data } = await api.post('/login', credentials);

        state.user = data;
        state.resolved = true;

        return true;
    } catch (error) {
        const status = error.response?.status;

        if (status === 422) {
            state.errors = error.response.data.errors ?? {};
        } else if (status === 401) {
            state.failure = 'Identifiants incorrects.';
        } else {
            state.failure = 'Impossible de se connecter.';
        }

        state.user = null;

        return false;
    } finally {
        state.loading = false;
    }
}

async function logout() {
    try {
        await api.post('/logout');
    } catch (error) {
        // The session is gone either way; fall through to the local clear.
    } finally {
        clear();
    }
}

export function useAuth() {
    return {
        user,
        isAuthenticated,
        isResolved,
        loading: computed(() => state.loading),
        errors: computed(() => state.errors),
        failure: computed(() => state.failure),
        login,
        logout,
        fetchUser,
        clear,
    };
}
