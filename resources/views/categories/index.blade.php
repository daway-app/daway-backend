@extends('layouts.app')

@section('title', __('categories.title'))

@section('content')
    @vite(['resources/css/pages/medicines.css', 'resources/css/pages/categories.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('categories.main_heading')</h1>
                <p>@lang('categories.main_description')</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('categories.create') }}" class="btn-add-pharmacy hover-shimmer">
                    <svg class="btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>@lang('categories.add_button')</span>
                </a>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('categories.breadcrumb_main')</a>
            <span>‹</span>
            <span>@lang('categories.breadcrumb_current')</span>
        </div>

        <!-- Flash Messages -->
        @if(session('success'))
            <div style="background:#d1fae5;color:#059669;border:1px solid #a7f3d0;border-radius:12px;padding:12px 18px;margin-bottom:16px;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div style="background:#ffe4e6;color:#be123c;border:1px solid #fecdd3;border-radius:12px;padding:12px 18px;margin-bottom:16px;">{{ session('error') }}</div>
        @endif

        <!-- 3. Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-title">@lang('categories.stat_total')</span>
                <span class="stat-value total">{{ $stats['total'] }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('categories.stat_active')</span>
                <span class="stat-value available">{{ $stats['active'] }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('categories.stat_links')</span>
                <span class="stat-value low">{{ $stats['links'] }}</span>
            </div>
            <a href="{{ $stats['needs_review'] > 0 ? route('categories.index') . '?q=' : '#' }}" class="stat-card stat-card-link @if($stats['needs_review'] > 0) stat-card-danger @endif">
                <span class="stat-title">@lang('categories.stat_needs_review')</span>
                <span class="stat-value {{ $stats['needs_review'] > 0 ? 'danger' : 'low' }}">{{ $stats['needs_review'] }}</span>
            </a>
        </div>

        <!-- 4. Search Filter (Debounced) -->
        <div class="filter-card">
            <div class="filter-row">
                <input type="text" id="search-input" value="{{ $q }}" placeholder="@lang('categories.search_placeholder')" class="filter-input" autocomplete="off">
                <span id="search-indicator" style="display:none;color:#1C72A6;font-size:13px;font-weight:600;">بحث...</span>
            </div>
        </div>

        <!-- 5. Table Card -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('categories.main_heading')</h3>
                <span>{{ $categories->total() }}</span>
            </div>

            <table class="custom-table">
                <thead>
                <tr>
                    <th>@lang('categories.col_category')</th>
                    <th>@lang('categories.col_status')</th>
                    <th>@lang('categories.col_sort')</th>
                    <th>@lang('categories.col_medicines_count')</th>
                    <th>@lang('categories.col_needs_review')</th>
                    <th style="text-align: center;">@lang('categories.col_action')</th>
                </tr>
                </thead>
                <tbody id="categories-tbody">
                @include('categories._row', ['categories' => $categories])
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $categories->links() }}
            </div>
        </div>
    </div>

    <script>
    (function() {
        var input = document.getElementById('search-input');
        if (!input) return;
        var indicator = document.getElementById('search-indicator');
        var timer = null;
        var currentQ = '{{ addslashes($q) }}';

        input.addEventListener('input', function() {
            var val = input.value.trim();
            if (val === currentQ) return;
            currentQ = val;
            if (timer) clearTimeout(timer);
            if (indicator) indicator.style.display = 'inline';

            timer = setTimeout(function() {
                var url = '{{ route('categories.index') }}';
                var params = val ? '?q=' + encodeURIComponent(val) : '';
                window.location.href = url + params;
            }, 350);
        });
    })();
    </script>
@endsection
