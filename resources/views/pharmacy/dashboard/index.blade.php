@extends('layouts.app')

@section('title', __('pharmacy.dashboard.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @push('scripts')
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    @endpush

    @php
        $total = $totalMedicinesInStock ?? 0;
        $available = $availableCount ?? 0;
        $low = $lowStockCount ?? 0;
        $out = $outOfStockCount ?? 0;

        $pct = fn($n) => $total > 0 ? round(($n / $total) * 100, 1) : 0;
        $availablePct = $pct($available);
        $lowPct = $pct($low);
        $outPct = $pct($out);
        $unclassified = max($total - $available - $low - $out, 0);
        $unclassifiedPct = $pct($unclassified);

        $inquiriesList = $latestInquiries ?? collect();

        $statusLabels = json_encode([
            __('pharmacy.status.available'),
            __('pharmacy.status.low_stock'),
            __('pharmacy.status.out'),
            __('pharmacy.status.unclassified'),
        ]);
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.dashboard.heading')</h1>
                <p>@lang('pharmacy.dashboard.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> @lang('pharmacy.dashboard.add_medicine')</a>
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn outline'><i class='fas fa-rotate'></i> @lang('pharmacy.dashboard.update_inventory')</a>
            </div>
        </div>

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>@lang('pharmacy.dashboard.stat_out')</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>@lang('pharmacy.dashboard.stat_low')</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>@lang('pharmacy.dashboard.stat_available')</span></div></div>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>@lang('pharmacy.dashboard.stat_total')</span></div></div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-chart-column'></i> @lang('pharmacy.dashboard.chart_inventory_status')</h2>
                    <p>@lang('pharmacy.dashboard.chart_inventory_desc')</p>
                </div>
                <div class='ph-card-body'>
                    <div class='chart-box'>
                        <canvas data-ph-chart='bar'
                            data-ph-labels='{{ $statusLabels }}'
                            data-ph-data='[{{ $available }},{{ $low }},{{ $out }},{{ $unclassified }}]'
                            data-ph-colors='["#16A34A","#CA8A04","#DC2626","#B9C4C3"]'></canvas>
                    </div>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-clock-rotate-left'></i> @lang('pharmacy.dashboard.chart_availability')</h2>
                </div>
                <div class='ph-card-body'>
                    <div class='ph-donut-wrap'>
                        <div class='ph-donut-chart'>
                            <canvas data-ph-chart='donut' data-ph-legend='off'
                                data-ph-center-value='{{ $availablePct }}%'
                                data-ph-center-label='{{ __('pharmacy.status.available') }}'
                                data-ph-labels='{{ $statusLabels }}'
                                data-ph-data='[{{ $available }},{{ $low }},{{ $out }},{{ $unclassified }}]'
                                data-ph-colors='["#16A34A","#CA8A04","#DC2626","#B9C4C3"]'></canvas>
                        </div>
                        <ul class='ph-legend'>
                            <li><span class='dot' style='background:#16A34A'></span> @lang('pharmacy.status.available') <b>{{ $availablePct }}%</b></li>
                            <li><span class='dot' style='background:#CA8A04'></span> @lang('pharmacy.status.low_stock') <b>{{ $lowPct }}%</b></li>
                            <li><span class='dot' style='background:#DC2626'></span> @lang('pharmacy.status.out') <b>{{ $outPct }}%</b></li>
                            <li><span class='dot' style='background:#B9C4C3'></span> @lang('pharmacy.status.unclassified') <b>{{ $unclassifiedPct }}%</b></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-bell'></i> @lang('pharmacy.dashboard.low_stock_title')</h2>
                    <p>@lang('pharmacy.dashboard.low_stock_desc')</p>
                </div>
                <div class='ph-card-body ph-table-wrap'>
                    <table class='ph-table'>
                        <thead><tr><th>@lang('pharmacy.dashboard.col_medicine')</th><th>@lang('pharmacy.dashboard.col_min_stock')</th><th>@lang('pharmacy.dashboard.col_quantity_left')</th><th>@lang('pharmacy.dashboard.col_status')</th></tr></thead>
                        <tbody>
                            @forelse($lowStockItems as $pm)
                                <tr>
                                    <td><strong>{{ $pm->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $pm->medicine->active_ingredient }}</small></td>
                                    <td>{{ $pm->min_stock ?? 10 }}</td>
                                    <td>{{ $pm->quantity }}</td>
                                    <td><span class='ph-badge low'>@lang('pharmacy.dashboard.badge_low')</span></td>
                                </tr>
                            @empty
                                <tr><td colspan='4'><div class='ph-empty'><i class='fas fa-check-circle'></i><h3>@lang('pharmacy.dashboard.no_alerts')</h3><p>@lang('pharmacy.dashboard.no_alerts_desc')</p></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-comment-dots'></i> @lang('pharmacy.dashboard.latest_inquiries')</h2>
                    <p>@lang('pharmacy.dashboard.latest_inquiries_desc')</p>
                </div>
                <div class='ph-card-body'>
                    @forelse($inquiriesList as $inquiry)
                        <div class='ph-inquiry-mini'>
                            <div class='who'>
                                <strong>{{ $inquiry->user->name ?? __('pharmacy.dashboard.patient_fallback') }}</strong>
                                <span>{{ $inquiry->created_at->diffForHumans() }}</span>
                            </div>
                            <p>@lang('pharmacy.dashboard.inquiry_q', ['medicine' => $inquiry->medicine->trade_name ?? ''])</p>
                        </div>
                    @empty
                        <div class='ph-empty'><i class='far fa-comment-alt'></i><h3>@lang('pharmacy.dashboard.no_inquiries_yet')</h3></div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection