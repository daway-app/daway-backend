document.addEventListener('DOMContentLoaded', function () {

                /* =====================================================
                   THEME
                ===================================================== */

                const themeToggle =
                    document.getElementById('themeToggle');

                const themeIcon =
                    themeToggle?.querySelector('.theme-toggle-icon i');

                const themeText =
                    themeToggle?.querySelector('.theme-toggle-text');


                const savedTheme =
                    localStorage.getItem('pharmacy-dashboard-theme');


                if (savedTheme === 'dark') {

                    document.documentElement.classList.add(
                        'dark-dashboard'
                    );

                    if (themeIcon) {

                        themeIcon.className =
                            'fas fa-sun';

                    }

                    if (themeText) {

                        themeText.textContent =
                            'الوضع النهاري';

                    }

                }


                themeToggle?.addEventListener(
                    'click',
                    function () {

                        const isDark =
                            document.documentElement.classList.toggle(
                                'dark-dashboard'
                            );


                        localStorage.setItem(
                            'pharmacy-dashboard-theme',
                            isDark ? 'dark' : 'light'
                        );


                        if (themeIcon) {

                            themeIcon.className =
                                isDark
                                    ? 'fas fa-sun'
                                    : 'fas fa-moon';

                        }


                        if (themeText) {

                            themeText.textContent =
                                isDark
                                    ? 'الوضع النهاري'
                                    : 'الوضع الليلي';

                        }


                        updateChartTheme();

                    }
                );


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


                const isDarkMode = () =>
                    document.documentElement.classList.contains(
                        'dark-dashboard'
                    );


                const chart =
                    new Chart(ctx, {

                        type:
                            'line',

                        data: {

                            labels: [

                                'السبت',
                                'الأحد',
                                'الإثنين',
                                'الثلاثاء',
                                'الأربعاء',
                                'الخميس',
                                'الجمعة'

                            ],

                            datasets: [

                                {

                                    label:
                                        'الطلبات',

                                    data: [

                                        14,
                                        22,
                                        18,
                                        29,
                                        25,
                                        34,
                                        40

                                    ],

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

                                    data: [

                                        7,
                                        8,
                                        7,
                                        9,
                                        8,
                                        10,
                                        11

                                    ],

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
