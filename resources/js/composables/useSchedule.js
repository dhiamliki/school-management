/**
 * The school week, in teaching order. Sunday is not a school day.
 */
export const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

export function toHm(value) {
    return value ? value.slice(0, 5) : '';
}

/**
 * Flatten the lesson/timetable nesting the show endpoints return into a plain
 * list of slots.
 *
 * The API answers with lessons that each carry their own slots, because that
 * is how the relations hang together. Every profile page wants the opposite
 * shape - one row per slot, carrying its lesson - so the flattening lives
 * here rather than being repeated three times.
 */
export function slotsFromLessons(lessons) {
    const slots = [];

    for (const lesson of lessons ?? []) {
        for (const slot of lesson.timetables ?? []) {
            slots.push({
                id: slot.id,
                day: slot.day_of_week,
                start: toHm(slot.start_time),
                end: toHm(slot.end_time),
                room: slot.room,
                title: lesson.title,
                subject: lesson.subject,
                teacher: lesson.teacher ? lesson.teacher.name : null,
                schoolClass: lesson.school_class ? lesson.school_class.name : null,
            });
        }
    }

    return sortSlots(slots);
}

/**
 * Chronological across the week: by teaching day, then by start time.
 */
export function sortSlots(slots) {
    return [...slots].sort(
        (a, b) => DAYS.indexOf(a.day) - DAYS.indexOf(b.day) || a.start.localeCompare(b.start),
    );
}

/**
 * Group slots by day, keeping the teaching-day order and dropping days with
 * nothing on them - a half day should not render four empty rows.
 */
export function groupByDay(slots) {
    return DAYS.map((day) => ({ day, slots: slots.filter((slot) => slot.day === day) })).filter(
        (group) => group.slots.length > 0,
    );
}
