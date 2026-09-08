<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Chart from '../lib/charts';

/**
 * A Chart.js canvas with a Vue lifecycle around it.
 *
 * Charts are the one place in this app that owns a DOM node Vue does not
 * render into, so the create/update/destroy dance lives here once rather than
 * in every page that wants a chart.
 */
const props = defineProps({
    type: { type: String, required: true },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    /**
     * What the chart says, for anyone who cannot see it. A canvas is opaque to
     * a screen reader, so every chart has to state its own summary; the
     * figures are also written out as text beside each chart on the page.
     */
    summary: { type: String, required: true },
});

const canvas = ref(null);
let chart = null;

function build() {
    if (!canvas.value) {
        return;
    }

    chart = new Chart(canvas.value, {
        type: props.type,
        data: props.data,
        options: props.options,
    });
}

onMounted(build);

// Replacing the arrays in place would leave Chart.js animating from stale
// values, so the whole data object is handed over and the chart re-reads it.
watch(
    () => [props.data, props.options],
    () => {
        if (!chart) {
            build();

            return;
        }

        chart.data = props.data;
        chart.options = props.options;
        chart.update();
    },
    { deep: true },
);

onBeforeUnmount(() => {
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="chart-canvas">
        <canvas ref="canvas" role="img" :aria-label="summary"></canvas>
    </div>
</template>

<style scoped>
.chart-canvas {
    position: relative;
    width: 100%;
    height: 100%;
}
</style>
