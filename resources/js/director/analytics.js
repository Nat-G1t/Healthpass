/**
 * Director Analytics — the By-Sex donut (FR-ANL-04). The medical-cases
 * charts were removed by D-32; the rescoped captured-data charts arrive
 * in the rebuild phase.
 *
 * Dedicated Vite entry (same pattern as nurse/live-queue.js) so Chart.js is
 * only downloaded on this page, never in the app-wide bundle. Chart.js v4 is
 * tree-shakeable: registering only the doughnut pieces keeps the build lean.
 */
import { ArcElement, Chart, DoughnutController, Tooltip } from 'chart.js';

Chart.register(ArcElement, DoughnutController, Tooltip);

Chart.defaults.font.family = "'Poppins', sans-serif";

const donutHost = document.querySelector('[data-by-sex]');

if (donutHost) {
    // The controller ships labels + datasets as JSON on the container;
    // Blade's {{ }} escaping is undone by the browser when reading dataset.
    const donutData = JSON.parse(donutHost.dataset.chart);

    new Chart(donutHost.querySelector('canvas'), {
        type: 'doughnut',
        data: {
            labels: donutData.labels,
            datasets: donutData.datasets.map((dataset) => ({
                ...dataset,
                // 2px surface gap between the slices.
                borderColor: '#FFFFFF',
                borderWidth: 2,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // Hole wide enough for the Blade-rendered center total overlay.
            cutout: '70%',
            plugins: {
                // Legend is server-rendered HTML beside the chart — it needs
                // count + percentage per slice (FR-ANL-04), which the built-in
                // legend can't show.
                legend: { display: false },
            },
        },
    });
}
