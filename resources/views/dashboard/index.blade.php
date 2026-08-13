@extends('layouts.app')

@section('title', __('dashboard.title'))

@section('content')
    @vite(['resources/css/pages/dashboard.css'])

    <div>
        <!-- 1. Top Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card card-teal">
                <div class="card-header-flex">
                    <span class="card-label">@lang('dashboard.total_patients')</span>
                    <div class="stat-icon icon-teal"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                </div>
                <h2 class="stat-value counter" data-target="{{ $totalPatients ?? 0 }}">0</h2>
                <div class="card-footer-flex">
                    <span class="trend-up {{ ($trendPatients ?? 0) < 0 ? 'trend-down' : '' }}">{{ ($trendPatients ?? 0) >= 0 ? '▲' : '▼' }} {{ abs($trendPatients ?? 0) }}%</span>
                    <span class="trend-label">@lang('dashboard.vs_last_month')</span>
                </div>
            </div>

            <div class="stat-card card-green">
                <div class="card-header-flex">
                    <span class="card-label">@lang('dashboard.active_pharmacies')</span>
                    <div class="stat-icon icon-green"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                </div>
                <h2 class="stat-value counter" data-target="{{ $activePharmacies ?? 0 }}">0</h2>
                <div class="card-footer-flex">
                    <span class="trend-up">▲ {{ $newPharmaciesThisWeek ?? 0 }}</span>
                    <span class="trend-label">@lang('dashboard.new_pharmacies_this_week')</span>
                </div>
            </div>

            <div class="stat-card card-amber">
                <div class="card-header-flex">
                    <span class="card-label">@lang('dashboard.registered_medicines')</span>
                    <div class="stat-icon icon-amber"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></div>
                </div>
                <h2 class="stat-value counter" data-target="{{ $totalMedicines ?? 0 }}">0</h2>
                <div class="card-footer-flex">
                    <span class="trend-up">▲ {{ $medicinesAddedRecently ?? 0 }}</span>
                    <span class="trend-label">@lang('dashboard.medicines_added_recently')</span>
                </div>
            </div>

            <div class="stat-card card-blue">
                <div class="card-header-flex">
                    <span class="card-label">@lang('dashboard.total_users')</span>
                    <div class="stat-icon icon-blue"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
                </div>
                <h2 class="stat-value counter" data-target="{{ $totalOtherUsers ?? 0 }}">0</h2>
                <div class="card-footer-flex">
                    <span class="trend-label">@lang('dashboard.admins_and_pharmacies')</span>
                </div>
            </div>
        </div>

        <!-- 2. Chart and Activities Section -->
        <div class="grid-2-1">
            <!-- Featured Chart Card -->
            <div class="featured-chart-card">
                <div class="chart-header-wrapper">
                    <div class="chart-title-group">
                        <div class="title-with-badge">
                            <h3 class="chart-main-title">@lang('dashboard.platform_activity')</h3>
                            <span class="live-pulse-badge">
                                <span class="pulse-dot"></span>
                                @lang('dashboard.live_update')
                            </span>
                        </div>
                        <div class="chart-subtitle">
                            <span>@lang('dashboard.track_searches')</span>
                            <span class="sub-trend-badge" id="trendBadge">{{ $chartDatasets['all']['trend'] }}</span>
                        </div>
                    </div>

                    <!-- Interactive Filter Buttons -->
                    <div class="filter-pills-group" id="filterPills">
                        <button class="pill-btn active" data-filter="all">@lang('dashboard.all_filter')</button>
                        <button class="pill-btn" data-filter="medicines">@lang('dashboard.medicines_search_filter')</button>
                        <button class="pill-btn" data-filter="users">@lang('dashboard.users_filter')</button>
                    </div>
                </div>

                <div class="chart-container-pro" id="chartContainer">
                    @foreach($chartDatasets['all']['values'] as $weekValue)
                        <div class="chart-bar-wrapper"><div class="chart-bar-pro" style="height: {{ $chartDatasets['all']['heights'][$loop->index] }}%;" data-value="{{ $weekValue }}"></div></div>
                    @endforeach
                </div>

                <div class="chart-footer-labels">
                    <span>@lang('dashboard.week_8')</span><span>@lang('dashboard.week_7')</span><span>@lang('dashboard.week_6')</span><span>@lang('dashboard.week_5')</span><span>@lang('dashboard.week_4')</span><span>@lang('dashboard.week_3')</span><span>@lang('dashboard.week_2')</span><span>@lang('dashboard.week_1')</span>
                </div>
            </div>

            <!-- Daily Activities Card -->
            <div class="pro-card">
                <div class="card-header-between">
                    <h3 class="card-pro-title">@lang('dashboard.latest_activities')</h3>
                    <button type="button" class="btn-view-all-logs" id="openLogModalBtn">@lang('dashboard.view_all')</button>
                </div>
                <div class="activity-feed">
                    @forelse($recentActivities as $activity)
                        @php
                            if (is_array($activity)) {
                                $activity = (object) ['description' => $activity['description'] ?? '', 'time' => $activity['time'] ?? '', 'color' => $activity['color'] ?? '#0B8FAC'];
                            } elseif (!is_object($activity)) {
                                $activity = (object) ['description' => $activity, 'time' => '', 'color' => '#0B8FAC'];
                            }
                        @endphp
                        <div class="activity-card">
                            <span class="dot-indicator" style="background: {{ $activity->color ?? '#0B8FAC' }};"></span>
                            <div class="activity-desc">{!! $activity->description ?? '' !!}</div>
                            <small class="activity-time">{{ $activity->time ?? '' }}</small>
                        </div>
                    @empty
                        <div class="empty-state">@lang('dashboard.no_recent_activities')</div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- 3. Patients Table -->
        <div class="table-card">
            <div class="table-header-between">
                <div>
                    <h3 class="card-pro-title">@lang('dashboard.latest_registered_patients')</h3>
                    <span class="table-subtitle">@lang('dashboard.list_of_latest_patients')</span>
                </div>
                <a href="{{ route('users.index') }}" class="btn-link-pro">@lang('dashboard.view_all_users') →</a>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                    <tr>
                        <th>@lang('dashboard.patient_header')</th>
                        <th>@lang('dashboard.email_header')</th>
                        <th>@lang('dashboard.registration_date_header')</th>
                        <th class="text-center">@lang('dashboard.actions_header')</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($patients as $patient)
                        <tr>
                            <td>
                                <div class="patient-cell">
                                    <div class="avatar-circle">{{ mb_substr($patient->name, 0, 1) }}</div>
                                    <div class="patient-details">
                                        <strong class="patient-name">{{ $patient->name }}</strong>
                                        <small class="patient-phone">{{ $patient->phone ?? '—' }}</small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="email-badge">{{ $patient->email }}</span></td>
                            <td><span class="date-text">{{ $patient->created_at ? $patient->created_at->diffForHumans() : '—' }}</span></td>
                            <td class="text-center">
                                <div class="actions-group">
                                    <a href="{{ route('users.edit', $patient->id) }}" class="action-btn btn-edit" title="@lang('dashboard.edit_tooltip')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                    <a href="{{ route('users.show', $patient->id) }}" class="action-btn btn-view" title="@lang('dashboard.view_details')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty-table-state">@lang('dashboard.no_patients')</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 4. Full Activity Log Modal -->
    <div class="modal-overlay" id="activityModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title-group">
                    <h3>@lang('dashboard.full_activity_log')</h3>
                    <p>@lang('dashboard.activity_log_description')</p>
                </div>
                <button type="button" class="close-modal-btn" id="closeLogModalBtn" title="@lang('dashboard.close')">&times;</button>
            </div>

            <div class="modal-controls">
                <div class="search-input-wrapper">
                    <input type="text" id="modalSearchInput" placeholder="@lang('dashboard.search_activity_log')">
                </div>
            </div>

            <div class="modal-body">
                <table class="modal-log-table">
                    <thead>
                    <tr>
                        <th>@lang('dashboard.type')</th>
                        <th>@lang('dashboard.details')</th>
                        <th>@lang('dashboard.time')</th>
                    </tr>
                    </thead>
                    <tbody id="modalLogTableBody">
                    @forelse($recentActivities as $activity)
                        <tr>
                            <td><span class="dot-indicator" style="background: {{ $activity->color ?? '#0B8FAC' }}; display: inline-block;"></span></td>
                            <td>{!! $activity->description !!}</td>
                            <td><small style="color: #64748b;">{{ $activity->time }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 20px;">@lang('dashboard.no_logs')</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="modal-footer">
                <span>@lang('dashboard.activities_displayed') <strong id="modalLogCount">{{ count($recentActivities) }}</strong></span>
                <button type="button" class="page-btn" id="modalCloseFooterBtn">@lang('dashboard.close')</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1. Interactive Number Counters
            document.querySelectorAll('.counter').forEach(counter => {
                const target = Number(counter.dataset.target || 0);
                const duration = 1000;
                const start = performance.now();

                const step = (now) => {
                    const progress = Math.min((now - start) / duration, 1);
                    const easeOutQuad = 1 - (1 - progress) * (1 - progress);
                    counter.textContent = Math.floor(target * easeOutQuad).toLocaleString('en-US');

                    if (progress < 1) requestAnimationFrame(step);
                };

                requestAnimationFrame(step);
            });

            // 2. Chart Filtering (real data from server)
            const filterButtons = document.querySelectorAll('#filterPills .pill-btn');
            const bars = document.querySelectorAll('#chartContainer .chart-bar-pro');
            const trendBadge = document.getElementById('trendBadge');

            const chartDatasets = @json($chartDatasets);

            filterButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    filterButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    const filterType = btn.dataset.filter;
                    const dataset = chartDatasets[filterType] || chartDatasets.all;

                    if (trendBadge) trendBadge.textContent = dataset.trend;

                    bars.forEach((bar, index) => {
                        const newHeight = (dataset.heights[index] ?? 0) + '%';
                        bar.style.height = newHeight;
                        bar.setAttribute('data-value', dataset.values[index] ?? 0);
                    });
                });
            });

            // 3. Modal Log
            const activityModal = document.getElementById('activityModal');
            const openModalBtn = document.getElementById('openLogModalBtn');
            const closeModalBtn = document.getElementById('closeLogModalBtn');
            const closeFooterBtn = document.getElementById('modalCloseFooterBtn');
            const modalSearchInput = document.getElementById('modalSearchInput');
            const modalLogTableBody = document.getElementById('modalLogTableBody');
            const modalLogCount = document.getElementById('modalLogCount');

            const toggleModal = (show) => {
                if (activityModal) {
                    activityModal.classList.toggle('active', show);
                }
            };

            if (openModalBtn) openModalBtn.addEventListener('click', () => toggleModal(true));
            if (closeModalBtn) closeModalBtn.addEventListener('click', () => toggleModal(false));
            if (closeFooterBtn) closeFooterBtn.addEventListener('click', () => toggleModal(false));

            if (activityModal) {
                activityModal.addEventListener('click', (e) => {
                    if (e.target === activityModal) toggleModal(false);
                });
            }

            if (modalSearchInput && modalLogTableBody) {
                modalSearchInput.addEventListener('input', () => {
                    const query = modalSearchInput.value.toLowerCase().trim();
                    const logRows = modalLogTableBody.querySelectorAll('tr');
                    let visibleCount = 0;

                    logRows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const matches = text.includes(query);
                        row.style.display = matches ? '' : 'none';
                        if (matches) visibleCount++;
                    });

                    if (modalLogCount) modalLogCount.textContent = visibleCount;
                });
            }
        });

        // ===== التحميل المسبق: بعد الدخول تُحمَّل الصفحات مرة واحدة وتُخزَّن (متصفح + خادم) =====
        (function () {
            const prefetchUrls = [
                '/users',
                '/medicines',
                '/pharmacies',
                '/patients',
                '/inventory',
                '/logs',
                '/api/notifications/count'
            ];
            if (typeof fetch !== 'function') return;
            prefetchUrls.forEach(function (url, i) {
                setTimeout(function () {
                    try {
                        fetch(url, { credentials: 'same-origin', cache: 'force-cache' });
                    } catch (e) { /* ignore */ }
                }, i * 400);
            });
        })();
    </script>
@endsection
