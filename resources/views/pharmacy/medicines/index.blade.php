@extends('layouts.app')

@section('title', __('pharmacy.medicines.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @php
        $total = $pharmacyMedicines->total();
        $available = $availableCount ?? 0;
        $low = $lowCount ?? 0;
        $out = $outCount ?? 0;

        $statusText = fn($status) => $status === 'ok' ? __('pharmacy.status.available') : ($status === 'low' ? __('pharmacy.status.low') : __('pharmacy.status.out'));
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.medicines.index.heading_page')</h1>
                <p>@lang('pharmacy.medicines.index.subtitle_page')</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> @lang('pharmacy.medicines.index.add_medicine')</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>@lang('pharmacy.status.out')</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>@lang('pharmacy.status.low_stock')</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>@lang('pharmacy.status.available')</span></div></div>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>@lang('pharmacy.dashboard.stat_total')</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-tabs' data-ph-tabs='.ph-medicine-table'>
                <button class='ph-tab active' data-filter='all'>@lang('pharmacy.status.all')</button>
                <button class='ph-tab' data-filter='out'>@lang('pharmacy.status.out')</button>
                <button class='ph-tab' data-filter='low'>@lang('pharmacy.status.low')</button>
                <button class='ph-tab' data-filter='ok'>@lang('pharmacy.status.available')</button>
            </div>
            <div class='ph-search'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='@lang('pharmacy.medicines.index.search_placeholder')' data-ph-search='.ph-medicine-table tbody tr'>
            </div>
        </div>

        <div class='ph-card ph-medicine-table'>
            <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                <table class='ph-table'>
                    <thead>
                        <tr>
                            <th>@lang('pharmacy.medicines.index.col_medicine')</th>
                            <th>@lang('pharmacy.medicines.index.col_ingredient')</th>
                            <th>@lang('pharmacy.medicines.index.col_price')</th>
                            <th>@lang('pharmacy.medicines.index.col_quantity')</th>
                            <th>@lang('pharmacy.medicines.index.col_status')</th>
                            <th>@lang('pharmacy.medicines.index.col_actions')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pharmacyMedicines as $pm)
                            @php
                                $q = $pm->quantity;
                                $status = $q <= 0 ? 'out' : ($q <= 10 ? 'low' : 'ok');
                            @endphp
                            <tr data-status='{{ $status }}' data-min='10'>
                                <td>
                                    <div style='display:flex;align-items:center;gap:12px;'>
                                        <div class='ph-med-thumb'>
                                            @if($pm->medicine->image)
                                                <img src='{{ \App\Support\Image::url($pm->medicine->image) }}' alt='{{ $pm->medicine->trade_name }}'>
                                            @else
                                                <i class='fas fa-pills'></i>
                                            @endif
                                        </div>
                                        <div>
                                            <strong>{{ $pm->medicine->trade_name }}</strong><br>
                                            <small style='color:var(--ph-ink-faint);'>{{ $pm->medicine->strength ?? '' }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $pm->medicine->active_ingredient }}</td>
                                <td>{{ number_format($pm->price, 2) }} @lang('pharmacy.currency')</td>
                                <td>{{ $q }}</td>
                                <td><span class='ph-badge {{ $status }}'>{{ $statusText($status) }}</span></td>
                                <td>
                                    <div style='display:flex;gap:8px;'>
                                        <a href='{{ route('pharmacy.medicines.edit', $pm->id) }}' class='ph-btn icon outline' title='@lang('pharmacy.medicines.index.edit_tooltip')'><i class='fas fa-pen'></i></a>
                                        <form action='{{ route('pharmacy.medicines.destroy', $pm->id) }}' method='POST' onsubmit='return confirm("@lang('pharmacy.medicines.index.delete_confirm')");' style='display:inline;'>
                                            @csrf
                                            @method('DELETE')
                                            <button type='submit' class='ph-btn icon danger' title='@lang('pharmacy.medicines.index.delete_tooltip')'><i class='fas fa-trash'></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan='6'><div class='ph-empty'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.medicines.index.empty_title')</h3><p>@lang('pharmacy.medicines.index.empty_desc')</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($pharmacyMedicines->hasPages())
                <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>{{ $pharmacyMedicines->links() }}</div>
            @endif
        </div>
    </div>
@endsection