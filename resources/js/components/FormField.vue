<script setup>
defineProps({
    label: { type: String, required: true },
    error: { type: Array, default: null },
});
</script>

<template>
    <label class="field">
        <span class="field-label">{{ label }}</span>
        <slot />
        <span v-if="error" class="field-error">{{ error[0] }}</span>
    </label>
</template>
