<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AppModal from '../components/AppModal.vue';
import FormField from '../components/FormField.vue';
import { unwrapList, useResource } from '../composables/useResource';
import api from '../lib/api';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';

const days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

// Only for a slot whose lesson has no teacher on it - a real gap in the
// data, not a colour we ran out of.
const neutralColor = '#6b7684';

/**
 * Where the first teacher's hue starts. Off pure red so that no swatch lands
 * on the danger colour, which means something else everywhere in the app.
 */
const HUE_ORIGIN = 18;

/**
 * Saturation, and the lightness band the generated colours are kept inside.
 *
 * The band matters: a slot tints its background from this colour and draws a
 * 3px bar in it, so a swatch that came out near-white would vanish against
 * the cell and a near-black one would read as a border rather than a colour.
 */
const SWATCH_SATURATION = 62;
const SWATCH_LIGHTNESS = 42;
const YELLOW_CORRECTION = 8;

/**
 * A colour for the teacher sitting at `index` in a roster of `total`.
 *
 * Hues are spread evenly around the wheel and sized to the roster, so the set
 * is as far apart as it can be for however many teachers the school has -
 * where the old fixed list of ten simply ran out and dropped everyone after
 * the tenth into the same grey.
 */
function hueFor(index, total) {
    return (HUE_ORIGIN + (index * 360) / Math.max(total, 1)) % 360;
}

function swatchFor(index, total) {
    const hue = hueFor(index, total);

    // Yellow and green read much lighter than blue and violet at the same HSL
    // lightness - a plain fixed lightness gives a set that looks evenly
    // spaced on paper but has washed-out swatches around 60deg. Darkening
    // that side of the wheel keeps every swatch at a similar weight against
    // white, and keeps the slot text above it legible.
    const towardsYellow = Math.max(0, Math.cos(((hue - 60) * Math.PI) / 180));
    const lightness = SWATCH_LIGHTNESS - YELLOW_CORRECTION * towardsYellow;

    return `hsl(${hue.toFixed(1)} ${SWATCH_SATURATION}% ${lightness.toFixed(1)}%)`;
}

/**
 * Hand out the evenly spaced hues in a scattered order rather than in a line.
 *
 * With 28 teachers, consecutive hues are under 13deg apart - neighbours in
 * the legend would be all but the same colour, which is exactly where telling
 * them apart matters most. Stepping through the set by a stride coprime with
 * its size visits every hue exactly once, so the colours stay evenly spread,
 * but puts a wide gap between entries that sit next to each other.
 */
function scatterStride(total) {
    // Roughly the golden-ratio fraction of the set, then walked upwards to the
    // first value that is coprime with it. Coprimality is what guarantees the
    // walk is a permutation rather than a short cycle that repeats colours.
    let stride = Math.max(1, Math.round(total * 0.382));

    while (stride < total && greatestCommonDivisor(stride, total) !== 1) {
        stride += 1;
    }

    return greatestCommonDivisor(stride, total) === 1 ? stride : 1;
}

function greatestCommonDivisor(a, b) {
    return b === 0 ? a : greatestCommonDivisor(b, a % b);
}

// The grid has to show a whole week at once, so this page asks for the
// whole (bounded) timetable instead of paginating. If a school ever outgrows
// that ceiling the template warns rather than dropping slots silently.
const { items, loading, meta, saving, errors, failure, load, save, destroy } =
    useResource('/timetables', { perPage: 600 });

const lessons = ref([]);
const teachers = ref([]);
const schoolClasses = ref([]);
// Reported separately from the grid's own failure: the week can render fine
// while the lists behind the slot form do not, and the two want different
// wording.
const optionsFailure = ref('');

/**
 * Which week the page is showing: the whole school, one class, or one
 * teacher. Kept as an explicit mode rather than two selects that clear each
 * other, so the page never sits in an ambiguous half-filtered state.
 */
const viewMode = ref('all');
const filterClassId = ref('');
const filterTeacherId = ref('');

const VIEW_MODES = [
    { value: 'all', label: 'Vue générale' },
    { value: 'class', label: 'Par classe' },
    { value: 'teacher', label: 'Par enseignant' },
];

/**
 * The entries the grid shows. Filtered here rather than server-side: the whole
 * (bounded) timetable is already loaded, so a second request would buy
 * nothing.
 *
 * Every view renders through this, so "Par classe" and "Par enseignant" are
 * the same grid as "Vue générale" with fewer entries in it - not a second
 * layout with its own look.
 */
const visibleItems = computed(() => {
    if (viewMode.value === 'class') {
        return filterClassId.value
            ? items.value.filter(
                (entry) => entry.lesson && entry.lesson.school_class
                    && entry.lesson.school_class.id === Number(filterClassId.value),
            )
            : [];
    }

    if (viewMode.value === 'teacher') {
        return filterTeacherId.value
            ? items.value.filter(
                (entry) => entry.lesson && entry.lesson.teacher
                    && entry.lesson.teacher.id === Number(filterTeacherId.value),
            )
            : [];
    }

    return items.value;
});

const focusedTitle = computed(() => {
    if (viewMode.value === 'class') {
        const found = schoolClasses.value.find((c) => c.id === Number(filterClassId.value));

        return found ? found.name : '';
    }

    const found = teachers.value.find((t) => t.id === Number(filterTeacherId.value));

    return found ? found.name : '';
});

const hasSelection = computed(() =>
    viewMode.value === 'all'
    || (viewMode.value === 'class' && !!filterClassId.value)
    || (viewMode.value === 'teacher' && !!filterTeacherId.value));

// Leaving a mode drops its selection, so returning to it starts clean.
watch(viewMode, (mode) => {
    if (mode !== 'class') {
        filterClassId.value = '';
    }

    if (mode !== 'teacher') {
        filterTeacherId.value = '';
    }
});
const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function toHm(value) {
    return value ? value.slice(0, 5) : '';
}

const slots = computed(() => {
    const found = new Map();

    for (const entry of visibleItems.value) {
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

    for (const entry of visibleItems.value) {
        const key = `${entry.day_of_week}|${toHm(entry.start_time)}-${toHm(entry.end_time)}`;

        if (!grouped.has(key)) {
            grouped.set(key, []);
        }

        grouped.get(key).push(entry);
    }

    return grouped;
});

const offGrid = computed(() => visibleItems.value.filter((entry) => !days.includes(entry.day_of_week)));

/**
 * The last slot each day actually teaches. Samedi is a half day, so its
 * afternoon rows are not empty cells waiting to be filled - the school is
 * shut. Deriving this from the data rather than hardcoding the hours keeps
 * the grid honest if the timetable ever changes.
 */
const lastSlotByDay = computed(() => {
    const latest = new Map();

    for (const entry of visibleItems.value) {
        const start = toHm(entry.start_time);
        const day = entry.day_of_week;

        if (!latest.has(day) || start > latest.get(day)) {
            latest.set(day, start);
        }
    }

    return latest;
});

/**
 * Whether a cell falls outside its day's teaching hours. A day with no slots
 * at all is left blank rather than greyed out wholesale.
 */
function closed(day, slot) {
    const last = lastSlotByDay.value.get(day);

    return last !== undefined && slot.start > last;
}

const rosterOrder = computed(() => [...teachers.value].sort((a, b) => a.id - b.id));

/**
 * One distinct colour per teacher, generated from the size of the roster.
 *
 * Keyed off the whole roster rather than whoever is on screen, so a teacher
 * keeps the same colour when the grid is filtered down to one class.
 */
const teacherColors = computed(() => {
    const roster = rosterOrder.value;
    const total = roster.length;
    const stride = scatterStride(total);
    const assigned = new Map();

    roster.forEach((teacher, index) => {
        assigned.set(teacher.id, swatchFor((index * stride) % total, total));
    });

    return assigned;
});

const legend = computed(() => {
    const present = new Set();

    for (const entry of visibleItems.value) {
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
    liveConflicts.value = [];
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
    liveConflicts.value = [];
    showForm.value = true;
    // Show the state of the slot being edited straight away.
    runConflictCheck();
}

/**
 * Live conflict check.
 *
 * The same detector runs again on save; this only moves the answer earlier,
 * so the user learns about a clash while choosing the slot rather than after
 * pressing Enregistrer.
 */
const liveConflicts = ref([]);
const checking = ref(false);
let checkTimer = null;
// Only the newest check may write its result: a slower earlier request must
// not overwrite the answer for what is now on screen.
let checkToken = 0;

const selectedLesson = computed(
    () => lessons.value.find((lesson) => lesson.id === Number(form.value.lesson_id)) ?? null,
);

/**
 * Conflict messages the backend returned when a save was rejected. They are
 * shown together at the top of the form rather than only under one field.
 */
const submitConflicts = computed(() =>
    Object.values(errors.value ?? {})
        .flat()
        .filter((message) => typeof message === 'string' && message.startsWith('Conflit')),
);

async function runConflictCheck() {
    const { lesson_id: lessonId, day_of_week: day, start_time: start, end_time: end } = form.value;
    const lesson = selectedLesson.value;

    // Nothing meaningful to ask about until the slot is actually described.
    if (!lessonId || !day || !start || !end || end <= start) {
        liveConflicts.value = [];

        return;
    }

    const token = ++checkToken;
    checking.value = true;

    try {
        const { data } = await api.get('/timetables/check-conflicts', {
            params: {
                day_of_week: day,
                start_time: start,
                end_time: end,
                teacher_id: lesson ? lesson.teacher_id : null,
                school_class_id: lesson ? lesson.school_class_id : null,
                room: form.value.room || null,
                exclude_id: editingId.value,
            },
        });

        if (token === checkToken) {
            liveConflicts.value = data.conflicts ?? [];
        }
    } catch (error) {
        // A failed pre-check is not worth interrupting the user for; the save
        // itself still enforces the rule.
        if (token === checkToken) {
            liveConflicts.value = [];
        }
    } finally {
        if (token === checkToken) {
            checking.value = false;
        }
    }
}

watch(
    () => [form.value.lesson_id, form.value.day_of_week, form.value.start_time, form.value.end_time, form.value.room],
    () => {
        if (!showForm.value) {
            return;
        }

        clearTimeout(checkTimer);
        checkTimer = setTimeout(runConflictCheck, 400);
    },
);

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;

        return;
    }

    // Rejected: re-run the check so the inline warning agrees with the error.
    await runConflictCheck();
}

async function removeEditing() {
    if (window.confirm('Supprimer ce créneau ?')) {
        await destroy(editingId.value);
        showForm.value = false;
    }
}

onMounted(async () => {
    await load();

    try {
        const [lessonResponse, teacherResponse, classResponse] = await Promise.all([
            api.get('/lessons', { params: { per_page: 200 } }),
            api.get('/teachers', { params: { per_page: 200 } }),
            api.get('/school-classes', { params: { per_page: 200 } }),
        ]);
        lessons.value = unwrapList(lessonResponse.data);
        teachers.value = unwrapList(teacherResponse.data);
        schoolClasses.value = unwrapList(classResponse.data);
    } catch (error) {
        // Left unhandled, this rejected into nothing and the slot form opened
        // with an empty list of lessons and no explanation for it. The view
        // filters read the same two lists, so they empty out together.
        optionsFailure.value =
            "Impossible de charger les cours, les enseignants et les classes. Rechargez la page avant de modifier l'emploi du temps.";
    }
});
</script>

<template>
    <div class="page-header">
        <h1>Emploi du temps</h1>
        <button class="btn btn-primary" @click="openCreate"><AppIcon name="plus" :size="16" />Ajouter un créneau</button>
    </div>

    <p v-if="failure" class="alert"><AppIcon name="error" :size="16" />{{ failure }}</p>
    <p v-if="optionsFailure" class="alert"><AppIcon name="error" :size="16" />{{ optionsFailure }}</p>

    <p v-else-if="meta.last_page > 1" class="alert alert-info">
        L'emploi du temps compte {{ meta.total }} créneaux, plus que cette vue ne
        peut afficher d'un coup : seuls les {{ items.length }} premiers sont dans la
        grille.
    </p>

    <LoadingState v-if="loading && !items.length" :rows="6" />

    <div v-else-if="!items.length" class="card">
        <EmptyState title="Aucun créneau." hint="Ajoutez un créneau pour composer la semaine." icon="timetable" />
    </div>

    <template v-else>
    <div class="card view-bar">
        <div class="view-modes" role="group" aria-label="Vue">
            <button
                v-for="mode in VIEW_MODES"
                :key="mode.value"
                type="button"
                class="view-mode"
                :class="{ active: viewMode === mode.value }"
                :aria-pressed="viewMode === mode.value"
                @click="viewMode = mode.value"
            >
                {{ mode.label }}
            </button>
        </div>

        <label v-if="viewMode === 'class'" class="view-select">
            <span class="field-label">Filtrer par classe</span>
            <select v-model="filterClassId">
                <option value="">Choisir une classe</option>
                <option v-for="schoolClass in schoolClasses" :key="schoolClass.id" :value="schoolClass.id">
                    {{ schoolClass.name }}
                </option>
            </select>
        </label>

        <label v-if="viewMode === 'teacher'" class="view-select">
            <span class="field-label">Filtrer par enseignant</span>
            <select v-model="filterTeacherId">
                <option value="">Choisir un enseignant</option>
                <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">
                    {{ teacher.name }}
                </option>
            </select>
        </label>
    </div>

    <!-- Nothing chosen yet in a filtered mode. -->
    <div v-if="!hasSelection" class="card">
        <EmptyState
            :title="viewMode === 'class' ? 'Choisissez une classe.' : 'Choisissez un enseignant.'"
            icon="timetable"
        />
    </div>

    <div v-else-if="!slots.length" class="card">
        <EmptyState title="Aucun créneau." icon="timetable" />
    </div>

    <template v-else>
    <p v-if="viewMode !== 'all'" class="grid-caption">
        <strong>{{ focusedTitle }}</strong>
        <span class="muted">· {{ visibleItems.length }} créneaux</span>
    </p>

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
                    <td v-for="day in days" :key="day" :class="{ closed: closed(day, slot) }">
                        <span v-if="closed(day, slot)" class="closed-mark" aria-label="Pas de cours">–</span>
                        <button
                            v-for="entry in cell(day, slot)"
                            :key="entry.id"
                            type="button"
                            class="slot"
                            :style="{ '--slot-color': entryColor(entry) }"
                            @click="openEdit(entry)"
                        >
                            <span class="slot-title">{{ entry.lesson ? entry.lesson.title : '–' }}</span>
                            <span v-if="teacherName(entry) && viewMode !== 'teacher'" class="slot-meta">
                                {{ teacherName(entry) }}
                            </span>
                            <span class="slot-meta">
                                <template v-if="viewMode !== 'class'">{{ className(entry) }}</template>
                                <template v-if="viewMode !== 'class' && className(entry) && entry.room"> · </template>{{ entry.room }}
                            </span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    </template>
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
                    <td>{{ entry.lesson ? entry.lesson.title : '–' }}</td>
                    <td>{{ entry.room || '–' }}</td>
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
        <div v-if="submitConflicts.length" class="alert conflict-alert">
            <p v-for="message in submitConflicts" :key="message">{{ message }}</p>
        </div>

        <div v-else-if="liveConflicts.length" class="conflict-warning">
            <p class="conflict-title"><AppIcon name="warning" :size="16" />Conflit détecté</p>
            <p v-for="conflict in liveConflicts" :key="`${conflict.kind}-${conflict.timetable_id}`">
                {{ conflict.message }}
            </p>
        </div>

        <p v-else-if="checking" class="conflict-checking muted">Vérification des conflits…</p>

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
.grid-caption {
    margin: 0 0 var(--space-3);
    font-size: var(--text-base);
}

.view-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-4);
    margin-bottom: var(--space-4);
    padding: var(--space-3) var(--space-4);
}

.view-modes {
    display: inline-flex;
    padding: var(--space-1);
    background: var(--canvas);
    border-radius: var(--radius-md);
}

.view-mode {
    padding: var(--space-2) var(--space-3);
    font: inherit;
    font-size: var(--text-base);
    color: var(--muted);
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
}

.view-mode:hover {
    color: var(--text);
}

.view-mode.active {
    color: var(--accent-ink);
    background: var(--surface);
    font-weight: var(--weight-medium);
    box-shadow: var(--shadow-sm);
}

.view-select {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 220px;
}

.view-select select {
    padding: var(--space-2) var(--space-3);
    font: inherit;
    font-size: var(--text-base);
    color: var(--text);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
}

.view-select select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.focus-panel .row-time {
    width: 104px;
}

.conflict-alert p,
.conflict-warning p {
    margin: 0 0 var(--space-2);
}

.conflict-alert p:last-child,
.conflict-warning p:last-child {
    margin-bottom: 0;
}

.conflict-warning {
    margin-bottom: var(--space-3);
    padding: var(--space-3) var(--space-3);
    border: 1px solid var(--warn-line);
    border-radius: 6px;
    background: var(--warn-soft);
    color: var(--warn-ink);
    font-size: var(--text-sm);
}

.conflict-title {
    font-weight: 600;
}

.conflict-checking {
    margin: 0 0 var(--space-3);
    font-size: var(--text-xs);
}

.grid-scroll {
    max-height: calc(100vh - 150px);
    overflow: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
}

.timetable-grid {
    width: 100%;
    min-width: 900px;
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

/* Outside the day's teaching hours - Samedi afternoon, for instance. */
.timetable-grid td.closed {
    background: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 6px,
        var(--border) 6px,
        var(--border) 7px
    );
    text-align: center;
}

.closed-mark {
    color: var(--muted);
    font-size: var(--text-xs);
}

.timetable-grid thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: var(--space-3) var(--space-3);
    text-align: center;
    background: var(--surface-alt);
}

.timetable-grid .time-col {
    position: sticky;
    left: 0;
    z-index: 1;
    width: 92px;
    min-width: 92px;
    padding: var(--space-3) var(--space-3);
    text-align: left;
    background: var(--surface-alt);
}

.timetable-grid thead .time-col {
    z-index: 3;
}

.time-start,
.time-end {
    display: block;
    font-size: var(--text-sm);
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
    padding: var(--space-1);
    vertical-align: top;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2) var(--space-5);
    margin: 0 0 var(--space-4);
    padding: 0;
    list-style: none;
}

.legend li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-sm);
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
    padding: var(--space-2) var(--space-3);
    font: inherit;
    text-align: left;
    color: var(--text);
    background: color-mix(in srgb, var(--slot-color) 10%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--slot-color) 28%, var(--surface));
    border-left: 3px solid var(--slot-color);
    border-radius: 4px;
    cursor: pointer;
}

.slot + .slot {
    margin-top: var(--space-1);
}

.slot:hover {
    background: color-mix(in srgb, var(--slot-color) 18%, var(--surface));
}

.slot-title {
    display: block;
    font-size: var(--text-base);
    font-weight: 600;
    line-height: 1.3;
}

.slot-meta {
    display: block;
    margin-top: 2px;
    font-size: var(--text-xs);
    color: var(--muted);
    line-height: 1.3;
}

.modal-remove {
    margin-top: var(--space-1);
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.off-grid {
    margin-top: var(--space-6);
}

.off-grid h2 {
    margin: 0 0 var(--space-3);
    font-size: var(--text-md);
    font-weight: 600;
}
</style>
