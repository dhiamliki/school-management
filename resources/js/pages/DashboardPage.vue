<script setup>
import { onMounted, ref } from 'vue';
import api from '../lib/api';

const cards = ref([
    { label: 'Classes', endpoint: '/school-classes', to: '/classes', count: null },
    { label: 'Élèves', endpoint: '/students', to: '/students', count: null },
    { label: 'Enseignants', endpoint: '/teachers', to: '/teachers', count: null },
    { label: 'Cours', endpoint: '/lessons', to: '/lessons', count: null },
    { label: 'Créneaux', endpoint: '/timetables', to: '/timetable', count: null },
]);

const loading = ref(true);
const failure = ref('');

onMounted(async () => {
    try {
        await Promise.all(
            cards.value.map(async (card) => {
                const { data } = await api.get(card.endpoint);
                card.count = data.length;
            }),
        );
    } catch (error) {
        failure.value = 'Impossible de charger les statistiques.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-header">
        <h1>Tableau de bord</h1>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <div v-if="loading" class="state">Chargement…</div>

    <div v-else class="stat-grid">
        <RouterLink v-for="card in cards" :key="card.endpoint" :to="card.to" class="stat-card">
            <div class="stat-value">{{ card.count }}</div>
            <div class="stat-label">{{ card.label }}</div>
        </RouterLink>
    </div>
</template>
