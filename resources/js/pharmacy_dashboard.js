document.addEventListener('DOMContentLoaded', function () {

                /* =====================================================
                   THEME (unified with the app: localStorage 'theme',
                   html/body .dark-mode + html[data-theme] + .dark-dashboard
                   for the dashboard-specific CSS variables)
                ===================================================== */

                const themeToggle =
                    document.getElementById('themeToggle');

                const themeIcon =
                    themeToggle?.querySelector('.theme-toggle-icon i');

                const themeText =
                    themeToggle?.querySelector('.theme-toggle-text');

                function isDarkMode() {
                    return document.documentElement.classList.contains('dark-mode');
                }

                function applyTheme(dark) {
                    document.documentElement.classList.toggle('dark-mode', dark);
                    document.body.classList.toggle('dark-mode', dark);
                    document.documentElement.classList.toggle('dark-dashboard', dark);
                    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                    if (themeIcon) {
                        themeIcon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
                    }
                    if (themeText) {
                        themeText.textContent = dark ? 'الوضع النهاري' : 'الوضع الليلي';
                    }
                }

                if (localStorage.getItem('theme') === 'dark') {
                    applyTheme(true);
                }

                themeToggle?.addEventListener('click', function () {
                    const dark = !isDarkMode();
                    applyTheme(dark);
                    localStorage.setItem('theme', dark ? 'dark' : 'light');
                    updateChartTheme();
                });

                new MutationObserver(function () {
                    const dark = isDarkMode();
                    document.documentElement.classList.toggle('dark-dashboard', dark);
                    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                    if (document.getElementById('pharmacyActivityChart')) {
                        updateChartTheme();
                    }
                }).observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });


                /* =====================================================
                   CHART
                ===================================================== */

                const canvas =
                    document.getElementById(
                        'pharmacyActivityChart'
                    );


                if (!canvas) {
                    return;
                }


                const ctx =
                    canvas.getContext('2d');


                let tealGradient =
                    ctx.createLinearGradient(
                        0,
                        0,
                        0,
                        320
                    );


                tealGradient.addColorStop(
                    0,
                    'rgba(11,143,172,.22)'
                );


                tealGradient.addColorStop(
                    1,
                    'rgba(11,143,172,0)'
                );


                const chartData =
                    window.pharmacyDashboardChart || {};

                const chartLabels =
                    Array.isArray(chartData.labels)
                        ? chartData.labels
                        : [

                                'السبت',
                                'الأحد',
                                'الإثنين',
                                'الثلاثاء',
                                'الأربعاء',
                                'الخميس',
                                'الجمعة'

                            ];

                const chartOrders =
                    Array.isArray(chartData.orders)
                        ? chartData.orders
                        : [

                                14,
                                22,
                                18,
                                29,
                                25,
                                34,
                                40

                            ];

                const chartRatings =
                    Array.isArray(chartData.ratings)
                        ? chartData.ratings
                        : [

                                7,
                                8,
                                7,
                                9,
                                8,
                                10,
                                11

                            ];

                const chart =
                    new Chart(ctx, {

                        type:
                            'line',

                        data: {

                            labels: chartLabels,

                            datasets: [

                                {

                                    label:
                                        'الطلبات',

                                    data: chartOrders,

                                    borderColor:
                                        '#0B8FAC',

                                    backgroundColor:
                                    tealGradient,

                                    borderWidth:
                                        3,

                                    fill:
                                        true,

                                    tension:
                                        .42,

                                    pointRadius:
                                        4,

                                    pointHoverRadius:
                                        7,

                                    pointBackgroundColor:
                                        isDarkMode()
                                            ? '#18201d'
                                            : '#ffffff',

                                    pointBorderColor:
                                        '#0B8FAC',

                                    pointBorderWidth:
                                        2

                                },

                                {

                                    label:
                                        'التقييمات',

                                    data: chartRatings,

                                    borderColor:
                                        '#e8a000',

                                    backgroundColor:
                                        'transparent',

                                    borderWidth:
                                        2.5,

                                    borderDash: [

                                        7,
                                        5

                                    ],

                                    fill:
                                        false,

                                    tension:
                                        .42,

                                    pointRadius:
                                        3.5,

                                    pointHoverRadius:
                                        7,

                                    pointBackgroundColor:
                                        isDarkMode()
                                            ? '#18201d'
                                            : '#ffffff',

                                    pointBorderColor:
                                        '#e8a000',

                                    pointBorderWidth:
                                        2

                                }

                            ]

                        },


                        options: {

                            responsive:
                                true,

                            maintainAspectRatio:
                                false,


                            interaction: {

                                mode:
                                    'index',

                                intersect:
                                    false

                            },


                            animation: {

                                duration:
                                    1300,

                                easing:
                                    'easeOutQuart'

                            },


                            plugins: {

                                legend: {

                                    position:
                                        'top',

                                    align:
                                        'start',

                                    labels: {

                                        color:
                                            isDarkMode()
                                                ? '#aebbb7'
                                                : '#667773',

                                        usePointStyle:
                                            true,

                                        pointStyle:
                                            'circle',

                                        padding:
                                            18,

                                        font: {

                                            family:
                                                'Cairo, sans-serif',

                                            size:
                                                11,

                                            weight:
                                                '600'

                                        }

                                    }

                                },


                                tooltip: {

                                    rtl:
                                        true,

                                    textDirection:
                                        'rtl',

                                    backgroundColor:
                                        isDarkMode()
                                            ? '#101816'
                                            : '#ffffff',

                                    borderColor:
                                        isDarkMode()
                                            ? 'rgba(255,255,255,.10)'
                                            : '#dce7e4',

                                    borderWidth:
                                        1,

                                    titleColor:
                                        isDarkMode()
                                            ? '#ffffff'
                                            : '#172321',

                                    bodyColor:
                                        isDarkMode()
                                            ? '#bfc9c5'
                                            : '#536460',

                                    padding:
                                        12,

                                    cornerRadius:
                                        10,

                                    displayColors:
                                        true,

                                    titleFont: {

                                        family:
                                            'Cairo, sans-serif',

                                        size:
                                            11,

                                        weight:
                                            '700'

                                    },

                                    bodyFont: {

                                        family:
                                            'Cairo, sans-serif',

                                        size:
                                            10

                                    }

                                }

                            },


                            scales: {

                                x: {

                                    border: {

                                        display:
                                            false

                                    },

                                    grid: {

                                        color:
                                            () =>
                                                isDarkMode()
                                                    ? 'rgba(255,255,255,.045)'
                                                    : 'rgba(50,80,75,.07)',

                                        drawTicks:
                                            false

                                    },

                                    ticks: {

                                        color:
                                            () =>
                                                isDarkMode()
                                                    ? '#778581'
                                                    : '#788985',

                                        padding:
                                            8,

                                        font: {

                                            family:
                                                'Cairo, sans-serif',

                                            size:
                                                10

                                        }

                                    }

                                },


                                y: {

                                    position:
                                        'right',

                                    beginAtZero:
                                        true,

                                    border: {

                                        display:
                                            false

                                    },

                                    grid: {

                                        color:
                                            () =>
                                                isDarkMode()
                                                    ? 'rgba(255,255,255,.045)'
                                                    : 'rgba(50,80,75,.07)',

                                        drawTicks:
                                            false

                                    },

                                    ticks: {

                                        color:
                                            () =>
                                                isDarkMode()
                                                    ? '#778581'
                                                    : '#788985',

                                        padding:
                                            8,

                                        font: {

                                            family:
                                                'Cairo, sans-serif',

                                            size:
                                                10

                                        }

                                    }

                                }

                            }

                        }

                    });


                /* =====================================================
                   UPDATE CHART THEME
                ===================================================== */

                function updateChartTheme() {

                    if (!chart) {
                        return;
                    }


                    const dark =
                        isDarkMode();


                    chart.data.datasets.forEach(
                        function (dataset) {

                            dataset.pointBackgroundColor =
                                dark
                                    ? '#232327'
                                    : '#ffffff';

                        }
                    );


                    chart.options.plugins.legend.labels.color =
                        dark
                            ? '#A1A1AA'
                            : '#667773';


                    chart.options.plugins.tooltip.backgroundColor =
                        dark
                            ? '#18181B'
                            : '#ffffff';


                    chart.options.plugins.tooltip.titleColor =
                        dark
                            ? '#ffffff'
                            : '#172321';


                    chart.options.plugins.tooltip.bodyColor =
                        dark
                            ? '#A1A1AA'
                            : '#536460';


                    chart.update();

                }

            });
