<script setup>
import AppIcon from './AppIcon.vue';

/**
 * The one way the app says "there is nothing here".
 *
 * Every list and panel had rolled its own line of grey text, in three
 * different shapes. This keeps the wording where it was written - each caller
 * still passes its own French copy - and only makes the presentation shared.
 */
defineProps({
    // The sentence to show, e.g. "Aucun élève."
    title: { type: String, required: true },
    // Optional second line explaining what to do about it.
    hint: { type: String, default: '' },
    icon: { type: String, default: 'inbox' },
    // Drops the icon and the padding, for use inside a panel or a table cell.
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="empty-state" :class="{ compact }">
        <span v-if="!compact" class="empty-state-icon">
            <AppIcon :name="icon" :size="22" />
        </span>
        <p class="empty-state-title">{{ title }}</p>
        <p v-if="hint" class="empty-state-hint">{{ hint }}</p>
        <slot />
    </div>
</template>
