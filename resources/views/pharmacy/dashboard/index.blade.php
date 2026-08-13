@extends('layouts.app')

@section('title', __('pharmacy.dashboard.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_dashboard.css', 'resources/js/pharmacy_dashboard.js'])

    <div class="pharmacy-dashboard" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        {{-- =========================================================
             HEADER
        ========================================================== --}}
        <header class="dashboard-header dashboard-animate">

            <div class="header-content">

                <div class="header-info">

                    {{-- Pharmacy Icon --}}
                    <div class="header-icon">
                        <div class="icon-ring"></div>
                        <i class="fas fa-clinic-medical"></i>
                    </div>

                    <div class="header-text">

                        <h1>
                             {{ $pharmacy->pharmacy_name }}
                        </h1>

                        <p>
                            @lang('pharmacy.dashboard.welcome')
                        </p>

                    </div>

                </div>

                <div class="header-actions">



                    <a
                        href="{{ route('pharmacy.medicines.index') }}"
                        class="manage-btn"
                    >
                        <i class="fas fa-pills"></i>

                        <span>
                    @lang('pharmacy.dashboard.manage_medicines')
                </span>

                        <i class="fas fa-arrow-left btn-arrow"></i>
                    </a>

                </div>

            </div>

        </header>


        {{-- =========================================================
             STATISTICS
        ========================================================== --}}
        <section class="stats-grid">

            {{-- Rating --}}
            <article class="stat-card rating-card dashboard-animate delay-1">

                <div class="card-glow"></div>

                <div class="floating-particle particle-1"></div>
                <div class="floating-particle particle-2"></div>

                <div class="stat-top">

                    <div class="stat-content">

                <span class="stat-title">
                    @lang('pharmacy.dashboard.avg_rating')
                </span>

                        <div class="rating-value">

                            <strong>
                                {{ number_format($averageRating, 1) }}
                            </strong>

                            <div class="rating-stars">

                                @for($i = 1; $i <= 5; $i++)
                                    <i class="fas fa-star"></i>
                                @endfor

                            </div>

                        </div>

                        <div class="rating-label">
                            @lang('pharmacy.dashboard.excellent_rating')
                        </div>

                    </div>

                    <div class="stat-icon rating-icon">
                        <i class="fas fa-star"></i>
                    </div>

                </div>

                <div class="stat-footer">

            <span>
                <i class="fas fa-users"></i>
                @lang('pharmacy.dashboard.rating_based')
            </span>

                </div>

            </article>


            {{-- Pharmacy Status --}}
            <article
                class="stat-card status-card dashboard-animate delay-2
        {{ $isPharmacyOpen ? 'is-open' : 'is-closed' }}"
            >

                <div class="card-glow"></div>

                <div class="floating-particle particle-3"></div>
                <div class="floating-particle particle-4"></div>

                <div class="stat-top">

                    <div class="stat-content">

                <span class="stat-title">
                    @lang('pharmacy.dashboard.current_status')
                </span>

                        <div class="pharmacy-status">

                            <span class="status-dot"></span>

                            <span>
                        {{ $isPharmacyOpen
                            ? __('pharmacy.dashboard.status_open')
                            : __('pharmacy.dashboard.status_closed')
                        }}
                    </span>

                        </div>

                        <div class="status-label">
                            @lang('pharmacy.dashboard.status_label')
                        </div>

                    </div>

                    <div class="stat-icon status-icon">

                        <i class="fas fa-{{ $isPharmacyOpen ? 'store' : 'store-slash' }}"></i>

                    </div>

                </div>

                <div class="stat-footer">

            <span>
                <i class="fas fa-clock"></i>
                @lang('pharmacy.dashboard.edit_hours_hint')
            </span>

                </div>

            </article>


            {{-- Stock --}}
            <article class="stat-card stock-card dashboard-animate delay-3">

                <div class="card-glow"></div>

                <div class="floating-particle particle-5"></div>
                <div class="floating-particle particle-6"></div>

                <div class="stat-top">

                    <div class="stat-content">

                <span class="stat-title">
                    @lang('pharmacy.dashboard.total_stock')
                </span>

                        <div class="stock-value">

                            {{ $totalMedicinesInStock }}

                            <span>
                        @lang('pharmacy.dashboard.item_unit')
                    </span>

                        </div>

                        <div class="stock-label">
                            @lang('pharmacy.dashboard.available_in_pharmacy')
                        </div>

                    </div>

                    <div class="stat-icon stock-icon">
                        <i class="fas fa-boxes-stacked"></i>
                    </div>

                </div>

                <div class="stat-footer">

            <span>
                <i class="fas fa-rotate"></i>
                @lang('pharmacy.dashboard.auto_updated')
            </span>

                </div>

            </article>

        </section>


        {{-- =========================================================
             WEEKLY ACTIVITY
        ========================================================== --}}
        <section class="dashboard-card chart-card dashboard-animate delay-4">

            <div class="card-header">

                <div class="section-title">

                    <div class="section-icon animated-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>

                    <div>

                        <h2>
                            @lang('pharmacy.dashboard.weekly_activity')
                        </h2>

                        <p>
                            @lang('pharmacy.dashboard.weekly_activity_desc')
                        </p>

                    </div>

                </div>

                <div class="live-badge">

                    <span class="live-dot"></span>

                    @lang('pharmacy.dashboard.live')

                </div>

            </div>


            <div class="chart-legend-mobile">

        <span>
            <i class="legend-line orders"></i>
            @lang('pharmacy.dashboard.orders')
        </span>

                <span>
            <i class="legend-line ratings"></i>
            @lang('pharmacy.dashboard.latest_ratings')
        </span>

            </div>


            <div class="chart-container">

                <canvas id="pharmacyActivityChart"></canvas>

            </div>

        </section>


        {{-- =========================================================
             MEDICINES
        ========================================================== --}}
        <section class="dashboard-card medicines-card dashboard-animate delay-5">

            <div class="card-header">

                <div class="section-title">

                    <div class="section-icon animated-icon">
                        <i class="fas fa-pills"></i>
                    </div>

                    <div>

                        <h2>
                            @lang('pharmacy.dashboard.your_medicines')
                        </h2>

                        <p>
                            @lang('pharmacy.dashboard.your_medicines_desc')
                        </p>

                    </div>

                </div>

                <a
                    href="{{ route('pharmacy.medicines.index') }}"
                    class="view-all-btn"
                >
                    عرض الكل
                    <i class="fas fa-arrow-left"></i>
                </a>

            </div>


            <div class="table-wrapper">

                <table class="medicine-table">

                    <thead>

                    <tr>
                        <th>@lang('pharmacy.dashboard.col_medicine')</th>
                        <th>@lang('pharmacy.dashboard.col_price')</th>
                        <th>@lang('pharmacy.dashboard.col_quantity')</th>
                        <th>@lang('pharmacy.dashboard.col_status')</th>
                        <th>@lang('pharmacy.dashboard.col_actions')</th>
                    </tr>

                    </thead>

                    <tbody>

                    @forelse ($pharmacyMedicines as $pharmacyMedicine)

                        <tr>

                            {{-- Medicine --}}
                            <td>

                                <div class="medicine-name">

                                    <div class="medicine-icon">
                                        <i class="fas fa-capsules"></i>
                                    </div>

                                    <div>

                                        <strong>
                                            {{ $pharmacyMedicine->medicine->trade_name }}
                                        </strong>

                                        @if(isset($pharmacyMedicine->medicine->scientific_name))

                                            <small>
                                                {{ $pharmacyMedicine->medicine->scientific_name }}
                                            </small>

                                        @endif

                                    </div>

                                </div>

                            </td>


                            {{-- Price --}}
                            <td>

                                <div class="price-wrapper">

                                    <strong>
                                        {{ number_format($pharmacyMedicine->price) }}
                                    </strong>

                                    <small>
                                        ل.س
                                    </small>

                                </div>

                            </td>


                            {{-- Stock --}}
                            <td>

                                <div
                                    class="stock-number
                            {{ $pharmacyMedicine->quantity <= 10 ? 'low-stock' : '' }}"
                                >

                            <span>
                                {{ $pharmacyMedicine->quantity }}
                            </span>

                                    <small>
                                        @lang('pharmacy.dashboard.unit')
                                    </small>

                                </div>

                            </td>


                            {{-- Status --}}
                            <td>

                                @if ($pharmacyMedicine->is_available)

                                    <span class="availability available">

                                <span class="availability-dot"></span>

                                @lang('pharmacy.common.available')

                            </span>

                                @else

                                    <span class="availability unavailable">

                                <span class="availability-dot"></span>

                                @lang('pharmacy.common.unavailable')

                            </span>

                                @endif

                            </td>


                            {{-- Actions --}}
                            <td>

                                <a
                                    href="{{ route(
                                'pharmacy.medicines.edit',
                                $pharmacyMedicine->id
                            ) }}"
                                    class="edit-btn"
                                >

                                    <i class="fas fa-pen"></i>

                                    @lang('pharmacy.common.edit')

                                </a>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="5">

                                <div class="empty-state">

                                    <div class="empty-icon">
                                        <i class="fas fa-box-open"></i>
                                    </div>

                                    <h3>
                                        @lang('pharmacy.dashboard.empty_title')
                                    </h3>

                                    <p>
                                        @lang('pharmacy.dashboard.empty_desc')
                                    </p>

                                    <a
                                        href="{{ route('pharmacy.medicines.index') }}"
                                        class="manage-btn"
                                    >
                                        @lang('pharmacy.dashboard.add_medicine')
                                    </a>

                                </div>

                            </td>

                        </tr>

                    @endforelse

                    </tbody>

                </table>

            </div>


            @if($pharmacyMedicines->hasPages())

                <div class="pagination-wrapper">
                    {{ $pharmacyMedicines->links() }}
                </div>

            @endif

        </section>


        {{-- =========================================================
             RATINGS
        ========================================================== --}}
        <section class="dashboard-card ratings-card dashboard-animate delay-6">

            <div class="card-header">

                <div class="section-title">

                    <div class="section-icon animated-icon">
                        <i class="fas fa-comment-dots"></i>
                    </div>

                    <div>

                        <h2>
                            @lang('pharmacy.dashboard.latest_ratings')
                        </h2>

                        <p>
                            @lang('pharmacy.dashboard.latest_ratings_desc')
                        </p>

                    </div>

                </div>

                <a
                    href="{{ route('pharmacy.ratings.index') }}"
                    class="view-all-btn"
                >

                    @lang('pharmacy.dashboard.view_all')

                    <i class="fas fa-arrow-left"></i>

                </a>

            </div>


            <div class="ratings-grid">

                @forelse ($latestRatings as $rating)

                    <article class="rating-item">

                        <div class="rating-header">

                            <div class="user-info">

                                <div class="avatar">
                                    {{ mb_substr($rating->user->name ?? 'م', 0, 1) }}
                                </div>

                                <div class="user-details">

                                    <strong>
                                        {{ $rating->user->name ?? __('pharmacy.dashboard.anonymous_user') }}
                                    </strong>

                                    <span>
                                @lang('pharmacy.dashboard.pharmacy_client')
                            </span>

                                </div>

                            </div>

                            <span class="rating-date">
                        {{ $rating->created_at->diffForHumans() }}
                    </span>

                        </div>


                        <div class="rating-stars">

                            @for ($i = 1; $i <= 5; $i++)

                                <i
                                    class="{{ $i <= $rating->rating
                                ? 'fas fa-star'
                                : 'far fa-star empty-star'
                            }}"
                                ></i>

                            @endfor

                        </div>


                        <div class="rating-comment">

                            <i class="fas fa-quote-right"></i>

                            <p>
                                {{ $rating->comment }}
                            </p>

                        </div>

                    </article>

                @empty

                    <div class="empty-ratings">

                        <div class="empty-icon">
                            <i class="far fa-comment-alt"></i>
                        </div>

                                    <h3>
                                        @lang('pharmacy.dashboard.no_ratings_title')
                                    </h3>

                                    <p>
                                        @lang('pharmacy.dashboard.no_ratings_desc')
                                    </p>

                    </div>

                @endforelse

            </div>

        </section>

    </div>

    {{-- =============================================================
    DASHBOARD CSS
    ============================================================= --}}



    {{-- =============================================================
    CHART.JS
    ============================================================= --}}

    @push('scripts')

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>




    @endpush

@endsection
