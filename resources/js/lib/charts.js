/**
 * The Chart.js pieces this app actually draws with.
 *
 * Chart.js ships a bundle that registers every controller, scale and plugin it
 * has. Registering only what the dashboard uses keeps the rest out of the
 * build - and makes it obvious that adding a new kind of chart is a deliberate
 * act rather than something that happens by accident.
 */
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

// No legend default is set here, and none can be: the Legend plugin is not in
// the register() call above, so Chart.defaults.plugins.legend does not exist
// and writing to it throws while this module is still evaluating - which takes
// the whole app down with it, not just the charts. Legends on this dashboard
// are plain HTML beside each chart, so they stay selectable text and inherit
// the app's type.
Chart.defaults.font.family =
    "'Switzer', 'Segoe UI', system-ui, -apple-system, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#78829a';
Chart.defaults.maintainAspectRatio = false;

export default Chart;
