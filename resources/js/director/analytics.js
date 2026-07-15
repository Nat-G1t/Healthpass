/**
 * Director Analytics — Medical Cases by College (FR-ANL-02) and the
 * By-Sex donut (FR-ANL-04).
 *
 * Dedicated Vite entry (same pattern as nurse/live-queue.js) so Chart.js is
 * only downloaded on this page, never in the app-wide bundle. Chart.js v4 is
 * tree-shakeable: registering only the bar + doughnut pieces keeps the
 * build lean.
 */
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
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
    Legend,
    Tooltip,
);

Chart.defaults.font.family = "'Poppins', sans-serif";

const SLATE = '#4B5563';
const GRID_GRAY = '#F3F4F6';

const host = document.querySelector('[data-cases-by-college]');

if (host) {
    // The controller ships labels + datasets as JSON on the container;
    // Blade's {{ }} escaping is undone by the browser when reading dataset.
    const chartData = JSON.parse(host.dataset.chart);

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

// ── Cases by Medical System (FR-ANL-08) ─────────────────────────────────────

const systemHost = document.querySelector('[data-cases-by-system]');

if (systemHost) {
    const chartData = JSON.parse(systemHost.dataset.chart);

    // Long system names ("Eyes, Ears, Nose & Throat Disorders") wrap into
    // short tick lines instead of being clipped by the y-axis.
    const wrapLabel = (label) => {
        const lines = [];
        let line = '';

        label.split(' ').forEach((word) => {
            if (line && `${line} ${word}`.length > 14) {
                lines.push(line);
                line = word;
            } else {
                line = line ? `${line} ${word}` : word;
            }
        });

        return [...lines, line];
    };

    // Draws each bar's total just past its end (prototype detail). With a
    // Male + Female segment per bar, neither segment shows the system
    // total on its own — this label does. The last dataset's bar element
    // ends at the full stack width, so its x is where the text goes.
    const barTotals = {
        id: 'barTotals',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            const meta = chart.getDatasetMeta(chart.data.datasets.length - 1);

            ctx.save();
            ctx.font = "600 12px 'Poppins', sans-serif";
            ctx.fillStyle = SLATE;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            chartData.totals.forEach((total, i) => {
                const bar = meta.data[i];
                if (bar) ctx.fillText(total, bar.x + 8, bar.y);
            });
            ctx.restore();
        },
    };

    new Chart(systemHost.querySelector('canvas'), {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: chartData.datasets.map((dataset) => ({
                ...dataset,
                // Same 2px surface gap between the M/F segments as the
                // stacked college chart uses.
                borderColor: '#FFFFFF',
                borderWidth: 2,
                borderSkipped: false,
                barThickness: 20,
            })),
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                // No x-axis in the prototype — the end-of-bar totals carry
                // the numbers. grace leaves room for those labels.
                x: { stacked: true, display: false, grace: '15%' },
                y: {
                    stacked: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: SLATE,
                        font: { size: 12, weight: 600 },
                        callback(value, index) {
                            return wrapLabel(this.getLabelForValue(index));
                        },
                    },
                },
            },
            plugins: {
                // No legend: the y labels name the systems, and the card's
                // subtitle explains strong shade = Male, lighter = Female.
                legend: { display: false },
                tooltip: {
                    // Hide the zero segment a stacked bar always carries.
                    filter: (item) => item.parsed.x !== 0,
                },
            },
        },
        plugins: [barTotals],
    });
}

// ── By-Sex donut (FR-ANL-04) ─────────────────────────────────────────────────

const donutHost = document.querySelector('[data-by-sex]');

if (donutHost) {
    const donutData = JSON.parse(donutHost.dataset.chart);

    new Chart(donutHost.querySelector('canvas'), {
        type: 'doughnut',
        data: {
            labels: donutData.labels,
            datasets: donutData.datasets.map((dataset) => ({
                ...dataset,
                // Same 2px surface gap as the stacked bar's segments.
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
