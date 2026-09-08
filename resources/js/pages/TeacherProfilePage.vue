<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/api';
import { groupByDay, slotsFromLessons } from '../composables/useSchedule';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';

const route = useRoute();

const teacher = ref(null);
const loading = ref(true);
const failure = ref('');

const classes = computed(() => teacher.value?.classes ?? []);
const slots = computed(() => slotsFromLessons(teacher.value?.lessons));
const week = computed(() => groupByDay(slots.value));

onMounted(async () => {
    try {
        const { data } = await api.get(`/teachers/${route.params.id}`);
        teacher.value = data.data ?? data;
    } catch (error) {
        failure.value =
            error.response?.status === 404
                ? "Cet enseignant n'existe pas."
                : "Impossible de charger la fiche de l'enseignant.";
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-header">
        <RouterLink to="/teachers" class="btn-link"><AppIcon name="back" :size="16" />Retour aux enseignants</RouterLink>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <LoadingState v-else-if="loading" :rows="4" />

    <template v-else-if="teacher">
        <header class="card profile-head">
            <div>
                <h1>{{ teacher.name }}</h1>
                <p class="profile-facts muted">
                    <span>{{ teacher.subject || 'Matière non renseignée' }}</span>
                    <span> · {{ teacher.email }}</span>
                    <span v-if="teacher.phone"> · {{ teacher.phone }}</span>
                </p>
            </div>
        </header>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value">{{ teacher.lessons_count ?? 0 }}</div>
                <div class="stat-label">Cours</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ classes.length }}</div>
                <div class="stat-label">Classes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ slots.length }}</div>
                <div class="stat-label">Créneaux / semaine</div>
            </div>
        </div>

        <div class="panels">
            <section class="card panel">
                <header class="panel-head">
                    <h2>Emploi du temps</h2>
                    <span class="panel-count">{{ slots.length }} créneaux</span>
                </header>

                <EmptyState v-if="!week.length" title="Aucun créneau programmé." compact />

                <div v-for="group in week" :key="group.day" class="day-group">
                    <h3 class="day-title">{{ group.day }}</h3>
                    <ul class="rows">
                        <li v-for="slot in group.slots" :key="slot.id" class="row">
                            <span class="row-time">{{ slot.start }}–{{ slot.end }}</span>
                            <div class="row-main">
                                <span class="row-title">{{ slot.title }}</span>
                                <span class="row-meta">
                                    {{ slot.schoolClass || 'sans classe' }}
                                    <template v-if="slot.room"> · {{ slot.room }}</template>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="card panel">
                <header class="panel-head">
                    <h2>Classes</h2>
                </header>

                <EmptyState v-if="!classes.length" title="Aucune classe." compact />

                <ul v-else class="rows">
                    <li v-for="schoolClass in classes" :key="schoolClass.id" class="row">
                        <div class="row-main">
                            <span class="row-title">
                                <RouterLink :to="`/classes/${schoolClass.id}`">{{ schoolClass.name }}</RouterLink>
                            </span>
                            <span class="row-meta">Niveau {{ schoolClass.level || '–' }}</span>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </template>
</template>
