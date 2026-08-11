import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

const token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    api.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

export default api;
