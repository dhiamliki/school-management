<script setup>
defineProps({
    title: { type: String, required: true },
    saving: { type: Boolean, default: false },
});

defineEmits(['close', 'submit']);
</script>

<template>
    <div class="modal-backdrop" @click.self="$emit('close')">
        <div class="modal">
            <h2>{{ title }}</h2>
            <form @submit.prevent="$emit('submit')">
                <slot />
                <div class="modal-actions">
                    <button type="button" class="btn" @click="$emit('close')">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="saving">
                        {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
