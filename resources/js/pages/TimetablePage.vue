<script setup>
import { computed, onMounted, ref } from 'vue';
import AppModal from '../components/AppModal.vue';
import FormField from '../components/FormField.vue';
import { useResource } from '../composables/useResource';
import api from '../lib/api';

const days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];

const teacherPalette = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948'];
const neutralColor = '#898781';

const { items, loading, saving, errors, failure, load, save, destroy } = useResource('/timetables');

const lessons = ref([]);
const teachers = ref([]);
const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function toHm(value) {
    return value ? value.slice(0, 5) : '';
}

const slots = computed(() => {
    const found = new Map();

    for (const entry of items.value) {
        const start = toHm(entry.start_time);
        const end = toHm(entry.end_time);
        const key = `${start}-${end}`;

        if (!found.has(key)) {
            found.set(key, { key, start, end });
        }
    }

    return [...found.values()].sort(
        (a, b) => a.start.localeCompare(b.start) || a.end.localeCompare(b.end),
    );
});

const cells = computed(() => {
    const grouped = new Map();

    for (const entry of items.value) {
        const key = `${entry.day_of_week}|${toHm(entry.start_time)}-${toHm(entry.end_time)}`;

        if (!grouped.has(key)) {
            grouped.set(key, []);
        }

        grouped.get(key).push(entry);
    }

    return grouped;
});

const offGrid = computed(() => items.value.filter((entry) => !days.includes(entry.day_of_week)));

const rosterOrder = computed(() => [...teachers.value].sort((a, b) => a.id - b.id));

const teacherColors = computed(() => {
    const assigned = new Map();

    rosterOrder.value.forEach((teacher, index) => {
        assigned.set(teacher.id, index < teacherPalette.length ? teacherPalette[index] : neutralColor);
    });

    return assigned;
});

const legend = computed(() => {
    const present = new Set();

    for (const entry of items.value) {
        if (entry.lesson && entry.lesson.teacher) {
            present.add(entry.lesson.teacher.id);
        }
    }

    return rosterOrder.value
        .filter((teacher) => present.has(teacher.id))
        .map((teacher) => ({ ...teacher, color: teacherColors.value.get(teacher.id) }));
});

function cell(day, slot) {
    return cells.value.get(`${day}|${slot.key}`) ?? [];
}

function teacherName(entry) {
    return entry.lesson && entry.lesson.teacher ? entry.lesson.teacher.name : '';
}

function entryColor(entry) {
    const teacher = entry.lesson ? entry.lesson.teacher : null;

    return (teacher && teacherColors.value.get(teacher.id)) || neutralColor;
}

function className(entry) {
    return entry.lesson && entry.lesson.school_class ? entry.lesson.school_class.name : '';
}

function blankForm() {
    return { lesson_id: '', day_of_week: 'Lundi', start_time: '', end_time: '', room: '' };
}

function openCreate() {
    editingId.value = null;
    form.value = blankForm();
    errors.value = {};
    showForm.value = true;
}

function openEdit(slot) {
    editingId.value = slot.id;
    form.value = {
        lesson_id: slot.lesson_id,
        day_of_week: slot.day_of_week,
        start_time: toHm(slot.start_time),
        end_time: toHm(slot.end_time),
        room: slot.room ?? '',
    };
    errors.value = {};
    showForm.value = true;
}

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;
    }
}

async function removeEditing() {
    if (window.confirm('Supprimer ce créneau ?')) {
        await destroy(editingId.value);
        showForm.value = false;
    }
}

onMounted(async () => {
    await load();
    const [lessonResponse, teacherResponse] = await Promise.all([
        api.get('/lessons'),
        api.get('/teachers'),
    ]);
    lessons.value = lessonResponse.data;
    teachers.value = teacherResponse.data;
});
</script>

<template>
    <div class="page-header">
        <h1>Emploi du temps</h1>
        <button class="btn btn-primary" @click="openCreate">Ajouter un créneau</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <div v-if="loading" class="state">Chargement…</div>

    <div v-else-if="!slots.length" class="card state">Aucun créneau.</div>

    <template v-else>
    <ul v-if="legend.length > 1" class="legend">
        <li v-for="teacher in legend" :key="teacher.id">
            <span class="legend-swatch" :style="{ backgroundColor: teacher.color }"></span>
            {{ teacher.name }}
        </li>
    </ul>

    <div class="grid-scroll">
        <table class="timetable-grid">
            <thead>
                <tr>
                    <th class="time-col">Horaire</th>
                    <th v-for="day in days" :key="day">{{ day }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="slot in slots" :key="slot.key">
                    <th class="time-col">
                        <span class="time-start">{{ slot.start }}</span>
                        <span class="time-end">{{ slot.end }}</span>
                    </th>
                    <td v-for="day in days" :key="day">
                        <button
                            v-for="entry in cell(day, slot)"
                            :key="entry.id"
                            type="button"
                            class="slot"
                            :style="{ '--slot-color': entryColor(entry) }"
                            @click="openEdit(entry)"
                        >
                            <span class="slot-title">{{ entry.lesson ? entry.lesson.title : '—' }}</span>
                            <span v-if="teacherName(entry)" class="slot-meta">{{ teacherName(entry) }}</span>
                            <span class="slot-meta">
                                {{ className(entry) }}<template v-if="className(entry) && entry.room"> · </template>{{ entry.room }}
                            </span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    </template>

    <div v-if="offGrid.length" class="off-grid">
        <h2>Autres jours</h2>
        <table>
            <thead>
                <tr>
                    <th>Jour</th>
                    <th>Horaire</th>
                    <th>Cours</th>
                    <th>Salle</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in offGrid" :key="entry.id">
                    <td>{{ entry.day_of_week }}</td>
                    <td>{{ toHm(entry.start_time) }} – {{ toHm(entry.end_time) }}</td>
                    <td>{{ entry.lesson ? entry.lesson.title : '—' }}</td>
                    <td>{{ entry.room || '—' }}</td>
                    <td class="actions">
                        <button class="btn-link" @click="openEdit(entry)">Modifier</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <AppModal
        v-if="showForm"
        :title="editingId ? 'Modifier le créneau' : 'Nouveau créneau'"
        :saving="saving"
        @close="showForm = false"
        @submit="submit"
    >
        <FormField label="Cours" :error="errors.lesson_id">
            <select v-model="form.lesson_id" required>
                <option value="">Choisir un cours</option>
                <option v-for="lesson in lessons" :key="lesson.id" :value="lesson.id">
                    {{ lesson.title }}
                </option>
            </select>
        </FormField>
        <FormField label="Jour" :error="errors.day_of_week">
            <select v-model="form.day_of_week" required>
                <option v-for="day in days" :key="day" :value="day">{{ day }}</option>
            </select>
        </FormField>
        <FormField label="Début" :error="errors.start_time">
            <input v-model="form.start_time" type="time" required>
        </FormField>
        <FormField label="Fin" :error="errors.end_time">
            <input v-model="form.end_time" type="time" required>
        </FormField>
        <FormField label="Salle" :error="errors.room">
            <input v-model="form.room" type="text">
        </FormField>

        <div v-if="editingId" class="modal-remove">
            <button type="button" class="btn-link danger" @click="removeEditing">
                Supprimer ce créneau
            </button>
        </div>
    </AppModal>
</template>

<style scoped>
.grid-scroll {
    max-height: calc(100vh - 150px);
    overflow: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
}

.timetable-grid {
    width: 100%;
    min-width: 780px;
    border: none;
    border-radius: 0;
    border-collapse: separate;
    border-spacing: 0;
    overflow: visible;
}

.timetable-grid th,
.timetable-grid td {
    border: none;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.timetable-grid th:last-child,
.timetable-grid td:last-child {
    border-right: none;
}

.timetable-grid thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 10px 12px;
    text-align: center;
    background: #fafbfc;
}

.timetable-grid .time-col {
    position: sticky;
    left: 0;
    z-index: 1;
    width: 92px;
    min-width: 92px;
    padding: 10px 12px;
    text-align: left;
    background: #fafbfc;
}

.timetable-grid thead .time-col {
    z-index: 3;
}

.time-start,
.time-end {
    display: block;
    font-size: 13px;
    letter-spacing: 0;
    text-transform: none;
}

.time-start {
    color: var(--text);
    font-weight: 600;
}

.time-end {
    color: var(--muted);
}

.timetable-grid tbody td {
    height: 78px;
    padding: 5px;
    vertical-align: top;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    margin: 0 0 14px;
    padding: 0;
    list-style: none;
}

.legend li {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    color: var(--muted);
}

.legend-swatch {
    width: 11px;
    height: 11px;
    border-radius: 3px;
}

.slot {
    display: block;
    width: 100%;
    padding: 7px 9px;
    font: inherit;
    text-align: left;
    color: var(--text);
    background: color-mix(in srgb, var(--slot-color) 10%, #ffffff);
    border: 1px solid color-mix(in srgb, var(--slot-color) 28%, #ffffff);
    border-left: 3px solid var(--slot-color);
    border-radius: 4px;
    cursor: pointer;
}

.slot + .slot {
    margin-top: 5px;
}

.slot:hover {
    background: color-mix(in srgb, var(--slot-color) 18%, #ffffff);
}

.slot-title {
    display: block;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.3;
}

.slot-meta {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.3;
}

.modal-remove {
    margin-top: 4px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.off-grid {
    margin-top: 26px;
}

.off-grid h2 {
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 600;
}
</style>
