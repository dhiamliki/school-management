<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/api';
import { groupByDay, slotsFromLessons } from '../composables/useSchedule';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';

const route = useRoute();

const student = ref(null);
const loading = ref(true);
const failure = ref('');

const attendance = computed(() => student.value?.attendance ?? null);
const history = computed(() => student.value?.attendance_history ?? []);

const week = computed(() =>
    groupByDay(slotsFromLessons(student.value?.school_class?.lessons)),
);

const rateTone = computed(() => {
    const rate = attendance.value?.rate;

    if (rate === null || rate === undefined) {
        return 'badge-muted';
    }

    return rate < 80 ? 'badge-bad' : rate < 90 ? 'badge-warn' : 'badge-good';
});

function statusTone(status) {
    return { présent: 'badge-good', absent: 'badge-bad', retard: 'badge-warn' }[status] ?? 'badge-muted';
}

function formatDate(value) {
    return value ? value.slice(0, 10) : '–';
}

onMounted(async () => {
    try {
        const { data } = await api.get(`/students/${route.params.id}`);
        student.value = data.data ?? data;
    } catch (error) {
        failure.value =
            error.response?.status === 404
                ? "Cet élève n'existe pas."
                : "Impossible de charger la fiche de l'élève.";
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-header">
        <RouterLink to="/students" class="btn-link"><AppIcon name="back" :size="16" />Retour aux élèves</RouterLink>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <LoadingState v-else-if="loading" :rows="4" />

    <template v-else-if="student">
        <header class="card profile-head">
            <div>
                <h1>{{ student.name }}</h1>
                <p class="profile-facts muted">
                    <RouterLink v-if="student.school_class" :to="`/classes/${student.school_class.id}`">
                        {{ student.school_class.name }}
                    </RouterLink>
                    <span v-else>Sans classe</span>
                    <span> · {{ student.matricule }}</span>
                    <span v-if="student.birth_date"> · né(e) le {{ formatDate(student.birth_date) }}</span>
                </p>
            </div>
            <span class="badge badge-lg" :class="rateTone">
                {{ attendance && attendance.rate !== null ? `${attendance.rate}%` : '–' }}
            </span>
        </header>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value">{{ attendance ? attendance.absences : 0 }}</div>
                <div class="stat-label">Absences</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ attendance ? attendance.lates : 0 }}</div>
                <div class="stat-label">Retards</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ attendance ? attendance.records : 0 }}</div>
                <div class="stat-label">Relevés</div>
            </div>
        </div>

        <div class="panels">
            <section class="card panel">
                <header class="panel-head">
                    <h2>Présences récentes</h2>
                    <span class="panel-count">{{ history.length }} derniers relevés</span>
                </header>

                <EmptyState v-if="!history.length" title="Aucun relevé de présence." compact />

                <ul v-else class="rows">
                    <li v-for="mark in history" :key="mark.id" class="row">
                        <span class="row-time">{{ formatDate(mark.date) }}</span>
                        <div class="row-main">
                            <span class="row-title">{{ mark.lesson ? mark.lesson.title : 'Journée entière' }}</span>
                            <span v-if="mark.note" class="row-meta">{{ mark.note }}</span>
                        </div>
                        <span class="badge" :class="statusTone(mark.status)">{{ mark.status }}</span>
                    </li>
                </ul>
            </section>

            <section class="card panel">
                <header class="panel-head">
                    <h2>Emploi du temps de la classe</h2>
                </header>

                <EmptyState v-if="!week.length" title="Aucun cours programmé." compact />

                <div v-for="group in week" :key="group.day" class="day-group">
                    <h3 class="day-title">{{ group.day }}</h3>
                    <ul class="rows">
                        <li v-for="slot in group.slots" :key="slot.id" class="row">
                            <span class="row-time">{{ slot.start }}–{{ slot.end }}</span>
                            <div class="row-main">
                                <span class="row-title">{{ slot.title }}</span>
                                <span class="row-meta">
                                    {{ slot.teacher || 'sans enseignant' }}
                                    <template v-if="slot.room"> · {{ slot.room }}</template>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </template>
</template>
