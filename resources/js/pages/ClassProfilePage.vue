<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/api';
import { groupByDay, slotsFromLessons } from '../composables/useSchedule';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';

const route = useRoute();

const schoolClass = ref(null);
const loading = ref(true);
const failure = ref('');

const roster = computed(() => schoolClass.value?.students ?? []);
const attendance = computed(() => schoolClass.value?.attendance ?? null);
const slots = computed(() => slotsFromLessons(schoolClass.value?.lessons));
const week = computed(() => groupByDay(slots.value));

/**
 * Who teaches this class, one row per subject, from its lessons.
 */
const staff = computed(() => {
    const seen = new Map();

    for (const lesson of schoolClass.value?.lessons ?? []) {
        if (!lesson.teacher) {
            continue;
        }

        const key = `${lesson.teacher.id}-${lesson.subject}`;

        if (!seen.has(key)) {
            seen.set(key, { key, teacher: lesson.teacher, subject: lesson.subject });
        }
    }

    return [...seen.values()].sort((a, b) => (a.subject ?? '').localeCompare(b.subject ?? '', 'fr'));
});

const rateTone = computed(() => {
    const rate = attendance.value?.rate;

    if (rate === null || rate === undefined) {
        return 'badge-muted';
    }

    return rate < 80 ? 'badge-bad' : rate < 90 ? 'badge-warn' : 'badge-good';
});

function studentTone(student) {
    const rate = student.attendance ? student.attendance.rate : null;

    if (rate === null || rate === undefined) {
        return 'badge-muted';
    }

    return rate < 80 ? 'badge-bad' : rate < 90 ? 'badge-warn' : 'badge-good';
}

function studentRate(student) {
    const rate = student.attendance ? student.attendance.rate : null;

    return rate === null || rate === undefined ? '–' : `${rate}%`;
}

onMounted(async () => {
    try {
        const { data } = await api.get(`/school-classes/${route.params.id}`);
        schoolClass.value = data.data ?? data;
    } catch (error) {
        failure.value =
            error.response?.status === 404
                ? "Cette classe n'existe pas."
                : 'Impossible de charger la fiche de la classe.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-header">
        <RouterLink to="/classes" class="btn-link"><AppIcon name="back" :size="16" />Retour aux classes</RouterLink>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <LoadingState v-else-if="loading" :rows="4" />

    <template v-else-if="schoolClass">
        <header class="card profile-head">
            <div>
                <h1>{{ schoolClass.name }}</h1>
                <p class="profile-facts muted">
                    <span>Niveau {{ schoolClass.level || '–' }}</span>
                    <span> · {{ roster.length }} élèves</span>
                    <span v-if="schoolClass.capacity"> · {{ schoolClass.capacity }} places</span>
                </p>
            </div>
            <span class="badge badge-lg" :class="rateTone">
                {{ attendance && attendance.rate !== null ? `${attendance.rate}%` : '–' }}
            </span>
        </header>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value">{{ roster.length }}</div>
                <div class="stat-label">Élèves</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ attendance ? attendance.absences : 0 }}</div>
                <div class="stat-label">Absences</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ attendance ? attendance.lates : 0 }}</div>
                <div class="stat-label">Retards</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ slots.length }}</div>
                <div class="stat-label">Créneaux / semaine</div>
            </div>
        </div>

        <div class="panels">
            <section class="card panel">
                <header class="panel-head">
                    <h2>Élèves</h2>
                    <span class="panel-count">{{ roster.length }}</span>
                </header>

                <EmptyState v-if="!roster.length" title="Aucun élève." compact />

                <ul v-else class="rows">
                    <li v-for="student in roster" :key="student.id" class="row">
                        <div class="row-main">
                            <span class="row-title">
                                <RouterLink :to="`/students/${student.id}`">{{ student.name }}</RouterLink>
                            </span>
                            <span class="row-meta">{{ student.matricule }}</span>
                        </div>
                        <span class="badge" :class="studentTone(student)">{{ studentRate(student) }}</span>
                    </li>
                </ul>
            </section>

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
                                    {{ slot.teacher || 'sans enseignant' }}
                                    <template v-if="slot.room"> · {{ slot.room }}</template>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="card panel">
                <header class="panel-head">
                    <h2>Enseignants</h2>
                    <span class="panel-count">{{ staff.length }} matières</span>
                </header>

                <EmptyState v-if="!staff.length" title="Aucun enseignant." compact />

                <ul v-else class="rows">
                    <li v-for="entry in staff" :key="entry.key" class="row">
                        <div class="row-main">
                            <span class="row-title">
                                <RouterLink :to="`/teachers/${entry.teacher.id}`">{{ entry.teacher.name }}</RouterLink>
                            </span>
                            <span class="row-meta">{{ entry.subject || 'matière non renseignée' }}</span>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </template>
</template>
