<script setup>
import { computed } from 'vue';

const props = defineProps({
    // The pagination block exposed by useResource.
    meta: { type: Object, required: true },
    // Plural noun for the caption, e.g. "élèves".
    label: { type: String, required: true },
    // True while a page is in flight, so the buttons cannot stack requests.
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const total = computed(() => props.meta.total ?? 0);
const currentPage = computed(() => props.meta.current_page ?? 1);
const lastPage = computed(() => props.meta.last_page ?? 1);

// Laravel sends from/to; fall back to the row count for a flat response.
const from = computed(() => props.meta.from ?? 0);
const to = computed(() => props.meta.to ?? 0);

const hasPages = computed(() => lastPage.value > 1);
const canGoBack = computed(() => currentPage.value > 1 && !props.loading);
const canGoForward = computed(() => currentPage.value < lastPage.value && !props.loading);

function go(page) {
    emit('change', page);
}
</script>

<template>
    <div v-if="total" class="pagination">
        <p class="pagination-caption muted">
            Affichage de {{ from }}–{{ to }} sur {{ total }} {{ label }}
        </p>

        <div v-if="hasPages" class="pagination-actions">
            <button type="button" class="btn" :disabled="!canGoBack" @click="go(currentPage - 1)">
                Précédent
            </button>
            <span class="pagination-position">Page {{ currentPage }} sur {{ lastPage }}</span>
            <button type="button" class="btn" :disabled="!canGoForward" @click="go(currentPage + 1)">
                Suivant
            </button>
        </div>
    </div>
</template>

<style scoped>
.pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3) var(--space-4);
    margin-top: var(--space-4);
}

.pagination-caption {
    margin: 0;
    font-size: var(--text-sm);
}

.pagination-actions {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.pagination-position {
    font-size: var(--text-sm);
    color: var(--muted);
}
</style>
