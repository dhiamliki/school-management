import { createApp } from 'vue';
import AppLayout from './components/AppLayout.vue';
import router from './router';
import '../css/spa.css';

createApp(AppLayout).use(router).mount('#app');
