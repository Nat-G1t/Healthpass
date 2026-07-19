/**
 * Director Analytics charts (D-32 rescope, FR-ANL-09/11 + amended 04):
 *  - Clinic Visits by College — stacked horizontal bar, Medical/Dental
 *  - Visits per Month — two-series line, direct label on latest points
 *  - Students Screened by Sex — doughnut
 *
 * Dedicated Vite entry (same pattern as nurse/live-queue.js) so Chart.js is
 * only downloaded on this page, never in the app-wide bundle. Chart.js v4 is
 * tree-shakeable: registering only the pieces used keeps the build lean.
 * The purpose + BMI mini bars are server-rendered HTML — no JS needed.
 *
 * Legends are server-rendered beside each chart (they carry counts the
 * built-in legend can't show), so `plugins.legend` stays off everywhere.
 * Direct labels draw in slate ink, not the series color — identity comes
 * from the mark, text stays readable.
 */
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement, BarController, BarElement, CategoryScale, DoughnutController,
    LinearScale, LineController, LineElement, PointElement, Tooltip,
);

Chart.defaults.font.family = "'Poppins', sans-serif";

const INK = '#4B5563';
const GRID = 'rgba(75, 85, 99, .12)';

/** Parse the JSON payload the controller ships on a data attribute. */
const chartData = (host) => JSON.parse(host.dataset.chart);

// ── Clinic Visits by College — stacked horizontal bar (FR-ANL-09) ────────
const collegeHost = document.querySelector('[data-college-bar]');

if (collegeHost) {
    // Draws each row's total just past the end of its stacked bar — the
    // mockup's right-hand value column, done as a Chart.js inline plugin.
    const rowTotals = {
        id: 'rowTotals',
        afterDatasetsDraw(chart) {
            const { ctx, data } = chart;
            const lastMeta = chart.getDatasetMeta(data.datasets.length - 1);

            ctx.save();
            ctx.font = "600 11px 'Poppins', sans-serif";
            ctx.fillStyle = INK;
            ctx.textBaseline = 'middle';

            lastMeta.data.forEach((bar, i) => {
                const total = data.datasets.reduce((sum, ds) => sum + (ds.data[i] ?? 0), 0);
                ctx.fillText(String(total), bar.x + 6, bar.y);
            });
            ctx.restore();
        },
    };

    new Chart(collegeHost.querySelector('canvas'), {
        type: 'bar',
        data: chartData(collegeHost),
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 34 } }, // room for the row totals
            datasets: {
                bar: {
                    // Thin marks with a hairline surface gap between the
                    // Medical and Dental segments of each stack.
                    barThickness: 16,
                    borderColor: '#FFFFFF',
                    borderWidth: 1,
                    borderRadius: 3,
                },
            },
            scales: {
                // Values live at the row ends + in the table — no x axis.
                x: { stacked: true, display: false },
                y: {
                    stacked: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: INK, font: { size: 11, weight: 600 } },
                },
            },
            plugins: { legend: { display: false } },
        },
        plugins: [rowTotals],
    });
}

// ── Visits per Month — two-series line (FR-ANL-11) ───────────────────────
const trendHost = document.querySelector('[data-trend]');

if (trendHost) {
    // Direct label on the LATEST point of each line (selective labeling —
    // never a number on every point). Nudges the second label clear when
    // the two lines end close together.
    const latestPointLabels = {
        id: 'latestPointLabels',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            ctx.save();
            ctx.font = "700 11px 'Poppins', sans-serif";
            ctx.fillStyle = INK;
            ctx.textAlign = 'center';

            const placed = [];
            chart.data.datasets.forEach((dataset, i) => {
                const points = chart.getDatasetMeta(i).data;
                const last = points[points.length - 1];
                if (!last) return;

                let y = last.y - 8;
                if (placed.some((prev) => Math.abs(prev - y) < 12)) {
                    y = last.y + 16; // collision → flip below the point
                }
                placed.push(y);

                ctx.fillText(String(dataset.data[dataset.data.length - 1]), last.x, y);
            });
            ctx.restore();
        },
    };

    const trendData = chartData(trendHost);

    new Chart(trendHost.querySelector('canvas'), {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: trendData.datasets.map((dataset) => ({
                ...dataset,
                borderWidth: 2,           // FR-ANL-11: 2px lines
                pointRadius: 3,
                pointHoverRadius: 5,
                pointHitRadius: 10,       // hit target bigger than the mark
                pointBackgroundColor: dataset.borderColor,
                pointBorderColor: '#FFFFFF',
                pointBorderWidth: 1.5,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 16, right: 20 } }, // room for the labels
            interaction: { mode: 'index', intersect: false }, // crosshair-style tooltip
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: 'rgba(75, 85, 99, .5)', font: { size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: GRID },
                    border: { display: false },
                    ticks: { color: 'rgba(75, 85, 99, .5)', font: { size: 10 }, maxTicksLimit: 5 },
                },
            },
            plugins: { legend: { display: false } },
        },
        plugins: [latestPointLabels],
    });
}

// ── Students Screened by Sex — doughnut (FR-ANL-04) ──────────────────────
const donutHost = document.querySelector('[data-by-sex]');

if (donutHost) {
    const donutData = chartData(donutHost);

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
