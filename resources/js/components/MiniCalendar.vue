<script setup>
import { computed, ref } from 'vue';

/**
 * A read-only month view.
 *
 * Deliberately not interactive: it is here to give the day its context - what
 * week we are in, how far off the end of the month is - next to today's
 * timetable. Anything clickable would promise a day view this app does not
 * have.
 */
const props = defineProps({
    // Injectable so the component can be reasoned about on a fixed date
    // rather than only on whatever day it happens to be rendered.
    today: { type: Date, default: () => new Date() },
});

// Lundi first: the school week runs Lundi to Samedi, so a Sunday-first grid
// would split it across two rows.
const WEEKDAY_INITIALS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

const cursor = ref(startOfMonth(props.today));

function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear()
        && a.getMonth() === b.getMonth()
        && a.getDate() === b.getDate();
}

const monthLabel = computed(() =>
    cursor.value
        .toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
        .replace(/^./, (c) => c.toUpperCase()));

/**
 * The six-week grid the month sits in, padded with the surrounding days so
 * every row is full and the calendar never changes height month to month.
 */
const weeks = computed(() => {
    const first = cursor.value;
    // getDay() is Sunday-based; shift it so Lundi is column zero.
    const lead = (first.getDay() + 6) % 7;
    const start = new Date(first.getFullYear(), first.getMonth(), 1 - lead);

    const rows = [];

    for (let week = 0; week < 6; week++) {
        const days = [];

        for (let day = 0; day < 7; day++) {
            const date = new Date(
                start.getFullYear(),
                start.getMonth(),
                start.getDate() + week * 7 + day,
            );

            days.push({
                key: date.toISOString().slice(0, 10),
                label: date.getDate(),
                outside: date.getMonth() !== first.getMonth(),
                isToday: sameDay(date, props.today),
                // The school is shut on Sunday, so those columns are greyed
                // rather than left looking like ordinary teaching days.
                closed: date.getDay() === 0,
            });
        }

        rows.push({ key: days[0].key, days });
    }

    return rows;
});

function shiftMonth(offset) {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + offset, 1);
}

const onCurrentMonth = computed(() =>
    cursor.value.getFullYear() === props.today.getFullYear()
    && cursor.value.getMonth() === props.today.getMonth());
</script>

<template>
    <div class="calendar">
        <header class="calendar-head">
            <button type="button" class="calendar-step" aria-label="Mois précédent" @click="shiftMonth(-1)">‹</button>
            <span class="calendar-month">{{ monthLabel }}</span>
            <button type="button" class="calendar-step" aria-label="Mois suivant" @click="shiftMonth(1)">›</button>
        </header>

        <button
            v-if="!onCurrentMonth"
            type="button"
            class="calendar-return"
            @click="cursor = startOfMonth(props.today)"
        >
            Revenir à aujourd'hui
        </button>

        <table class="calendar-grid">
            <thead>
                <tr>
                    <th v-for="(initial, index) in WEEKDAY_INITIALS" :key="index" scope="col">
                        {{ initial }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="week in weeks" :key="week.key">
                    <td
                        v-for="day in week.days"
                        :key="day.key"
                        :class="{ outside: day.outside, closed: day.closed, today: day.isToday }"
                        :aria-current="day.isToday ? 'date' : undefined"
                    >
                        <span>{{ day.label }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.calendar {
    min-width: 0;
}

.calendar-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
}

/* Centred between the two arrows and set at the card-title size, as in the
   reference, rather than tucked in at the left. */
.calendar-month {
    flex: 1;
    text-align: center;
    font-size: 16px;
    font-weight: var(--weight-semibold);
    letter-spacing: -0.01em;
    color: var(--gray-900);
}

.calendar-step {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    font: inherit;
    font-size: var(--text-md);
    line-height: 1;
    color: var(--gray-600);
    background: var(--gray-100);
    border: none;
    border-radius: var(--radius-pill);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
}

.calendar-step:hover {
    color: var(--gray-900);
    background: var(--gray-200);
}

.calendar-return {
    display: block;
    width: 100%;
    margin-bottom: var(--space-2);
    padding: var(--space-1);
    font: inherit;
    font-size: var(--text-xs);
    color: var(--dash-violet-ink);
    background: none;
    border: none;
    cursor: pointer;
}

.calendar-return:hover {
    text-decoration: underline;
}

.calendar-grid {
    width: 100%;
    border: none;
    border-radius: 0;
    border-collapse: collapse;
    table-layout: fixed;
}

.calendar-grid th,
.calendar-grid td {
    padding: 0;
    border: none;
    background: none;
    text-align: center;
}

.calendar-grid th {
    padding-bottom: var(--space-2);
    font-size: 11px;
    font-weight: var(--weight-medium);
    letter-spacing: 0.06em;
    color: var(--muted);
    text-transform: uppercase;
}

.calendar-grid td span {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin: 2px auto;
    font-size: var(--text-base);
    font-variant-numeric: tabular-nums;
    color: var(--text);
    border-radius: 10px;
}

.calendar-grid td.outside span {
    color: var(--gray-300);
}

/* Sunday: the school is shut. */
.calendar-grid td.closed span {
    color: var(--gray-400);
}

.calendar-grid td.outside.closed span {
    color: var(--gray-200);
}

/* Today is a soft rounded-square chip carrying dark ink, the way the
   reference marks the current day - not a solid dot in reverse. */
.calendar-grid td.today span {
    color: var(--dash-violet-ink);
    font-weight: var(--weight-semibold);
    background: var(--dash-violet-wash);
}
</style>
