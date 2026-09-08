<script setup>
import { computed } from 'vue';

/**
 * Inline SVG icons, drawn on a 24x24 grid with a 1.75 stroke and round caps
 * so they read as one set.
 *
 * Kept as a local map rather than an icon library: the app needs a dozen
 * glyphs, and a dependency for that would cost more than it saves. Icons
 * inherit currentColor, so they follow whatever text colour they sit in.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
});

const PATHS = {
    // Navigation
    dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    classes: '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M10 21v-5h4v5"/>',
    students: '<path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/><path d="M20 20v-1.5a3.5 3.5 0 0 0-2.6-3.4"/><path d="M15.5 4.7a3.5 3.5 0 0 1 0 6.6"/>',
    teachers: '<path d="M4 4h16v11H4z"/><path d="M12 15v3"/><path d="M8 21h8"/><path d="M8.5 10.5 11 8l2 2.5L15.5 6"/>',
    lessons: '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H19v14H6.5A2.5 2.5 0 0 0 4 19.5z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H19v4H6.5A2.5 2.5 0 0 1 4 19.5z"/>',
    timetable: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M8 14h3"/><path d="M8 17.5h6"/>',
    attendance: '<rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 3h6v3H9z"/><path d="M8.5 12.5 11 15l4.5-4.5"/>',
    chatbot: '<path d="M21 12a8 8 0 0 1-8 8H4l1.6-3.2A8 8 0 1 1 21 12z"/><path d="M9 11h.01"/><path d="M12.5 11h.01"/><path d="M16 11h.01"/>',

    // Actions and feedback
    plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
    edit: '<path d="M4 20h4l10-10a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13.5 6.5 17.5 10.5"/>',
    trash: '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6"/><path d="M14 11v6"/>',
    eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    back: '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
    warning: '<path d="M12 3.5 22 20H2z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
    error: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5"/><path d="M12 16h.01"/>',
    check: '<path d="m5 12.5 5 5L19 7"/>',
    inbox: '<path d="M3 12h5l1.5 3h5L16 12h5"/><path d="M5.5 5h13l2.5 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.6-3.6"/>',
};

const markup = computed(() => PATHS[props.name] ?? '');
</script>

<template>
    <svg
        class="icon"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        v-html="markup"
    />
</template>
