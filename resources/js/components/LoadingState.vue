<script setup>
/**
 * The one way the app says "this is still loading".
 *
 * Ten pages each rendered their own "Chargement…" line. Two shapes replace
 * them: a skeleton that stands in for the rows about to arrive, and an inline
 * spinner for the places where a block would be too heavy.
 */
defineProps({
    // 'skeleton' mimics a table or list; 'inline' is a spinner plus a label.
    variant: { type: String, default: 'skeleton' },
    rows: { type: Number, default: 5 },
    label: { type: String, default: 'Chargement…' },
});

/**
 * Bar widths that vary a little down the list, so the placeholder reads as
 * content rather than as a striped block.
 */
const WIDTHS = [
    ['38%', '26%', '18%'],
    ['30%', '32%', '14%'],
    ['42%', '22%', '20%'],
    ['28%', '30%', '16%'],
    ['36%', '24%', '22%'],
];

function widthsFor(index) {
    return WIDTHS[index % WIDTHS.length];
}
</script>

<template>
    <div v-if="variant === 'inline'" class="loading-inline" role="status" :aria-label="label">
        <span class="spinner"></span>
        <span>{{ label }}</span>
    </div>

    <div v-else class="skeleton-rows" role="status" :aria-label="label">
        <div v-for="row in rows" :key="row" class="skeleton-row">
            <span
                v-for="(width, column) in widthsFor(row - 1)"
                :key="column"
                class="skeleton skeleton-bar"
                :style="{ width }"
            ></span>
        </div>
    </div>
</template>
