@extends('layouts.app')

@section('title', __('categories.show_title', ['name' => $category->name_ar]))

@section('content')
    @vite(['resources/css/pages/medicines.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section" style="display:flex;align-items:center;gap:14px;">
                @if($category->image)
                    <img src="{{ \App\Support\Image::url($category->image) }}" alt="{{ $category->name_ar }}" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:14px;">
                @endif
                <div>
                    <h1>{{ $category->name_ar }}</h1>
                    <p>{{ $category->name_en }} • {{ $category->slug }}</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="{{ route('categories.index') }}" class="btn-add-pharmacy hover-shimmer" style="background:#e2e8f0;color:#334155;">
                    <span>@lang('categories.back_button')</span>
                </a>
                <a href="{{ route('categories.edit', $category->id) }}" class="btn-add-pharmacy hover-shimmer">
                    <svg class="btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    <span>@lang('categories.edit_button')</span>
                </a>
                <form action="{{ route('categories.toggleStatus', $category->id) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn-add-pharmacy hover-shimmer" style="{{ $category->is_active ? 'background:#e11d48;color:#ffffff;' : 'background:#10b981;color:#ffffff;' }}">
                        <span>{{ $category->is_active ? __('categories.toggle_disable') : __('categories.toggle_enable') }}</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('categories.breadcrumb_main')</a>
            <span>‹</span>
            <a href="{{ route('categories.index') }}">@lang('categories.breadcrumb_current')</a>
            <span>‹</span>
            <span>@lang('categories.breadcrumb_current_show')</span>
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
                <span class="stat-title">@lang('categories.stat_links_total')</span>
                <span class="stat-value total">{{ $stats['links'] }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('categories.stat_needs_review')</span>
                <span class="stat-value low">{{ $stats['needs_review'] }}</span>
            </div>
        </div>

        <!-- 4. Review Tabs + Search -->
        <div class="filter-card">
            <div class="filter-row" style="justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <a href="{{ route('categories.show', $category->id) }}" style="padding:8px 16px;border-radius:12px;font-size:13px;font-weight:600;text-decoration:none;background:{{ $review ? '#e2e8f0' : '#1C72A6' }};color:{{ $review ? '#334155' : '#ffffff' }};">@lang('categories.tab_all')</a>
                    <a href="{{ route('categories.show', array_merge(['category' => $category->id], ['review' => 1])) }}" style="padding:8px 16px;border-radius:12px;font-size:13px;font-weight:600;text-decoration:none;background:{{ $review ? '#1C72A6' : '#e2e8f0' }};color:{{ $review ? '#ffffff' : '#334155' }};">@lang('categories.tab_review')</a>
                </div>
                <form method="GET" action="{{ route('categories.show', $category->id) }}" style="display:flex;gap:8px;flex:1;min-width:260px;max-width:560px;">
                    <input type="hidden" name="review" value="{{ $review ? 1 : '' }}">
                    <input type="text" name="q" value="{{ $q }}" placeholder="@lang('categories.attach_search_placeholder')" class="filter-input" style="flex:1;">
                    <select name="source" class="filter-select">
                        <option value="">@lang('categories.source_filter_all')</option>
                        <option value="product_class" {{ $source === 'product_class' ? 'selected' : '' }}>@lang('categories.source_product_class')</option>
                        <option value="rules" {{ $source === 'rules' ? 'selected' : '' }}>@lang('categories.source_rules')</option>
                        <option value="admin" {{ $source === 'admin' ? 'selected' : '' }}>@lang('categories.source_admin')</option>
                    </select>
                    <button type="submit" style="padding:10px 22px;border:none;border-radius:12px;background:#1C72A6;color:#ffffff;cursor:pointer;font-weight:600;font-family:inherit;">@lang('categories.search_button')</button>
                </form>
            </div>
        </div>

        <!-- 5. Search Results (attach) -->
        @if($q !== '' && mb_strlen($q) >= 2 && $searchResults->isNotEmpty())
            <div class="main-card" style="margin-bottom:20px;">
                <div class="card-top-bar">
                    <h3>@lang('categories.search_results_title')</h3>
                    <span>@lang('categories.search_results_hint')</span>
                </div>
                <table class="custom-table">
                    <thead>
                    <tr>
                        <th>@lang('categories.col_medicine')</th>
                        <th>@lang('categories.col_type')</th>
                        <th>@lang('categories.col_product_class')</th>
                        <th style="text-align:center;">@lang('categories.col_action')</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($searchResults as $result)
                        <tr>
                            <td>
                                <strong>{{ $result['name'] }}</strong><br>
                                <small style="color: #94a3b8; font-size: 11px;">{{ $result['sub'] }}</small>
                            </td>
                            <td>
                                <span class="pill-badge {{ $result['type'] === 'moh' ? 'status-badge low' : 'status-badge available' }}">● {{ $result['type_label'] }}</span>
                            </td>
                            <td>{{ $result['class'] }}</td>
                            <td style="text-align:center;">
                                @if($result['linked'])
                                    <span class="pill-badge status-badge available">✓ @lang('categories.attached_badge')</span>
                                @else
                                    <form action="{{ route('categories.medicines.attach', $category->id) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $result['type'] }}">
                                        @if($result['type'] === 'moh')
                                            <input type="hidden" name="moh_product_id" value="{{ $result['moh_product_id'] }}">
                                            <input type="hidden" name="moh_drug_id" value="{{ $result['moh_drug_id'] }}">
                                        @else
                                            <input type="hidden" name="medicine_id" value="{{ $result['medicine_id'] }}">
                                        @endif
                                        <button type="submit" class="pill-badge status-badge available" style="border:none;cursor:pointer;font-family:inherit;">@lang('categories.attach_button')</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @elseif($q !== '' && mb_strlen($q) >= 2 && $searchResults->isEmpty())
            <div class="main-card" style="margin-bottom:20px;">
                <p style="text-align:center;padding:18px;color:#94a3b8;margin:0;">@lang('categories.search_no_results')</p>
            </div>
        @endif

        <!-- 6. Links Table -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('categories.links_table_heading')</h3>
                <span>@lang('categories.registered_links', ['count' => $links->total()])</span>
            </div>

            <table class="custom-table">
                <thead>
                <tr>
                    <th>@lang('categories.col_medicine')</th>
                    <th>@lang('categories.col_product_class')</th>
                    <th>@lang('categories.col_source')</th>
                    <th>@lang('categories.col_confidence')</th>
                    <th>@lang('categories.col_review')</th>
                    <th style="text-align:center;">@lang('categories.col_action')</th>
                </tr>
                </thead>
                <tbody>
                @forelse($links as $link)
                    @php
                        $moh = $mohByProduct->get($link->moh_product_id) ?? $mohByDrug->get($link->moh_drug_id);
                        $local = $localMedicines->get($link->medicine_id);
                        $medicineName = $moh?->trade_name ?? ($local?->trade_name ?? __('categories.unknown_medicine'));
                        $medicineSub = $moh?->generic_name ?? ($local?->active_ingredient ?? '—');
                        $productClass = $moh?->product_class ?? '—';
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $medicineName }}</strong><br>
                            <small style="color: #94a3b8; font-size: 11px;">{{ $medicineSub }}</small>
                        </td>
                        <td>{{ $productClass }}</td>
                        <td>
                            @if($link->source === \App\Models\CategoryMedicineLink::SOURCE_ADMIN)
                                <span class="pill-badge status-badge available">@lang('categories.source_admin')</span>
                            @elseif($link->source === \App\Models\CategoryMedicineLink::SOURCE_PRODUCT_CLASS)
                                <span class="pill-badge status-badge low">@lang('categories.source_product_class')</span>
                            @else
                                <span class="pill-badge" style="background:#e0f2fe;color:#0369a1;">@lang('categories.source_rules')</span>
                            @endif
                        </td>
                        <td>{{ $link->confidence !== null ? $link->confidence.'%' : '—' }}</td>
                        <td>
                            @if($link->needs_review)
                                <form action="{{ route('categories.review.approve', [$category->id, $link->id]) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pill-badge status-badge available" style="border:none;cursor:pointer;font-family:inherit;">✓ @lang('categories.approve_button')</button>
                                </form>
                            @else
                                <span class="pill-badge status-badge available">✓ @lang('categories.reviewed_badge')</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-btn-group" style="justify-content:center;">
                                <form action="{{ route('categories.medicines.detach', [$category->id, $link->id]) }}" method="POST" onsubmit="return confirm('@lang('categories.detach_confirm')');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete" title="@lang('categories.detach_button')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
                            @lang('categories.no_links_found')
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $links->links() }}
            </div>
        </div>
    </div>
@endsection
