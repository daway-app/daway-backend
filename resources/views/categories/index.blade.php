@extends('layouts.app')

@section('title', __('categories.title'))

@section('content')
    @vite(['resources/css/pages/medicines.css'])

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
        </div>

        <!-- 4. Search Filter -->
        <div class="filter-card">
            <form method="GET" action="{{ route('categories.index') }}">
                <div class="filter-row">
                    <input type="text" name="q" value="{{ $q }}" placeholder="@lang('categories.search_placeholder')" class="filter-input">
                    <button type="submit" style="padding:10px 22px;border:none;border-radius:12px;background:#1C72A6;color:#ffffff;cursor:pointer;font-weight:600;font-family:inherit;">@lang('categories.search_button')</button>
                </div>
            </form>
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
                    <th style="text-align: center;">@lang('categories.col_action')</th>
                </tr>
                </thead>
                <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                @if($category->image)
                                    <img src="{{ \App\Support\Image::url($category->image) }}" alt="{{ $category->name_ar }}" width="42" height="42" style="width:42px;height:42px;object-fit:cover;border-radius:10px;">
                                @else
                                    <div class="pill-icon-wrapper icon-cyan"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></div>
                                @endif
                                <div>
                                    <strong>{{ $category->name_ar }}</strong><br>
                                    <small style="color: #94a3b8; font-size: 11px;">{{ $category->name_en }} • {{ $category->slug }}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <form action="{{ route('categories.toggleStatus', $category->id) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="pill-badge status-badge {{ $category->is_active ? 'available' : 'out' }}" style="border:none;cursor:pointer;font-family:inherit;">● {{ $category->is_active ? __('categories.status_active') : __('categories.status_inactive') }}</button>
                            </form>
                        </td>
                        <td>{{ $category->sort_order }}</td>
                        <td><strong style="color: #1C72A6;">{{ $category->category_medicine_links_count }}</strong></td>
                        <td>
                            <div class="action-btn-group">
                                <a href="{{ route('categories.show', $category->id) }}" class="action-btn" title="@lang('categories.tooltip_manage')" style="color: #1C72A6;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>
                                <a href="{{ route('categories.edit', $category->id) }}" class="action-btn edit" title="@lang('categories.tooltip_edit')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></a>
                                <form action="{{ route('categories.destroy', $category->id) }}" method="POST" onsubmit="return confirm('@lang('categories.delete_confirm')');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete" title="@lang('categories.tooltip_delete')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 24px; color: #94a3b8;">
                            @lang('categories.no_categories_found')
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $categories->links() }}
            </div>
        </div>
    </div>
@endsection
