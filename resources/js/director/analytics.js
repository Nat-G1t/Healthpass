/**
 * Director Analytics — Medical Cases by College (FR-ANL-02).
 *
 * Dedicated Vite entry (same pattern as nurse/live-queue.js) so Chart.js is
 * only downloaded on this page, never in the app-wide bundle. Chart.js v4 is
 * tree-shakeable: registering only the bar-chart pieces keeps the build lean.
 */
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);

const SLATE = '#4B5563';
const GRID_GRAY = '#F3F4F6';

const host = document.querySelector('[data-cases-by-college]');

if (host) {
    // The controller ships labels + datasets as JSON on the container;
    // Blade's {{ }} escaping is undone by the browser when reading dataset.
    const chartData = JSON.parse(host.dataset.chart);

    Chart.defaults.font.family = "'Poppins', sans-serif";

    new Chart(host.querySelector('canvas'), {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: chartData.datasets.map((dataset) => ({
                ...dataset,
                // 2px surface gap between stacked segments — the identity
                // fallback when two segments end up side by side.
                borderColor: '#FFFFFF',
                borderWidth: 2,
                borderSkipped: false,
                barThickness: 20,
            })),
        },
        options: {
            indexAxis: 'y', // horizontal bars: one row per college
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: true,
                    grid: { color: GRID_GRAY },
                    border: { display: false },
                    ticks: { color: SLATE, precision: 0, font: { size: 11 } },
                },
                y: {
                    stacked: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: SLATE, font: { size: 12, weight: 600 } },
                },
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'rectRounded',
                        boxWidth: 10,
                        boxHeight: 10,
                        color: SLATE,
                        padding: 14,
                        font: { size: 11 },
                    },
                },
                tooltip: {
                    // Hide the zero segments a stacked bar always carries.
                    filter: (item) => item.parsed.x !== 0,
                },
            },
        },
    });
}
