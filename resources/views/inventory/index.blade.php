@extends('layouts.app')

@section('title', __('inventory.title'))

@section('content')
    @vite(['resources/css/inventory.css'])

    <div>
        <!-- 1. كروت الإحصائيات -->
        <div class="stats-container">
            <div class="stat-item">
                <div class="stat-icon" style="background-color: #e0f2f1; color: #00657A;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                <div class="stat-details">
                    <div class="label">@lang('inventory.total_items')</div>
                    <div class="value">{{ $stockSummary->totalItems ?? 0 }}</div>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background-color: #e8f5e9; color: #2e7d32;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
                <div class="stat-details">
                    <div class="label">@lang('inventory.in_stock')</div>
                    <div class="value">{{ $stockSummary->inStock ?? 0 }}</div>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background-color: #fffde7; color: #f9a825;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line><circle cx="12" cy="12" r="10"></circle></svg></div>
                <div class="stat-details">
                    <div class="label">@lang('inventory.low_stock')</div>
                    <div class="value">{{ $stockSummary->lowStock ?? 0 }}</div>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background-color: #ffebee; color: #c62828;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg></div>
                <div class="stat-details">
                    <div class="label">@lang('inventory.out_of_stock')</div>
                    <div class="value">{{ $stockSummary->outOfStock ?? 0 }}</div>
                </div>
            </div>
        </div>

        <!-- 2. بطاقة الجدول -->
        <div class="table-wrapper-card">
            <div class="table-header">
                <h2 class="table-title">@lang('inventory.table_title')</h2>
            </div>
            <div class="table-responsive-container">
                <table class="inventory-table">
                    <thead>
                    <tr>
                        <th>@lang('inventory.medicine')</th>
                        <th>@lang('inventory.total_quantity')</th>
                        <th>@lang('inventory.pharmacies_count')</th>
                        <th>@lang('inventory.status')</th>
                        <th>@lang('inventory.actions')</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($medicines as $medicine)
                        <tr>
                            <td>
                                <div class="medicine-cell">
                                    <div class="medicine-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                    </div>
                                    <div class="medicine-details">
                                        <div class="name">{{ $medicine->trade_name }}</div>
                                        <div class="scientific">{{ $medicine->scientific_name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="quantity-text">{{ $medicine->pharmacy_medicines_sum_quantity ?? 0 }} @lang('inventory.unit')</span>
                            </td>
                            <td>
                                <span class="pharmacies-count">{{ $medicine->pharmacy_medicines_count }} @lang('inventory.pharmacy')</span>
                            </td>
                            <td>
                                @php
                                    $quantity = $medicine->pharmacy_medicines_sum_quantity;
                                    $status = 'out-of-stock';
                                    if ($quantity > 20) {
                                        $status = 'available';
                                    } elseif ($quantity > 0) {
                                        $status = 'low-stock';
                                    }
                                @endphp
                                <span class="status-badge {{ $status }}">
                                        <span class="dot"></span>
                                        @lang('inventory.status_' . $status)
                                    </span>
                            </td>
                            <td>
                                <button class="action-button">@lang('inventory.view_details')</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem;">@lang('inventory.no_medicines')</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. روابط التنقل -->
        <div style="margin-top: 1.5rem;">
            {{ $medicines->links() }}
        </div>
    </div>
@endsection
