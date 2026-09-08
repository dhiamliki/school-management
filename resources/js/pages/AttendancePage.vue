<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import FormField from '../components/FormField.vue';
import { unwrapList } from '../composables/useResource';
import api from '../lib/api';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';

const STATUSES = [
    { value: 'présent', label: 'Présent', short: 'P' },
    { value: 'absent', label: 'Absent', short: 'A' },
    { value: 'retard', label: 'Retard', short: 'R' },
];

// Monday is 1 in the school's own numbering; Sunday (7) is not a school day.
const WEEKDAYS = { 1: 'Lundi', 2: 'Mardi', 3: 'Mercredi', 4: 'Jeudi', 5: 'Vendredi', 6: 'Samedi' };

const FULL_DAY = '';

const schoolClasses = ref([]);
const students = ref([]);
const lessons = ref([]);
const timetables = ref([]);

const date = ref(new Date().toISOString().slice(0, 10));
const classId = ref('');
const lessonId = ref(FULL_DAY);

const marks = ref({});
const loading = ref(false);
const saving = ref(false);
const failure = ref('');
const notice = ref('');

/**
 * The French name of the selected day, or null for a Sunday.
 */
const weekday = computed(() => {
    if (!date.value) {
        return null;
    }

    // getUTCDay() on a plain Y-m-d avoids the local-timezone off-by-one.
    const day = new Date(`${date.value}T00:00:00Z`).getUTCDay();

    return WEEKDAYS[day === 0 ? 7 : day] ?? null;
});

const isSchoolDay = computed(() => weekday.value !== null);

/**
 * The lessons the chosen class actually has on the chosen weekday. Marking
 * against a lesson the class does not have that day would invent history, so
 * only real slots are offered.
 */
const availableLessons = computed(() => {
    if (!classId.value || !weekday.value) {
        return [];
    }

    const todaysLessonIds = new Set(
        timetables.value
            .filter((slot) => slot.day_of_week === weekday.value)
            .map((slot) => slot.lesson_id),
    );

    return lessons.value
        .filter((lesson) => lesson.school_class_id === Number(classId.value))
        .filter((lesson) => todaysLessonIds.has(lesson.id));
});

const roster = computed(() => students.value);

const tally = computed(() => {
    const counts = { présent: 0, absent: 0, retard: 0, unmarked: 0 };

    for (const student of roster.value) {
        const status = marks.value[student.id];
        if (status) {
            counts[status]++;
        } else {
            counts.unmarked++;
        }
    }

    return counts;
});

const canSave = computed(
    () => !saving.value && roster.value.length > 0 && tally.value.unmarked < roster.value.length,
);

function setMark(studentId, status) {
    // Clicking the status a pupil already has clears it, so a misclick can be
    // undone without saving something wrong.
    marks.value = {
        ...marks.value,
        [studentId]: marks.value[studentId] === status ? undefined : status,
    };
    notice.value = '';
}

function markAllPresent() {
    const next = { ...marks.value };

    for (const student of roster.value) {
        next[student.id] = 'présent';
    }

    marks.value = next;
    notice.value = '';
}

function clearMarks() {
    marks.value = {};
    notice.value = '';
}

/**
 * Pull whatever is already recorded for this date and lesson so the grid
 * opens on the current state rather than blank.
 */
async function loadRoster() {
    if (!classId.value) {
        students.value = [];

        return;
    }

    const { data } = await api.get('/students', {
        params: { school_class_id: classId.value, per_page: 200 },
    });

    // Alphabetical: a register is read down a list of names, not by row id.
    students.value = unwrapList(data).sort((a, b) => a.name.localeCompare(b.name, 'fr'));
}

async function loadMarks() {
    if (!classId.value || !isSchoolDay.value) {
        marks.value = {};

        return;
    }

    loading.value = true;
    failure.value = '';

    try {
        await loadRoster();

        const { data } = await api.get('/attendances', {
            params: { date: date.value, school_class_id: classId.value, per_page: 200 },
        });

        const wanted = lessonId.value === FULL_DAY ? null : Number(lessonId.value);
        const next = {};

        for (const row of unwrapList(data)) {
            if ((row.lesson_id ?? null) === wanted) {
                next[row.student_id] = row.status;
            }
        }

        marks.value = next;
    } catch (error) {
        failure.value = 'Impossible de charger les présences de cette date.';
        students.value = [];
    } finally {
        loading.value = false;
    }
}

async function submit() {
    const payload = roster.value
        .filter((student) => marks.value[student.id])
        .map((student) => ({ student_id: student.id, status: marks.value[student.id] }));

    if (!payload.length) {
        return;
    }

    saving.value = true;
    failure.value = '';
    notice.value = '';

    try {
        const { data } = await api.post('/attendances/bulk', {
            date: date.value,
            lesson_id: lessonId.value === FULL_DAY ? null : Number(lessonId.value),
            marks: payload,
        });

        notice.value = `${data.saved} présence(s) enregistrée(s).`;
    } catch (error) {
        failure.value = "Impossible d'enregistrer les présences.";
    } finally {
        saving.value = false;
    }
}

// A lesson only makes sense within the class and day it belongs to.
watch([classId, date], () => {
    lessonId.value = FULL_DAY;
    loadMarks();
});

watch(lessonId, loadMarks);

onMounted(async () => {
    loading.value = true;

    try {
        const [classResponse, lessonResponse, timetableResponse] = await Promise.all([
            api.get('/school-classes', { params: { per_page: 200 } }),
            api.get('/lessons', { params: { per_page: 200 } }),
            api.get('/timetables', { params: { per_page: 600 } }),
        ]);

        schoolClasses.value = unwrapList(classResponse.data);
        lessons.value = unwrapList(lessonResponse.data);
        timetables.value = unwrapList(timetableResponse.data);
    } catch (error) {
        failure.value = 'Impossible de charger les données.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-header">
        <h1>Présences</h1>
        <button class="btn btn-primary" :disabled="!canSave" @click="submit">
            {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
    </div>

    <p v-if="failure" class="alert"><AppIcon name="error" :size="16" />{{ failure }}</p>
    <p v-if="notice" class="alert alert-success"><AppIcon name="check" :size="16" />{{ notice }}</p>

    <div class="card filters">
        <FormField label="Date">
            <input v-model="date" type="date" required>
        </FormField>
        <FormField label="Classe">
            <select v-model="classId">
                <option value="">Choisir une classe</option>
                <option v-for="schoolClass in schoolClasses" :key="schoolClass.id" :value="schoolClass.id">
                    {{ schoolClass.name }}
                </option>
            </select>
        </FormField>
        <FormField label="Cours">
            <select v-model="lessonId" :disabled="!classId">
                <option :value="FULL_DAY">Journée entière</option>
                <option v-for="lesson in availableLessons" :key="lesson.id" :value="lesson.id">
                    {{ lesson.title }} ({{ lesson.subject }})
                </option>
            </select>
        </FormField>
    </div>

    <div v-if="!isSchoolDay" class="card">
        <EmptyState :title="`Le ${date} est un dimanche : l'école est fermée.`" icon="timetable" />
    </div>

    <div v-else-if="!classId" class="card">
        <EmptyState title="Choisissez une classe pour saisir les présences." icon="attendance" />
    </div>

    <LoadingState v-else-if="loading" :rows="6" />

    <div v-else-if="!roster.length" class="card">
        <EmptyState title="Aucun élève dans cette classe." icon="students" />
    </div>

    <template v-else>
        <div class="toolbar">
            <div class="tally">
                <span class="badge badge-present">{{ tally['présent'] }} présents</span>
                <span class="badge badge-absent">{{ tally.absent }} absents</span>
                <span class="badge badge-late">{{ tally.retard }} retards</span>
                <span v-if="tally.unmarked" class="badge badge-muted">{{ tally.unmarked }} non saisis</span>
            </div>
            <div class="toolbar-actions">
                <button type="button" class="btn-link" @click="markAllPresent">Tout présent</button>
                <button type="button" class="btn-link danger" @click="clearMarks">Effacer</button>
            </div>
        </div>

        <div class="table-scroll">
            <table>
            <thead>
                <tr>
                    <th>Élève</th>
                    <th class="marks-col">Présence</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="student in roster" :key="student.id">
                    <td>{{ student.name }}</td>
                    <td class="marks-col">
                        <div class="marks">
                            <button
                                v-for="status in STATUSES"
                                :key="status.value"
                                type="button"
                                class="mark"
                                :class="[`mark-${status.short}`, { active: marks[student.id] === status.value }]"
                                :aria-pressed="marks[student.id] === status.value"
                                :title="status.label"
                                @click="setMark(student.id, status.value)"
                            >
                                {{ status.label }}
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    </template>
</template>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3) var(--space-5);
    margin-bottom: var(--space-4);
}

.filters :deep(.field) {
    min-width: 190px;
}
.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3) var(--space-4);
    margin-bottom: var(--space-3);
}

.tally {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.toolbar-actions {
    display: flex;
    gap: var(--space-4);
}

.marks-col {
    width: 1%;
    white-space: nowrap;
}

.marks {
    display: flex;
    gap: var(--space-2);
}

.mark {
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    font-size: var(--text-sm);
    cursor: pointer;
}

.mark:hover {
    border-color: var(--border-strong);
}

.mark.active {
    color: var(--surface);
    font-weight: 500;
}

.mark-P.active {
    background: var(--ok);
    border-color: var(--ok);
}

.mark-A.active {
    background: var(--danger);
    border-color: var(--danger);
}

.mark-R.active {
    background: var(--warn);
    border-color: var(--warn);
}

.badge {
    display: inline-block;
    padding: var(--space-1) var(--space-3);
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 500;
}

.badge-present {
    background: var(--ok-soft);
    color: var(--ok-ink);
}

.badge-absent {
    background: var(--danger-soft);
    color: var(--danger-ink);
}

.badge-late {
    background: var(--warn-soft);
    color: var(--warn-ink);
}

.badge-muted {
    background: var(--canvas);
    color: var(--muted);
}
</style>
