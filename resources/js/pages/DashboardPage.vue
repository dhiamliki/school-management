<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';
import { unwrapList, unwrapMeta } from '../composables/useResource';
import LoadingState from '../components/LoadingState.vue';
import EmptyState from '../components/EmptyState.vue';
import AppIcon from '../components/AppIcon.vue';
import ChartCanvas from '../components/ChartCanvas.vue';
import MiniCalendar from '../components/MiniCalendar.vue';

/**
 * The absence rate above which the Alertes panel reports a pupil. Mirrors
 * Attendance::AT_RISK_RATE, which is what the endpoint actually applies; this
 * copy exists only so the panel can say what it is measuring.
 */
const AT_RISK_RATE = 15;
const ALERTS_SHOWN = 5;

/**
 * Chart colours - the dashboard's own palette.
 *
 * This page, and only this page, departs from the single blue accent the rest
 * of the app uses: the two charts run on the reference's soft blue and yellow
 * rather than on the app's semantic green / amber / red. That is a deliberate
 * exception and it stops at the charts: every badge, pill and row elsewhere on
 * the page - the Alertes rates included - still uses the semantic palette,
 * where the colour is the meaning rather than the styling.
 *
 * Retards needs a third colour the two-tone reference does not supply; the
 * soft violet is taken from the same family, and keeps the tone consistent.
 *
 * The values are needed in JavaScript, so they are declared here and mirrored
 * by the --dash-* custom properties in the style block; the two are meant to
 * stay in step. Each legend reads its swatch from the same constant that
 * colours the chart it belongs to - genderSlices for the donut,
 * attendanceSeries for the bars - so a legend dot cannot drift away from the
 * slice or bar it stands for.
 */
const CHART_BLUE = '#8ecfe8';
const CHART_YELLOW = '#f4cf5b';
const CHART_VIOLET = '#b0a2f0';
const UNKNOWN_GREY = '#d7dce6';

const counts = ref({});
const context = ref({});

const quickActions = [
    { label: '+ Ajouter un élève', to: '/students' },
    { label: '+ Ajouter un enseignant', to: '/teachers' },
    { label: '+ Créer un cours', to: '/lessons' },
    { label: 'Marquer les présences', to: '/attendance' },
];

const atRisk = ref([]);
const atRiskTotal = ref(0);
const schedule = ref([]);
const scheduleDay = ref(null);
const isSchoolDay = ref(true);
const activity = ref([]);
const gender = ref(null);
const week = ref([]);

// Today's marks: present over everything marked so far today.
const markedToday = ref(null);
const todayRate = ref(null);
// The previous teaching day's rate and the gap between the two. Both stay
// null unless each day actually has a register.
const previousDay = ref(null);
const rateChange = ref(null);

const loading = ref(true);
const failure = ref('');

const alerts = computed(() => atRisk.value.slice(0, ALERTS_SHOWN));

/* ---------------------------------------------------------------------------
   Stat tiles
--------------------------------------------------------------------------- */

/**
 * The headline tiles, each with the badge it has earned.
 *
 * Every badge is a real figure derived from something the school already
 * records - an occupancy rate against declared capacity, an average per class,
 * a count of distinct subjects. Cours deliberately carries none: no figure
 * about lessons says anything the headline count does not, and inventing a
 * "+12 %" to fill the corner is exactly what this page must not do.
 */
const tiles = computed(() => {
    const classes = counts.value.school_classes ?? null;
    const students = counts.value.students ?? null;
    const slots = counts.value.timetables ?? null;
    const occupancy = context.value.class_occupancy;
    const subjects = context.value.teacher_subjects;

    return [
        {
            key: 'school_classes',
            label: 'Classes',
            to: '/classes',
            icon: 'classes',
            value: classes,
            badge: occupancy != null ? `${Math.round(occupancy)} % occupé` : null,
        },
        {
            key: 'students',
            label: 'Élèves',
            to: '/students',
            icon: 'students',
            value: students,
            badge: classes && students != null ? `≈ ${Math.round(students / classes)} / classe` : null,
        },
        {
            key: 'teachers',
            label: 'Enseignants',
            to: '/teachers',
            icon: 'teachers',
            value: counts.value.teachers ?? null,
            badge: subjects ? `${subjects} matières` : null,
        },
        {
            key: 'lessons',
            label: 'Cours',
            to: '/lessons',
            icon: 'lessons',
            value: counts.value.lessons ?? null,
            badge: null,
        },
        {
            key: 'timetables',
            label: 'Créneaux',
            to: '/timetable',
            icon: 'timetable',
            value: slots,
            badge: classes && slots != null ? `${Math.round(slots / classes)} h / classe` : null,
        },
    ];
});

/* ---------------------------------------------------------------------------
   Gender donut
--------------------------------------------------------------------------- */

/**
 * The slices, "non renseigné" included only when there is one - a school that
 * has recorded every pupil should not carry an empty slice in its legend.
 */
const genderSlices = computed(() => {
    if (!gender.value) {
        return [];
    }

    return [
        { label: 'Garçons', value: gender.value.male, color: CHART_BLUE },
        { label: 'Filles', value: gender.value.female, color: CHART_YELLOW },
        { label: 'Non renseigné', value: gender.value.unknown, color: UNKNOWN_GREY },
    ].filter((slice) => slice.value > 0);
});

const genderTotal = computed(() => gender.value?.total ?? 0);

function sharePercent(value) {
    return genderTotal.value ? Math.round((value / genderTotal.value) * 100) : 0;
}

const genderChart = computed(() => ({
    labels: genderSlices.value.map((slice) => slice.label),
    datasets: [
        {
            data: genderSlices.value.map((slice) => slice.value),
            backgroundColor: genderSlices.value.map((slice) => slice.color),
            borderWidth: 0,
            hoverOffset: 6,
        },
    ],
}));

const genderOptions = {
    // A thin ring around a wide open middle, as in the reference - the centre
    // is a space the pair of figures sits in, not a label slot.
    cutout: '80%',
    plugins: {
        tooltip: {
            callbacks: {
                label: (context) => ` ${context.label} : ${context.parsed} élèves`,
            },
        },
    },
};

const genderSummary = computed(() =>
    genderSlices.value.length
        ? `Répartition des ${genderTotal.value} élèves : `
            + genderSlices.value.map((slice) => `${slice.label} ${slice.value}`).join(', ')
        : 'Aucun élève inscrit.');

/* ---------------------------------------------------------------------------
   Weekly attendance
--------------------------------------------------------------------------- */

const weekHasMarks = computed(() => week.value.some((day) => day.marked > 0));

/**
 * The bars chart rates, not volumes.
 *
 * Raw counts made the chart unreadable and, worse, misleading: a day's total
 * depends on how many registers happened to be taken, so a tall bar said
 * "more marks were entered", not "attendance was better". As a share of that
 * day's own marks the six days are actually comparable, and the two or three
 * absences a primary school records stop vanishing under a block of présents.
 *
 * The counts behind each share are kept alongside and printed in the tooltip,
 * so nothing is lost by the conversion.
 */
function share(value, total) {
    return total ? Math.round((value / total) * 100) : 0;
}

const attendanceSeries = [
    { key: 'present', label: 'Présents', color: CHART_YELLOW },
    { key: 'late', label: 'Retards', color: CHART_VIOLET },
    { key: 'absent', label: 'Absents', color: CHART_BLUE },
];

const attendanceChart = computed(() => ({
    // Samedi is a half day. That no longer shortens the bars - a rate is a
    // rate - but the label keeps saying so, because it still explains why the
    // day rests on far fewer marks.
    labels: week.value.map((day) => (day.half_day ? `${day.day} ½` : day.day)),
    datasets: attendanceSeries.map((series) => ({
        label: series.label,
        data: week.value.map((day) => share(day[series.key], day.marked)),
        // Carried through so the tooltip can show the count the share came
        // from; Chart.js hands the whole dataset back on hover.
        counts: week.value.map((day) => day[series.key]),
        totals: week.value.map((day) => day.marked),
        backgroundColor: series.color,
        borderRadius: 5,
        maxBarThickness: 20,
    })),
}));

const attendanceOptions = {
    layout: { padding: { top: 4 } },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { padding: 6 },
        },
        y: {
            beginAtZero: true,
            // A share cannot pass 100, and pinning the axis there keeps the
            // six days on one scale instead of rescaling to the best day.
            max: 100,
            border: { display: false },
            grid: { color: '#eef0f5' },
            ticks: {
                stepSize: 25,
                padding: 8,
                callback: (value) => `${value} %`,
            },
        },
    },
    plugins: {
        tooltip: {
            callbacks: {
                // "Présents : 95 % (410/432)". The share is what is drawn, the
                // counts are what it was computed from.
                label: (context) => {
                    const marked = context.dataset.totals[context.dataIndex];
                    const count = context.dataset.counts[context.dataIndex];

                    return ` ${context.dataset.label} : ${context.parsed.y} % (${count}/${marked})`;
                },
            },
        },
    },
};

const attendanceLegend = attendanceSeries.map(({ label, color }) => ({ label, color }));

const attendanceSummary = computed(() =>
    week.value
        .map((day) => `${day.day} : ${share(day.present, day.marked)} % présents, `
            + `${share(day.late, day.marked)} % retards, `
            + `${share(day.absent, day.marked)} % absents, sur ${day.marked} relevés`)
        .join('. ') || 'Aucun relevé sur la semaine.');

/* ------------------------------------------------------------------------ */

function formatMoment(value) {
    if (!value) {
        return '';
    }

    const at = new Date(value);

    return at.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * "+2,4 pts" / "-1,1 pt". Percentage points, not percent: the two figures
 * being compared are already percentages, and calling the gap a percentage
 * would overstate it.
 */
function formatChange(value) {
    const size = Math.abs(value);

    return `${value > 0 ? '+' : '−'}${size.toFixed(1).replace('.', ',')} pt${size >= 2 ? 's' : ''}`;
}

/**
 * The five independent requests behind the page, each named by the part of
 * the dashboard it fills and each writing only its own state.
 *
 * @type {Array<{ label: string, run: () => Promise<void> }>}
 */
const widgets = [
    {
        label: 'les chiffres clés',
        run: async () => {
            // One request for every headline figure on the page.
            const { data } = await api.get('/dashboard/stats');

            counts.value = data.counts ?? {};
            context.value = data.context ?? {};
            markedToday.value = data.today.marked;
            todayRate.value = data.today.rate;
            previousDay.value = data.today.previous;
            rateChange.value = data.today.change;
            gender.value = data.gender;
        },
    },
    {
        label: 'les alertes',
        run: async () => {
            const { data } = await api.get('/attendances/at-risk', {
                params: { per_page: ALERTS_SHOWN },
            });
            atRisk.value = unwrapList(data);
            atRiskTotal.value = unwrapMeta(data).total;
        },
    },
    {
        label: "l'emploi du temps du jour",
        run: async () => {
            const { data } = await api.get('/dashboard/today-schedule');
            schedule.value = data.data ?? [];
            scheduleDay.value = data.day;
            isSchoolDay.value = data.is_school_day;
        },
    },
    {
        label: "l'activité récente",
        run: async () => {
            const { data } = await api.get('/dashboard/recent-activity');
            activity.value = data.data ?? [];
        },
    },
    {
        label: 'la présence de la semaine',
        run: async () => {
            const { data } = await api.get('/dashboard/attendance-week');
            week.value = data.data ?? [];
        },
    },
];

onMounted(async () => {
    // allSettled, not all: these five requests have nothing to do with each
    // other, and Promise.all rejecting on the first failure threw away the
    // four answers that had arrived. Now a widget that fails is named and the
    // rest of the page still renders.
    const results = await Promise.allSettled(widgets.map((widget) => widget.run()));

    const missing = widgets
        .filter((widget, index) => results[index].status === 'rejected')
        .map((widget) => widget.label);

    if (missing.length === widgets.length) {
        failure.value = 'Impossible de charger le tableau de bord.';
    } else if (missing.length) {
        failure.value = `Impossible de charger ${missing.join(', ')}. Le reste du tableau de bord est à jour.`;
    }

    loading.value = false;
});
</script>

<template>
    <div class="dash">
        <div class="page-header">
            <h1>Tableau de bord</h1>
        </div>

        <p v-if="failure" class="alert">{{ failure }}</p>

        <LoadingState v-if="loading" :rows="4" />

        <template v-else>
            <!--
                Two columns, as in the reference: everything that reads left to
                right stacks in the main column, and the calendar with today's
                timetable under it runs down a narrower rail beside it, level
                with the stat row at the top.
            -->
            <div class="dash-body">
                <div class="dash-main">
                    <div class="stat-grid">
                        <RouterLink
                            v-for="(tile, index) in tiles"
                            :key="tile.key"
                            :to="tile.to"
                            class="stat-card dash-card"
                            :class="index % 2 === 0 ? 'tone-violet' : 'tone-amber'"
                        >
                            <div class="stat-top">
                                <span class="stat-chip"><AppIcon :name="tile.icon" :size="18" /></span>
                                <span v-if="tile.badge" class="stat-badge">{{ tile.badge }}</span>
                            </div>
                            <div class="stat-value">{{ tile.value ?? '–' }}</div>
                            <div class="stat-label">{{ tile.label }}</div>
                        </RouterLink>

                        <RouterLink to="/attendance" class="stat-card dash-card tone-amber">
                            <div class="stat-top">
                                <span class="stat-chip"><AppIcon name="attendance" :size="18" /></span>
                                <!-- Shown only when both days carry a register; there is
                                     otherwise nothing honest to compare against. -->
                                <span
                                    v-if="rateChange !== null"
                                    class="stat-badge"
                                    :class="rateChange >= 0 ? 'badge-up' : 'badge-down'"
                                >
                                    {{ rateChange >= 0 ? '↑' : '↓' }} {{ formatChange(rateChange) }}
                                </span>
                            </div>
                            <div class="stat-value">{{ todayRate === null ? '–' : `${todayRate}%` }}</div>
                            <div class="stat-label">
                                Présence aujourd'hui
                                <span v-if="rateChange !== null" class="stat-note">
                                    vs {{ previousDay.day.toLowerCase() }}
                                </span>
                                <span v-else-if="todayRate !== null" class="stat-note">{{ markedToday }} relevés</span>
                                <span v-else class="stat-note">rien de saisi</span>
                            </div>
                        </RouterLink>
                    </div>

                    <nav class="quick-actions">
                        <RouterLink v-for="action in quickActions" :key="action.to" :to="action.to" class="btn">
                            {{ action.label }}
                        </RouterLink>
                    </nav>

                    <div class="charts-row">
                        <section class="card widget donut-widget">
                            <header class="widget-head">
                                <h2>Filles et garçons</h2>
                                <span class="widget-note">{{ genderTotal }} inscrits</span>
                            </header>

                            <EmptyState v-if="!genderSlices.length" title="Aucun élève inscrit." compact />

                            <template v-else>
                                <div class="donut">
                                    <ChartCanvas
                                        type="doughnut"
                                        :data="genderChart"
                                        :options="genderOptions"
                                        :summary="genderSummary"
                                    />
                                    <!--
                                        The reference puts a pair of figures in the
                                        middle of the ring rather than a total. The
                                        count is not lost: it is the "N inscrits" note
                                        in this card's own header.
                                    -->
                                    <div class="donut-centre" aria-hidden="true">
                                        <AppIcon name="students" :size="46" />
                                    </div>
                                </div>

                                <ul class="donut-legend">
                                    <li v-for="slice in genderSlices" :key="slice.label">
                                        <span class="legend-dot" :style="{ backgroundColor: slice.color }"></span>
                                        <span class="legend-label">{{ slice.label }}</span>
                                        <span class="legend-count">{{ slice.value }}</span>
                                        <span class="legend-share">{{ sharePercent(slice.value) }} %</span>
                                    </li>
                                </ul>
                            </template>
                        </section>

                        <section class="card widget week-widget">
                            <header class="widget-head">
                                <h2>Présences de la semaine</h2>
                                <span class="widget-note">Part des relevés · 6 derniers jours d'école</span>
                            </header>

                            <EmptyState
                                v-if="!weekHasMarks"
                                title="Aucun relevé sur la semaine."
                                hint="Les présences saisies apparaîtront ici."
                                compact
                            />

                            <template v-else>
                                <ul class="series-legend">
                                    <li v-for="series in attendanceLegend" :key="series.label">
                                        <span class="legend-dot" :style="{ backgroundColor: series.color }"></span>
                                        {{ series.label }}
                                    </li>
                                </ul>

                                <div class="bars">
                                    <ChartCanvas
                                        type="bar"
                                        :data="attendanceChart"
                                        :options="attendanceOptions"
                                        :summary="attendanceSummary"
                                    />
                                </div>
                            </template>
                        </section>
                    </div>

                    <div class="lower-row">
                        <section class="card widget alerts-widget">
                            <header class="widget-head">
                                <h2>Alertes</h2>
                                <span v-if="atRiskTotal" class="widget-note">{{ atRiskTotal }} élève(s)</span>
                            </header>

                            <p class="widget-hint">
                                Taux d'absence supérieur à {{ AT_RISK_RATE }} % sur les 30 derniers jours.
                            </p>

                            <EmptyState v-if="!alerts.length" title="Aucune alerte. " compact />

                            <ul v-else class="rows">
                                <li v-for="student in alerts" :key="student.id" class="row">
                                    <div class="row-main">
                                        <span class="row-title">{{ student.name }}</span>
                                        <span class="row-meta">{{ student.school_class ? student.school_class.name : 'sans classe' }}</span>
                                    </div>
                                    <div class="row-side">
                                        <span class="badge badge-bad">{{ student.absence_rate }} % d'absence</span>
                                        <span class="row-abs muted">{{ student.absence_count }}/{{ student.records_count }}</span>
                                        <RouterLink :to="`/students/${student.id}`" class="btn-link">Voir</RouterLink>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <section class="card widget activity-widget">
                            <header class="widget-head">
                                <h2>Activité récente</h2>
                            </header>

                            <EmptyState v-if="!activity.length" title="Aucune activité." compact />

                            <ul v-else class="rows scroll">
                                <li v-for="entry in activity" :key="`${entry.type}-${entry.id}`" class="row">
                                    <div class="row-main">
                                        <span class="row-title">{{ entry.label }}</span>
                                        <span class="row-meta">{{ formatMoment(entry.at) }}</span>
                                    </div>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>

                <!--
                    One card, two stacked sections: the month above, the day it is
                    pointing at below. They are the same subject, so the reference
                    does not split them across two boxes and neither does this.
                -->
                <aside class="card widget rail">
                    <MiniCalendar />

                    <section class="rail-agenda">
                        <header class="widget-head">
                            <h2>Emploi du temps aujourd'hui</h2>
                            <span v-if="isSchoolDay" class="widget-note">{{ scheduleDay }}</span>
                        </header>

                        <EmptyState
                            v-if="!isSchoolDay"
                            title="Pas de cours aujourd'hui : l'école est fermée le dimanche."
                            compact
                        />

                        <EmptyState v-else-if="!schedule.length" title="Pas de cours aujourd'hui." compact />

                        <ul v-else class="rows scroll">
                            <li v-for="slot in schedule" :key="slot.id" class="row">
                                <span class="row-time">{{ slot.start_time }}–{{ slot.end_time }}</span>
                                <div class="row-main">
                                    <span class="row-title">{{ slot.title }}</span>
                                    <span class="row-meta">
                                        {{ slot.teacher || 'sans enseignant' }} · {{ slot.school_class || 'sans classe' }}
                                        <template v-if="slot.room"> · {{ slot.room }}</template>
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* ---------------------------------------------------------------------------
   Dashboard-only palette.

   Scoped to .dash on purpose: the sidebar, the buttons and every other page
   stay on the app's single blue accent. Mirrors the JS constants at the top
   of this file, which the charts draw with.

   The tile fills are pastels, as in the reference, but pastels chosen to sit
   behind a large near-black figure: light enough that the number, the white
   icon chip and the white badge pill all stay legible on top of them.
--------------------------------------------------------------------------- */
.dash {
    --dash-violet: #6b46e5;
    /* The tile fill. A real pastel, as in the reference, rather than a tint
       so pale it reads as white - the colour is what separates one tile from
       the next along the row. */
    --dash-violet-soft: #ded8f8;
    --dash-violet-wash: #f2effd;
    --dash-violet-line: #cdc4f2;
    --dash-violet-ink: #402d96;

    --dash-amber: #e0921b;
    --dash-amber-soft: #fbe7a6;
    --dash-amber-wash: #fdf6e2;
    --dash-amber-line: #f4d78a;
    --dash-amber-ink: #7b4c07;

    /* Widget cards carry no border in the reference; a soft ambient shadow is
       the only thing lifting them off the canvas, so it has to be lighter
       than the app's general --shadow-md or the page reads heavy. */
    --dash-card-shadow: 0 1px 2px rgba(20, 30, 45, 0.04), 0 6px 20px rgba(20, 30, 45, 0.05);

    /* One gutter, used by every grid on the page, and the width of the rail
       the calendar and today's timetable share. */
    --dash-gutter: var(--space-6);
    --dash-rail: 340px;

    /* Mirrors CHART_BLUE in the script above. */
    --dash-chart-blue: #8ecfe8;
}

/* --- Stat tiles -------------------------------------------------------- */

.stat-grid {
    gap: var(--dash-gutter);
    grid-template-columns: repeat(auto-fill, minmax(196px, 1fr));
}

/* Flat fills, not gradients, and no border: in the reference the pastel block
   is the whole card, with nothing drawn around its edge. */
.dash-card {
    padding: var(--space-5) var(--space-5) var(--space-6);
    border-color: transparent;
    box-shadow: none;
}

.dash-card.tone-violet {
    background: var(--dash-violet-soft);
}

.dash-card.tone-amber {
    background: var(--dash-amber-soft);
}

/* The hairline the base .stat-card reveals on hover is the blue accent; each
   tile shows its own colour instead. */
.dash-card.tone-violet::after {
    background: var(--dash-violet);
}

.dash-card.tone-amber::after {
    background: var(--dash-amber);
}

/* Icon chip left, badge right - the reference's tile header. */
.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-6);
}

/* A white circle on the pastel, matching the white pill the badge opposite it
   sits in - the two corners of the tile head are cut from the same material. */
.stat-chip {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-pill);
    background: #ffffff;
}

.tone-violet .stat-chip {
    color: var(--dash-violet-ink);
}

.tone-amber .stat-chip {
    color: var(--dash-amber-ink);
}

/* Every badge on this page is a real derived figure - an occupancy rate, an
   average per class, a count of subjects, or a genuine day-on-day change. */
.stat-badge {
    padding: 4px 10px;
    font-size: 11px;
    font-weight: var(--weight-semibold);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    border-radius: var(--radius-pill);
    background: #ffffff;
}

.tone-violet .stat-badge {
    color: var(--dash-violet-ink);
}

.tone-amber .stat-badge {
    color: var(--dash-amber-ink);
}

.stat-badge.badge-up {
    color: var(--ok-ink);
    background: var(--ok-soft);
}

.stat-badge.badge-down {
    color: var(--danger-ink);
    background: var(--danger-soft);
}

/* Big, near-black and stacked straight above its label, as in the reference:
   the pastel is the background, the figure is the ink. */
.dash-card .stat-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.05;
    color: var(--gray-900);
}

.dash-card .stat-label {
    margin-top: var(--space-2);
    font-size: var(--text-base);
    font-weight: var(--weight-medium);
    color: var(--gray-700);
}

.stat-note {
    display: block;
    margin-top: 1px;
    font-size: var(--text-xs);
    font-weight: var(--weight-normal);
    color: var(--muted);
}

/* The one place the muted grey sits on a tint rather than on white or on the
   canvas. Even darkened it only reaches 3.9:1 against the violet, so the note
   takes the tile's own ink here, the way the chip and the badge already do. */
.tone-violet .stat-note {
    color: var(--dash-violet-ink);
}

.tone-amber .stat-note {
    color: var(--dash-amber-ink);
}

.quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin: 0;
}

.quick-actions .btn {
    text-decoration: none;
}

/* --- Layout -------------------------------------------------------------
   The reference's two-column skeleton: a wide main column, and a narrower
   rail beside it that starts level with the stat row and runs the height of
   the page. Every card keeps the same single gutter between it and whatever
   sits next to it, in either direction.
------------------------------------------------------------------------- */

.dash-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) var(--dash-rail);
    gap: var(--dash-gutter);
    align-items: start;
}

.dash-main {
    display: flex;
    flex-direction: column;
    gap: var(--dash-gutter);
    min-width: 0;
}

/* The donut is the narrower of the pair, as in the reference, where the ring
   sits beside a chart given room to spread its days out. */
.charts-row {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    gap: var(--dash-gutter);
    align-items: stretch;
}

.lower-row {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    gap: var(--dash-gutter);
    align-items: start;
}

/* Free-standing white cards: no border, one soft shadow, and the same 24px of
   padding inside as there is gutter between them. */
.widget {
    display: flex;
    flex-direction: column;
    position: relative;
    padding: var(--space-6);
    border-color: transparent;
    box-shadow: var(--dash-card-shadow);
    overflow: hidden;
}

/* --- The rail -----------------------------------------------------------
   Month above, that day's timetable below, inside one card: they are the same
   subject and the reference does not split them. The divider is a hairline,
   not a second card edge.
------------------------------------------------------------------------- */

.rail {
    /* The stat row above starts the main column, so the rail has to be told to
       climb past it to the top of the two-column band. */
    align-self: stretch;
    gap: var(--space-6);
}

.rail-agenda {
    display: flex;
    flex-direction: column;
    min-height: 0;
    padding-top: var(--space-6);
    border-top: 1px solid var(--border);
}

.rail-agenda .widget-head {
    flex-wrap: wrap;
}

/* Long days scroll inside the rail rather than stretching the column. */
.rail-agenda .rows.scroll {
    max-height: 340px;
}

.widget-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-5);
}

/* Every card title is the same dark 16px in the reference - the colour lives
   in the data, not in the headings. */
.widget-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: var(--weight-semibold);
    letter-spacing: -0.01em;
    color: var(--gray-900);
}

.widget-note {
    font-size: var(--text-xs);
    color: var(--muted);
}

.widget-hint {
    margin: calc(var(--space-4) * -1) 0 var(--space-4);
    font-size: var(--text-xs);
    color: var(--muted);
}

/* --- Donut --------------------------------------------------------------- */

.donut {
    position: relative;
    height: 188px;
    /* Real air between the ring and its legend, as in the reference, rather
       than a list that starts where the chart stops. */
    margin-bottom: var(--space-6);
}

/* The pair of figures the reference centres in the ring. Drawn in the same
   blue as the ring's first slice so the middle belongs to the chart rather
   than sitting on top of it. */
.donut-centre {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--dash-chart-blue);
    /* The ring underneath owns the hover targets. */
    pointer-events: none;
}

/* One row per slice: dot and name on the left, count and share right-aligned
   in their own columns so the figures line up down the list. */
.donut-legend {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: auto 0 0;
    padding: 0;
    list-style: none;
}

.donut-legend li {
    display: grid;
    grid-template-columns: 10px minmax(0, 1fr) auto 46px;
    align-items: center;
    gap: var(--space-3);
    font-size: var(--text-base);
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: var(--radius-pill);
}

.legend-label {
    color: var(--gray-600);
}

.legend-count {
    font-weight: var(--weight-semibold);
    font-variant-numeric: tabular-nums;
}

.legend-share {
    text-align: right;
    font-size: var(--text-sm);
    font-variant-numeric: tabular-nums;
    color: var(--muted);
}

/* --- Bars ---------------------------------------------------------------- */

.series-legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2) var(--space-5);
    margin: 0 0 var(--space-4);
    padding: 0;
    list-style: none;
}

.series-legend li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-sm);
    color: var(--gray-600);
}

.bars {
    flex: 1;
    min-height: 236px;
}

/* --- Lists --------------------------------------------------------------- */

.rows {
    margin: 0;
    padding: 0;
    list-style: none;
}

.rows.scroll {
    max-height: 300px;
    overflow-y: auto;
}

.row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-2);
    margin: 0 calc(var(--space-2) * -1);
    border-top: 1px solid var(--border);
    border-radius: var(--radius-sm);
    transition: background var(--transition-fast);
}

.row:hover {
    background: var(--surface-alt);
}

.row:first-child {
    border-top: none;
}

.row-side {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

/* The raw figures behind the rate, so the badge can be checked at a glance. */
.row-abs {
    font-size: var(--text-xs);
    font-variant-numeric: tabular-nums;
}

/* Below this the rail no longer has room to sit beside the content, so it
   drops under it and the whole page runs as one column. */
@media (max-width: 1180px) {
    .dash-body {
        grid-template-columns: minmax(0, 1fr);
    }

    .charts-row,
    .lower-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}

@media (max-width: 820px) {
    .charts-row,
    .lower-row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
