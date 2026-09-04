@extends('layouts.app')

@section('title', __('pharmacy.inventory.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @push('scripts')
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    @endpush

    @php
        $available = $available ?? 0;
        $low = $low ?? 0;
        $out = $out ?? 0;
        $total = $available + $low + $out;
        $pct = fn($n) => $total > 0 ? round(($n / $total) * 100, 1) : 0;
        $labels = json_encode($trendLabels ?? []);
        $data = json_encode($trendData ?? []);

        $statusText = fn($status) => $status === 'ok' ? __('pharmacy.inventory.status_available') : ($status === 'low' ? __('pharmacy.inventory.status_low') : __('pharmacy.inventory.status_out'));

        $statusLabels = json_encode([
            __('pharmacy.status.available'),
            __('pharmacy.status.low_stock'),
            __('pharmacy.status.out'),
        ]);
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.inventory.heading')</h1>
                <p>@lang('pharmacy.inventory.subtitle')</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> @lang('pharmacy.inventory.add_medicine')</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>@lang('pharmacy.dashboard.stat_total')</span></div><span class='ph-stat-progress'></span></div>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>@lang('pharmacy.inventory.stat_out')</span></div><span class='ph-stat-progress'></span></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>@lang('pharmacy.inventory.stat_low')</span></div><span class='ph-stat-progress'></span></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>@lang('pharmacy.inventory.stat_available')</span></div><span class='ph-stat-progress'></span></div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-chart-pie'></i> @lang('pharmacy.inventory.chart_status_title')</h2>
                    <p>@lang('pharmacy.inventory.chart_status_desc')</p>
                </div>
                <div class='ph-card-body'>
                    <div class='ph-donut-wrap'>
                        <div class='ph-donut-chart'>
                            <canvas data-ph-chart='donut' data-ph-legend='off'
                                data-ph-center-value='{{ $total }}'
                                data-ph-center-label='{{ __('pharmacy.inventory.center_total') }}'
                                data-ph-labels='{{ $statusLabels }}'
                                data-ph-data='[{{ $available }},{{ $low }},{{ $out }}]'
                                data-ph-colors='["#16A34A","#CA8A04","#DC2626"]'></canvas>
                        </div>
                        <ul class='ph-legend'>
                            <li><span class='dot' style='background:#16A34A'></span> @lang('pharmacy.inventory.legend_available') <b>{{ $pct($available) }}%</b></li>
                            <li><span class='dot' style='background:#CA8A04'></span> @lang('pharmacy.inventory.legend_low') <b>{{ $pct($low) }}%</b></li>
                            <li><span class='dot' style='background:#DC2626'></span> @lang('pharmacy.inventory.legend_out') <b>{{ $pct($out) }}%</b></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-chart-line'></i> @lang('pharmacy.inventory.trend_title')</h2>
                    <p>@lang('pharmacy.inventory.trend_desc')</p>
                </div>
                <div class='ph-card-body'><div class='chart-box'><canvas data-ph-chart='line' data-ph-labels='{{ $labels }}' data-ph-data='{{ $data }}'></canvas></div></div>
            </div>
        </div>

        <form method='GET' action='{{ route('pharmacy.inventory.index') }}' class='ph-filters'>
            @if(($q ?? '') !== '')
                <input type='hidden' name='q' value='{{ $q }}'>
            @endif
            <div class='ph-tabs' data-ph-tabs='.ph-inventory-table'>
                <button type='submit' name='status' value='all' class='ph-tab {{ ($status ?? 'all') === 'all' ? 'active' : '' }}' data-filter='all'>@lang('pharmacy.inventory.status_all')</button>
                <button type='submit' name='status' value='ok' class='ph-tab {{ ($status ?? 'all') === 'ok' ? 'active' : '' }}' data-filter='ok'>@lang('pharmacy.inventory.status_available')</button>
                <button type='submit' name='status' value='low' class='ph-tab {{ ($status ?? 'all') === 'low' ? 'active' : '' }}' data-filter='low'>@lang('pharmacy.inventory.status_low')</button>
                <button type='submit' name='status' value='out' class='ph-tab {{ ($status ?? 'all') === 'out' ? 'active' : '' }}' data-filter='out'>@lang('pharmacy.inventory.status_out')</button>
            </div>
            <div class='ph-search' style='flex:1;max-width:340px;'>
                <i class='fas fa-search'></i>
                <input type='text' name='q' value='{{ $q ?? '' }}' placeholder='@lang('pharmacy.inventory.search_placeholder')' autocomplete='off'>
            </div>
            @if(($q ?? '') !== '' || ($status ?? 'all') !== 'all')
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn ghost'><i class='fas fa-xmark'></i> @lang('pharmacy.inventory.clear_filters')</a>
            @endif
        </form>

        <form action='{{ route('pharmacy.inventory.update') }}' method='POST' data-offline-form='inventory'>
            @csrf
            @method('PUT')
            <div class='ph-card ph-inventory-table' data-offline-page='inventory'>
                <div class='ph-card-head'><h2><i class='fas fa-boxes-stacked'></i> @lang('pharmacy.inventory.update_title')</h2></div>
                <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                    <table class='ph-table'>
                        <thead><tr><th>@lang('pharmacy.inventory.col_medicine')</th><th>@lang('pharmacy.inventory.col_status')</th><th>@lang('pharmacy.inventory.col_current')</th><th>@lang('pharmacy.inventory.col_edit')</th></tr></thead>
                        <tbody>
                            @forelse($items as $item)
                                @php
                                    $qty = $item->quantity;
                                    $status = $qty <= 0 ? 'out' : ($qty <= 10 ? 'low' : 'ok');
                                @endphp
                                <tr data-status='{{ $status }}' data-min='10'>
                                    <td><strong>{{ $item->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $item->medicine->active_ingredient }}</small></td>
                                    <td><span class='ph-badge {{ $status }}'>{{ $statusText($status) }}</span></td>
                                    <td>{{ $qty }}</td>
                                    <td>
                                        <div class='ph-stepper'>
                                            <button type='button' class='dec'><i class='fas fa-minus'></i></button>
                                            <input type='number' name='quantities[{{ $item->id }}]' value='{{ $qty }}' min='0'>
                                            <button type='button' class='inc'><i class='fas fa-plus'></i></button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan='4'><div class='ph-empty'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.inventory.empty')</h3></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($items->isEmpty() && $all->isNotEmpty())
                    <div class='ph-empty' style='padding:24px;'>
                        <i class='fas fa-magnifying-glass'></i>
                        <h3>@lang('pharmacy.inventory.no_results')</h3>
                    </div>
                @endif
                @if($items->count())
                    <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>
                        <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> @lang('pharmacy.inventory.save_button')</button>
                    </div>
                @endif
            </div>
        </form>
    </div>

    {{-- بيانات المخزون للعمل بدون اتصال (offline hydration payload) --}}
    @php
        $offlineInventory = collect($items ?? collect())->map(fn ($item) => [
            'id' => $item->id,
            'medicine_id' => $item->medicine_id,
            'quantity' => $item->quantity,
            'trade_name' => $item->medicine->trade_name ?? '',
            'active_ingredient' => $item->medicine->active_ingredient ?? '',
            'updated_at' => optional($item->updated_at)->toIso8601String(),
        ])->values()->all();
    @endphp
    <script id='daway-offline-inventory' type='application/json'>@json($offlineInventory)</script>
@endsection