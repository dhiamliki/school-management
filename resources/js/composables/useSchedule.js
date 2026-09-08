/**
 * The school week, in teaching order. Sunday is not a school day.
 */
export const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

export function toHm(value) {
    return value ? value.slice(0, 5) : '';
}

/**
 * The API nests slots under lessons; every profile page wants the opposite,
 * one row per slot carrying its lesson.
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

export function sortSlots(slots) {
    return [...slots].sort(
        (a, b) => DAYS.indexOf(a.day) - DAYS.indexOf(b.day) || a.start.localeCompare(b.start),
    );
}

// Drops empty days, so a half day does not render four blank rows.
export function groupByDay(slots) {
    return DAYS.map((day) => ({ day, slots: slots.filter((slot) => slot.day === day) })).filter(
        (group) => group.slots.length > 0,
    );
}
