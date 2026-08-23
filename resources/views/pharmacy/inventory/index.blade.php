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
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>@lang('pharmacy.inventory.stat_out')</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>@lang('pharmacy.inventory.stat_low')</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>@lang('pharmacy.inventory.stat_available')</span></div></div>
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

        <form action='{{ route('pharmacy.inventory.update') }}' method='POST'>
            @csrf
            @method('PUT')
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-boxes-stacked'></i> @lang('pharmacy.inventory.update_title')</h2></div>
                <div class='ph-card-body ph-table-wrap'>
                    <table class='ph-table'>
                        <thead><tr><th>@lang('pharmacy.inventory.col_medicine')</th><th>@lang('pharmacy.inventory.col_status')</th><th>@lang('pharmacy.inventory.col_min_stock')</th><th>@lang('pharmacy.inventory.col_current')</th><th>@lang('pharmacy.inventory.col_edit')</th></tr></thead>
                        <tbody>
                            @forelse($items as $item)
                                @php
                                    $q = $item->quantity;
                                    $min = $item->min_stock ?? 10;
                                    $status = $q <= 0 ? 'out' : ($q <= $min ? 'low' : 'ok');
                                @endphp
                                <tr data-status='{{ $status }}' data-min='{{ $min }}'>
                                    <td><strong>{{ $item->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $item->medicine->active_ingredient }}</small></td>
                                    <td><span class='ph-badge {{ $status }}'>{{ $statusText($status) }}</span></td>
                                    <td>{{ $min }}</td>
                                    <td>{{ $q }}</td>
                                    <td>
                                        <div class='ph-stepper'>
                                            <button type='button' class='dec'><i class='fas fa-minus'></i></button>
                                            <input type='number' name='quantities[{{ $item->id }}]' value='{{ $q }}' min='0'>
                                            <button type='button' class='inc'><i class='fas fa-plus'></i></button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan='5'><div class='ph-empty'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.inventory.empty')</h3></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($items->count())
                    <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>
                        <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> @lang('pharmacy.inventory.save_button')</button>
                    </div>
                @endif
            </div>
        </form>
    </div>
@endsection