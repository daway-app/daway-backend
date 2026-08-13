@extends('layouts.app')

@section('title', __('pharmacy.medicines.index.title'))

@section('content')
    @vite(['resources/css/pages/medicines.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('pharmacy.medicines.index.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.medicines.index.subtitle')</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('pharmacy.medicines.create') }}" class="btn-add-medicine">
                    <span>+</span> @lang('pharmacy.medicines.index.add_medicine')
                </a>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('pharmacy.dashboard.index') }}">@lang('pharmacy.medicines.index.breadcrumb_dashboard')</a>
            <span>‹</span>
            <span>@lang('pharmacy.medicines.index.breadcrumb_current')</span>
        </div>

        @if (session('success'))
            <div class="alert-message success"> {{ session('success') }} </div>
        @endif
        @if (session('error'))
            <div class="alert-message error"> {{ session('error') }} </div>
        @endif

        <!-- 3. Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-title">@lang('pharmacy.medicines.index.total_medicines')</span>
                <span class="stat-value total">{{ $pharmacyMedicines->total() }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('pharmacy.medicines.index.available_count')</span>
                <span class="stat-value available">{{ $availableCount }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-title">@lang('pharmacy.medicines.index.out_of_stock')</span>
                <span class="stat-value out">{{ $outCount }}</span>
            </div>
        </div>

        <!-- 4. Table Card -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('pharmacy.medicines.index.table_heading')</h3>
                <span>@lang('pharmacy.medicines.index.total_badge', ['count' => $pharmacyMedicines->total()])</span>
            </div>

            <table class="custom-table" id="medicinesTable">
                <thead>
                <tr>
                    <th>@lang('pharmacy.medicines.index.col_num')</th>
                    <th>@lang('pharmacy.medicines.index.col_medicine')</th>
                    <th>@lang('pharmacy.medicines.index.col_price')</th>
                    <th>@lang('pharmacy.medicines.index.col_quantity')</th>
                    <th>@lang('pharmacy.medicines.index.col_status')</th>
                    <th style="text-align: center;">@lang('pharmacy.medicines.index.col_actions')</th>
                </tr>
                </thead>
                <tbody>
                @forelse($pharmacyMedicines as $pharmacyMedicine)
                    <tr data-status="{{ $pharmacyMedicine->is_available ? 'available' : 'unavailable' }}">
                        <td>{{ $pharmacyMedicines->firstItem() + $loop->index }}</td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="pill-icon-wrapper icon-cyan"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></div>
                                <div>
                                    <strong>{{ $pharmacyMedicine->medicine->trade_name }}</strong><br>
                                    <small style="color: #94a3b8; font-size: 11px;">{{ $pharmacyMedicine->medicine->active_ingredient }}</small>
                                </div>
                            </div>
                        </td>
                        <td><strong style="color: #0B8FAC;">@lang('pharmacy.medicines.index.currency') {{ number_format($pharmacyMedicine->price, 2) }}</strong></td>
                        <td>{{ $pharmacyMedicine->quantity }}</td>
                        <td>
                            @if ($pharmacyMedicine->is_available)
                                <span class="pill-badge status-badge available">● @lang('pharmacy.common.available')</span>
                            @else
                                <span class="pill-badge status-badge unavailable">● @lang('pharmacy.common.unavailable')</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-btn-group">
                                <a href="{{ route('pharmacy.medicines.edit', $pharmacyMedicine->id) }}" class="action-btn edit" title="@lang('pharmacy.medicines.index.edit_tooltip')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></a>
                                <form action="{{ route('pharmacy.medicines.destroy', $pharmacyMedicine->id) }}" method="POST" onsubmit="return confirm('@lang('pharmacy.medicines.index.delete_confirm')');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete" title="@lang('pharmacy.medicines.index.delete_tooltip')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
                            @lang('pharmacy.medicines.index.empty')
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div class="pagination-wrapper">
                {{ $pharmacyMedicines->links() }}
            </div>
        </div>
    </div>
@endsection
