@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
    @vite(['resources/css/pages/medicines.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('pharmacy.alternatives.index.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.alternatives.index.card_title')</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('pharmacy.alternatives.create') }}" class="btn-add-pharmacy" style="text-decoration: none;">
                    <svg class="btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>@lang('pharmacy.alternatives.index.add_button')</span>
                </a>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('pharmacy.dashboard.index') }}">@lang('pharmacy.medicines.index.breadcrumb_dashboard')</a>
            <span>‹</span>
            <span>@lang('pharmacy.alternatives.index.card_title')</span>
        </div>

        @if (session('success'))
            <div class="alert-message success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert-message error">{{ session('error') }}</div>
        @endif

        <!-- 3. Table Card -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('pharmacy.alternatives.index.card_title')</h3>
            </div>

            <table class="custom-table" id="dataTable">
                <thead>
                <tr>
                    <th>@lang('pharmacy.alternatives.index.col_num')</th>
                    <th>@lang('pharmacy.alternatives.index.col_medicine')</th>
                    <th>@lang('pharmacy.alternatives.index.col_ingredient')</th>
                    <th>@lang('pharmacy.alternatives.index.col_alternatives')</th>
                    <th style="text-align: center;">@lang('pharmacy.alternatives.index.col_actions')</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($pharmacyMedicines as $pharmacyMedicine)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $pharmacyMedicine->medicine->trade_name }}</strong></td>
                        <td>{{ $pharmacyMedicine->medicine->active_ingredient }}</td>
                        <td>
                            @forelse ($pharmacyMedicine->medicine->alternatives as $alternative)
                                <span class="pill-badge badge-category">
                                    {{ $alternative->trade_name }}
                                    <form action="{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pharmacyMedicine->id, 'alternative' => $alternative->id]) }}" method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('@lang('pharmacy.alternatives.index.confirm_delete')');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" style="background: none; border: none; color: #dc2626; cursor: pointer; padding: 0 4px; font-size: 13px; line-height: 1;" title="@lang('pharmacy.alternatives.index.delete_tooltip')">✕</button>
                                    </form>
                                </span>
                            @empty
                                @lang('pharmacy.alternatives.index.no_alternatives')
                            @endforelse
                        </td>
                        <td style="text-align: center;">
                            <div class="action-btn-group">
                                <a href="{{ route('pharmacy.alternatives.create', ['pharmacyMedicine' => $pharmacyMedicine->id]) }}" class="action-btn edit" title="@lang('pharmacy.alternatives.index.add_alternative')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 24px; color: #94a3b8;">
                            @lang('pharmacy.alternatives.index.empty')
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
