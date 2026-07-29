/*
 * Shared Chart.js setup for the CRM.
 *
 * Pairs with the vendored Chart.js v4.5.1 UMD build at public/js/chart.umd.min.js
 * (MIT). Vendored rather than installed, matching qrcode.min.js — the CSP is
 * `script-src 'self'`, so a CDN would be blocked. To upgrade: download the new
 * chart.umd.min.js from https://www.chartjs.org/ and update the version here.
 *
 * Loaded only on pages that set $includeCharts. Keeps the compact, low-chrome
 * look of the rest of the app: no chart titles (the card header does that job),
 * thin gridlines, slate text, and the app accent indigo as the primary series.
 *
 * Note: any Tailwind class used here would be purged (tailwind.config.js only
 * scans src/Views/**), so colours are literal hex values matching the theme.
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined') return;

    var C = window.CrmCharts = {};

    C.colors = {
        accent:     '#4f46e5', // accent-600
        accentSoft: '#6366f1', // accent-500
        slate:      '#cbd5e1', // slate-300
        slateDark:  '#64748b', // slate-500
        green:      '#22c55e',
        amber:      '#f59e0b',
        red:        '#ef4444',
        blue:       '#3b82f6',
        purple:     '#a855f7',
        teal:       '#14b8a6',
    };

    // Categorical palette for doughnuts / many-series bars.
    C.palette = [
        C.colors.accent, C.colors.blue, C.colors.teal, C.colors.amber,
        C.colors.purple, C.colors.green, C.colors.red, C.colors.slateDark,
        '#818cf8', '#f472b6', '#0ea5e9', '#84cc16',
    ];

    C.gbp = function (n, decimals) {
        var d = decimals === undefined ? 2 : decimals;
        return '£' + (Number(n) || 0).toLocaleString('en-GB', {
            minimumFractionDigits: d, maximumFractionDigits: d,
        });
    };

    /** Compact axis labels: £1.2k, £3.4M. */
    C.gbpShort = function (n) {
        var v = Number(n) || 0, abs = Math.abs(v);
        if (abs >= 1e6) return '£' + (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (abs >= 1e3) return '£' + (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return '£' + v.toFixed(0);
    };

    Chart.defaults.font.family =
        'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.animation.duration = 300;

    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'rectRounded';
    Chart.defaults.plugins.legend.labels.padding = 14;

    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.92)'; // slate-900
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 4;
    Chart.defaults.plugins.tooltip.titleFont = { size: 11, weight: '600' };
    Chart.defaults.plugins.tooltip.bodyFont = { size: 11 };
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxWidth = 8;
    Chart.defaults.plugins.tooltip.boxHeight = 8;
    Chart.defaults.plugins.tooltip.usePointStyle = true;

    /** Money axis config, shared by the bar/line charts. */
    C.moneyScale = function (extra) {
        return Object.assign({
            beginAtZero: true,
            border: { display: false },
            grid: { color: '#f1f5f9', drawTicks: false },
            ticks: { padding: 6, callback: function (v) { return C.gbpShort(v); } },
        }, extra || {});
    };

    C.categoryScale = function (extra) {
        return Object.assign({
            border: { display: false },
            grid: { display: false },
            ticks: { padding: 4 },
        }, extra || {});
    };

    /** Tooltip label callback that formats the value as GBP. */
    C.moneyTooltip = function (ctx) {
        var v = ctx.parsed.y !== undefined && ctx.parsed.y !== null ? ctx.parsed.y : ctx.parsed;
        return ' ' + ctx.dataset.label + ': ' + C.gbp(v);
    };

    /**
     * Doughnut with a GBP tooltip that also shows each slice's share.
     * `data` is [{label, value, color?}].
     */
    C.doughnut = function (canvas, data, opts) {
        opts = opts || {};
        var total = data.reduce(function (s, d) { return s + (Number(d.value) || 0); }, 0);

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(function (d) { return d.label; }),
                datasets: [{
                    data: data.map(function (d) { return d.value; }),
                    backgroundColor: data.map(function (d, i) {
                        return d.color || C.palette[i % C.palette.length];
                    }),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 4,
                }],
            },
            options: {
                cutout: opts.cutout || '62%',
                plugins: {
                    legend: { position: opts.legendPosition || 'right' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = Number(ctx.parsed) || 0;
                                var pct = total > 0 ? Math.round((v / total) * 1000) / 10 : 0;
                                var val = opts.money === false
                                    ? v.toLocaleString('en-GB')
                                    : C.gbp(v);
                                return ' ' + ctx.label + ': ' + val + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    };

    /** Render "no data yet" in place of an empty chart. */
    C.empty = function (canvas, message) {
        var p = document.createElement('p');
        p.className = 'text-sm text-slate-400 text-center py-10';
        p.textContent = message || 'No data yet.';
        canvas.replaceWith(p);
    };
})();
