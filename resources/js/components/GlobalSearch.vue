<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/api';
import AppIcon from './AppIcon.vue';

/**
 * The header search: a jump list across pupils, teachers and classes.
 *
 * Backed by a real endpoint rather than being decorative - a search box that
 * does nothing is worse than no search box, because it invites the one thing
 * it cannot do.
 */
const router = useRouter();

const term = ref('');
const results = ref([]);
const open = ref(false);
const searching = ref(false);
const failed = ref(false);
const root = ref(null);

// Only the newest request may write its results: a slower earlier one must not
// overwrite the answer for what has since been typed.
let token = 0;
let timer = null;

const tooShort = computed(() => term.value.trim().length < 2);

async function run() {
    const query = term.value.trim();

    if (query.length < 2) {
        results.value = [];
        searching.value = false;
        failed.value = false;

        return;
    }

    const mine = ++token;
    searching.value = true;

    try {
        const { data } = await api.get('/search', { params: { q: query } });

        if (mine === token) {
            results.value = data.data ?? [];
            failed.value = false;
        }
    } catch (error) {
        // A failed lookup is not worth interrupting the page for, but it must
        // not be reported as an empty result either: "aucun résultat" about a
        // pupil who does exist is worse than saying the search itself failed.
        if (mine === token) {
            results.value = [];
            failed.value = true;
        }
    } finally {
        if (mine === token) {
            searching.value = false;
        }
    }
}

watch(term, () => {
    open.value = true;
    clearTimeout(timer);
    timer = setTimeout(run, 250);
});

function go(hit) {
    term.value = '';
    results.value = [];
    open.value = false;
    router.push(hit.to);
}

function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    clearTimeout(timer);
});
</script>

<template>
    <div ref="root" class="topbar-search" role="search">
        <AppIcon name="search" :size="16" />
        <input
            v-model="term"
            type="search"
            placeholder="Rechercher un élève, un enseignant, une classe…"
            aria-label="Rechercher"
            @focus="open = true"
            @keydown.escape="open = false"
        >

        <ul v-if="open && !tooShort" class="search-results">
            <li v-if="searching && !results.length" class="search-empty">Recherche…</li>
            <li v-else-if="failed" class="search-empty search-failed">
                La recherche a échoué. Réessayez.
            </li>
            <li v-else-if="!results.length" class="search-empty">Aucun résultat.</li>

            <li v-for="hit in results" :key="`${hit.type}-${hit.id}`">
                <a :href="hit.to" @click.prevent="go(hit)">
                    <span class="search-hit-main">
                        <span class="search-hit-label">{{ hit.label }}</span>
                        <span class="search-hit-meta">{{ hit.meta }}</span>
                    </span>
                    <span class="search-hit-kind">{{ hit.kind }}</span>
                </a>
            </li>
        </ul>
    </div>
</template>
