// Only the Chart.js pieces the dashboard draws with, so the rest stays out of
// the build.
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    LinearScale,
    Tooltip,
);

// Do not set a legend default here. Legend is not registered above, so
// Chart.defaults.plugins.legend does not exist and writing to it throws while
// this module evaluates, taking the whole app down. Legends are plain HTML.
Chart.defaults.font.family =
    "'Switzer', 'Segoe UI', system-ui, -apple-system, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#78829a';
Chart.defaults.maintainAspectRatio = false;

export default Chart;
