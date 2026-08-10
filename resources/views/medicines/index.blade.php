@extends('layouts.app')

@section('title', __('medicines.title'))

@section('content')
    @vite(['resources/css/medicines.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('medicines.main_heading')</h1>
                <p>@lang('medicines.main_description')</p>
            </div>
            <div class="header-actions">
                <div class="notification-btn" title="@lang('topbar.notifications_tooltip')"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg></div>
                <a href="{{ route('medicines.create') }}" class="btn-add-medicine">
                    <span>+</span> @lang('medicines.add_medicine_button')
                </a>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('medicines.breadcrumb_main')</a>
            <span>‹</span>
            <span>@lang('medicines.breadcrumb_current')</span>
        </div>

        <!-- 3. Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-title">@lang('medicines.total_medicines')</span>
                <span class="stat-value total counter" data-target="{{ $medicines->total() }}">{{ $medicines->total() }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('medicines.available_now')</span>
                <span class="stat-value available counter" data-target="0">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('medicines.low_stock')</span>
                <span class="stat-value low counter" data-target="0">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('medicines.out_of_stock')</span>
                <span class="stat-value out counter" data-target="0">0</span>
            </div>
        </div>

        <!-- 4. Charts Section -->
        <div class="charts-section-grid">
            <div class="chart-card glass-card">
                <div class="chart-card-header">
                    <div class="header-icon icon-teal"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg></div>
                    <div>
                        <h3>@lang('medicines.category_distribution_title')</h3>
                        <p class="chart-subtext">@lang('medicines.category_distribution_desc')</p>
                    </div>
                </div>

                <div class="chart-donut-layout">
                    <div class="donut-container-lg">
                        <div class="donut-chart-animated">
                            <div class="donut-inner-hole">
                                <span class="donut-number">{{ $medicines->total() }}</span>
                                <span class="donut-caption">@lang('medicines.total_medicines_label')</span>
                            </div>
                        </div>
                    </div>

                    <div class="chart-legend-vertical">
                        <div class="legend-row">
                            <span class="dot-indicator" style="--dot-color: #0B8FAC;"></span>
                            <span class="legend-text">@lang('medicines.painkillers_category')</span>
                            <span class="badge-percent badge-teal">45%</span>
                        </div>
                        <div class="legend-row">
                            <span class="dot-indicator" style="--dot-color: #0284c7;"></span>
                            <span class="legend-text">@lang('medicines.antibiotics_category')</span>
                            <span class="badge-percent badge-sky">30%</span>
                        </div>
                        <div class="legend-row">
                            <span class="dot-indicator" style="--dot-color: #d97706;"></span>
                            <span class="legend-text">@lang('medicines.chronic_category')</span>
                            <span class="badge-percent badge-amber">15%</span>
                        </div>
                        <div class="legend-row">
                            <span class="dot-indicator" style="--dot-color: #e11d48;"></span>
                            <span class="legend-text">@lang('medicines.other_category')</span>
                            <span class="badge-percent badge-rose">10%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chart-card glass-card">
                <div class="chart-card-header">
                    <div class="header-icon icon-emerald"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                    <div>
                        <h3>@lang('medicines.availability_status_title')</h3>
                        <p class="chart-subtext">@lang('medicines.availability_status_desc')</p>
                    </div>
                </div>

                <div class="bars-chart-container">
                    <div class="bar-progress-group">
                        <div class="bar-info-row">
                            <span class="bar-title">● @lang('medicines.available_status')</span>
                            <span class="bar-percentage text-emerald">70%</span>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill fill-emerald" style="--fill-w: 70%;"></div>
                        </div>
                    </div>

                    <div class="bar-progress-group">
                        <div class="bar-info-row">
                            <span class="bar-title">● @lang('medicines.low_stock_status')</span>
                            <span class="bar-percentage text-amber">20%</span>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill fill-amber" style="--fill-w: 20%;"></div>
                        </div>
                    </div>

                    <div class="bar-progress-group">
                        <div class="bar-info-row">
                            <span class="bar-title">● @lang('medicines.out_of_stock_status')</span>
                            <span class="bar-percentage text-rose">10%</span>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill fill-rose" style="--fill-w: 10%;"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- 5. Filter and Search Bar -->
        <div class="filter-card">
            <div class="filter-row">
                <input type="text" id="searchInput" placeholder="@lang('medicines.search_placeholder')" class="filter-input">
                <select id="categoryFilter" class="filter-select">
                    <option value="">@lang('medicines.all_categories_filter')</option>
                    <option value="painkillers">@lang('medicines.painkillers_category')</option>
                    <option value="antibiotics">@lang('medicines.antibiotics_category')</option>
                </select>
                <select id="statusFilter" class="filter-select">
                    <option value="">@lang('medicines.all_statuses_filter')</option>
                    <option value="available">@lang('medicines.available_status')</option>
                    <option value="low">@lang('medicines.low_stock_status')</option>
                    <option value="out">@lang('medicines.out_of_stock_status')</option>
                </select>
            </div>
        </div>

        <!-- 6. Table Card -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('medicines.table_heading')</h3>
                <span id="registeredCount">@lang('medicines.registered_medicines', ['count' => $medicines->total()])</span>
            </div>

            <table class="custom-table" id="medicinesTable">
                <thead>
                <tr>
                    <th>@lang('medicines.col_medicine')</th>
                    <th>@lang('medicines.col_active_ingredient')</th>
                    <th>@lang('medicines.col_category')</th>
                    <th>@lang('medicines.col_available_in')</th>
                    <th>@lang('medicines.col_lowest_price')</th>
                    <th>@lang('medicines.col_status')</th>
                    <th>@lang('medicines.col_alternative')</th>
                    <th style="text-align: center;">@lang('medicines.col_action')</th>
                </tr>
                </thead>
                <tbody id="tableBody">
                @forelse($medicines as $medicine)
                    <tr data-status="available" data-category="@lang('medicines.painkillers_category')">
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="pill-icon-wrapper icon-cyan"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></div>
                                <div>
                                    <strong>{{ $medicine->trade_name }}</strong><br>
                                    <small style="color: #94a3b8; font-size: 11px;">{{ $medicine->scientific_name }}</small>
                                </div>
                            </div>
                        </td>
                        <td>{{ $medicine->scientific_name }}</td>
                        <td><span class="pill-badge badge-category">● @lang('medicines.painkillers_category')</span></td>
                        <td><strong style="color: #0B8FAC;">0</strong> @lang('medicines.pharmacies_count', ['count' => 0])</td>
                        <td><strong>₪ {{ number_format($medicine->price ?? 0, 2) }}</strong></td>
                        <td><span class="pill-badge status-badge available">● @lang('medicines.available_status')</span></td>
                        <td><span class="pill-badge badge-none">• —</span></td>
                        <td>
                            <div class="action-btn-group">
                                <form action="{{ route('medicines.destroy', $medicine->id) }}" method="POST" onsubmit="return confirm('{{ __('medicines.delete_confirm') }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete" title="@lang('medicines.tooltip_delete')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </form>
                                <a href="{{ route('medicines.edit', $medicine->id) }}" class="action-btn edit" title="@lang('medicines.tooltip_edit')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                            @lang('medicines.no_medicines_found')
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $medicines->links() }}
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.counter').forEach(counter => {
                const target = Number(counter.dataset.target || 0);
                const duration = 900;
                const start = performance.now();

                const tick = (now) => {
                    const progress = Math.min((now - start) / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    counter.textContent = Math.floor(target * eased).toLocaleString('en-US');

                    if (progress < 1) requestAnimationFrame(tick);
                };

                requestAnimationFrame(tick);
            });

            const search = document.getElementById('searchInput');
            const category = document.getElementById('categoryFilter');
            const status = document.getElementById('statusFilter');
            const rows = [...document.querySelectorAll('#tableBody tr[data-status]')];
            const count = document.getElementById('registeredCount');

            const normalize = value => (value || '').trim().toLowerCase();

            const filterRows = () => {
                const q = normalize(search?.value);
                const selectedCategory = normalize(category?.value);
                const selectedStatus = normalize(status?.value);

                let visible = 0;

                rows.forEach(row => {
                    const text = normalize(row.textContent);
                    const rowCategory = normalize(row.dataset.category);
                    const rowStatus = normalize(row.dataset.status);

                    const matchesSearch = !q || text.includes(q);
                    const matchesCategory = !selectedCategory || rowCategory.includes(selectedCategory);
                    const matchesStatus = !selectedStatus || rowStatus === selectedStatus;

                    const show = matchesSearch && matchesCategory && matchesStatus;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                if (count) {
                    count.textContent = `@lang('medicines.filtered_medicines', ['count' => 'visible'])`;
                }
            };

            [search, category, status].forEach(el => {
                if (el) {
                    el.addEventListener('input', filterRows);
                    el.addEventListener('change', filterRows);
                }
            });

            rows.forEach((row, index) => {
                row.style.animation = `tableRowIn 0.45s ${Math.min(index * 0.035, 0.6)}s both`;
            });
        });
    </script>
@endsection
