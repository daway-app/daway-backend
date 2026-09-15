/**
 * Daway Accounting — صفحة النظرة العامة (Overview)
 * ==========================================================
 * مسؤولياته:
 *   1. رسم مخطط المبيعات + تبديل الفترة (Today / 7d / 30d / Month).
 *   2. إعادة الرسم عند تبديل الوضع الفاتح/الداكن (نفس نمط pharmacy_hub.js).
 *   3. فتح/إغلاق حوارات الصفحة.
 * لا يحمل أي منطق أعمال — الأرقام تُمرَّر عبر data-* أو window.acOverviewConfig.
 */
(function () {
    'use strict';

    var U = window.AccountingUtil;
    var charts = [];

    function el(name) {
        return document.querySelector('[data-ac-chart="' + name + '"]');
    }

    /* ------------------------------------------------------
       بناء مخطط المبيعات
       ------------------------------------------------------ */
    function buildSalesChart(canvas, series) {
        var dark = U.isDark();
        var grid = U.token('--line-soft') || '#EEF4F3';
        var tick = U.token('--ink-faint') || '#5C7073';
        var accent = U.token('--focus-ring') || '#1C72A6';

        return new window.Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: series.labels || [],
                datasets: [{
                    data: series.data || [],
                    borderColor: accent,
                    backgroundColor: dark ? 'rgba(56,189,248,.16)' : 'rgba(28,114,166,.10)',
                    pointBackgroundColor: accent,
                    pointBorderColor: U.token('--paper') || '#fff',
                    pointBorderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        rtl: document.dir === 'rtl',
                        textDirection: document.dir === 'rtl' ? 'rtl' : 'ltr',
                        callbacks: {
                            label: function (ctx) {
                                return U.fmtMoney(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: grid },
                        ticks: {
                            color: tick,
                            callback: function (v) {
                                return U.fmtNumber(v, 0);
                            }
                        }
                    },
                    x: { grid: { display: false }, ticks: { color: tick } }
                }
            }
        });
    }

    /* ------------------------------------------------------
       رسم مخطط توزيع المصروفات (دونات)
       ------------------------------------------------------ */
    function buildExpenseChart(canvas, labels, data, colors) {
        return new window.Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.map(U.color),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        display: true,
                        position: document.dir === 'rtl' ? 'left' : 'right',
                        labels: {
                            color: U.token('--ink-faint') || '#5C7073',
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: true,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        rtl: document.dir === 'rtl',
                        callbacks: {
                            label: function (ctx) {
                                return ctx.label + ': ' + U.fmtMoney(ctx.parsed);
                            }
                        }
                    }
                },
                scales: {}
            }
        });
    }

    /* ------------------------------------------------------
       التهيئة
       ------------------------------------------------------ */
    function init() {
        if (!window.Chart) {
            return;
        }
        var cfg = window.acOverviewConfig || {};
        var series = cfg.salesSeries || {};

        // 1) مخطط المبيعات + تبويبات الفترة
        var salesCanvas = el('sales');
        if (salesCanvas) {
            var current = cfg.defaultRange || '7d';
            var chart = buildSalesChart(salesCanvas, series[current] || { labels: [], data: [] });
            charts.push({ canvas: salesCanvas, mode: 'sales' });

            document.querySelectorAll('[data-ac-range]').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var key = tab.getAttribute('data-ac-range');
                    if (!series[key]) {
                        return;
                    }
                    document.querySelectorAll('[data-ac-range]').forEach(function (t) {
                        t.classList.remove('active');
                        t.setAttribute('aria-pressed', 'false');
                    });
                    tab.classList.add('active');
                    tab.setAttribute('aria-pressed', 'true');

                    chart.data.labels = series[key].labels || [];
                    chart.data.datasets[0].data = series[key].data || [];
                    chart.update();
                });
            });
        }

        // 2) مخطط المصروفات
        var expenseCanvas = el('expenses');
        if (expenseCanvas) {
            var eLabels = JSON.parse(expenseCanvas.getAttribute('data-ac-labels') || '[]');
            var eData = JSON.parse(expenseCanvas.getAttribute('data-ac-data') || '[]');
            var eColors = JSON.parse(expenseCanvas.getAttribute('data-ac-colors') || '[]');
            var eChart = buildExpenseChart(expenseCanvas, eLabels, eData, eColors);
            charts.push({ canvas: expenseCanvas, mode: 'expenses', labels: eLabels, data: eData, colors: eColors });
        }

        U.initModalA11y();
    }

    /* ------------------------------------------------------
       إعادة الرسم عند تبديل الثيم
       ------------------------------------------------------ */
    function redraw() {
        if (!window.Chart || !charts.length) {
            return;
        }
        var cfg = window.acOverviewConfig || {};
        var series = cfg.salesSeries || {};

        charts.forEach(function (item) {
            var active = document.querySelector('[data-ac-range].active');
            var key = active ? active.getAttribute('data-ac-range') : (cfg.defaultRange || '7d');
            var existing = window.Chart.getChart(item.canvas);
            if (existing) {
                existing.destroy();
            }
            if (item.mode === 'sales') {
                buildSalesChart(item.canvas, series[key] || { labels: [], data: [] });
            } else if (item.mode === 'expenses') {
                buildExpenseChart(item.canvas, item.labels, item.data, item.colors);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
    new MutationObserver(redraw).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class']
    });
})();
