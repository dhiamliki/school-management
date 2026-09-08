<script setup>
import { useRoute } from 'vue-router';
import AppIcon from '../components/AppIcon.vue';

/**
 * Shown for any path the router does not recognise.
 *
 * The server hands every non-API path to the SPA, so a mistyped or stale URL
 * reaches the router rather than a 404 page. Without this route it matched
 * nothing and rendered the sidebar around an empty column, which reads as the
 * app having broken rather than as the address being wrong.
 */
const route = useRoute();
</script>

<template>
    <div class="page-header">
        <h1>Page introuvable</h1>
    </div>

    <div class="card not-found">
        <span class="not-found-icon"><AppIcon name="error" :size="22" /></span>
        <p class="not-found-title">Cette page n'existe pas.</p>
        <p class="not-found-path">{{ route.fullPath }}</p>
        <p class="not-found-hint">
            Le lien est peut-être ancien, ou l'adresse comporte une faute de frappe.
        </p>
        <RouterLink to="/dashboard" class="btn btn-primary">Retour au tableau de bord</RouterLink>
    </div>
</template>

<style scoped>
.not-found {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-8) var(--space-6);
    text-align: center;
}

.not-found-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    color: var(--muted);
    background: var(--canvas);
    border-radius: var(--radius-pill);
}

.not-found-title {
    margin: 0;
    font-size: var(--text-md);
    font-weight: var(--weight-medium);
}

/* The address is quoted back so it is obvious which link was followed. */
.not-found-path {
    margin: 0;
    padding: var(--space-1) var(--space-3);
    font-size: var(--text-sm);
    color: var(--muted);
    background: var(--canvas);
    border-radius: var(--radius-sm);
    word-break: break-all;
}

.not-found-hint {
    margin: 0 0 var(--space-2);
    font-size: var(--text-sm);
    color: var(--muted);
}
</style>
