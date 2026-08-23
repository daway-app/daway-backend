@extends('layouts.app')

@section('title', __('pharmacy.dashboard.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

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
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>لوحة الصيدلية</h1>
                <p>نظرة عامة على أداء صيدلية {{ $pharmacy->pharmacy_name }}</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> إضافة دواء</a>
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn outline'><i class='fas fa-rotate'></i> تحديث المخزون</a>
            </div>
        </div>

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>نافذ</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>مخزون منخفض</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>متوفر</span></div></div>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>إجمالي الأدوية</span></div></div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-chart-column'></i> حالة المخزون</h2>
                    <p>عدد الأدوية حسب حالة المخزون</p>
                </div>
                <div class='ph-card-body'>
                    <div class='chart-box'>
                        <canvas data-ph-chart='bar'
                            data-ph-labels='["متوفر","مخزون منخفض","نافذ","غير مصنف"]'
                            data-ph-data='[{{ $available }},{{ $low }},{{ $out }},{{ $unclassified }}]'
                            data-ph-colors='["#16A34A","#CA8A04","#DC2626","#B9C4C3"]'></canvas>
                    </div>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-clock-rotate-left'></i> نسبة التوفر</h2>
                </div>
                <div class='ph-card-body'>
                    <div class='ph-donut-wrap'>
                        <div class='ph-donut-chart'>
                            <canvas data-ph-chart='donut' data-ph-legend='off'
                                data-ph-center-value='{{ $availablePct }}%'
                                data-ph-center-label='متوفر'
                                data-ph-labels='["متوفر","مخزون منخفض","نافذ","غير مصنف"]'
                                data-ph-data='[{{ $available }},{{ $low }},{{ $out }},{{ $unclassified }}]'
                                data-ph-colors='["#16A34A","#CA8A04","#DC2626","#B9C4C3"]'></canvas>
                        </div>
                        <ul class='ph-legend'>
                            <li><span class='dot' style='background:#16A34A'></span> متوفر <b>{{ $availablePct }}%</b></li>
                            <li><span class='dot' style='background:#CA8A04'></span> مخزون منخفض <b>{{ $lowPct }}%</b></li>
                            <li><span class='dot' style='background:#DC2626'></span> نافذ <b>{{ $outPct }}%</b></li>
                            <li><span class='dot' style='background:#B9C4C3'></span> غير مصنف <b>{{ $unclassifiedPct }}%</b></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-bell'></i> تنبيهات المخزون المنخفض</h2>
                    <p>الأدوية التي وصلت إلى حد المخزون المنخفض</p>
                </div>
                <div class='ph-card-body ph-table-wrap'>
                    <table class='ph-table'>
                        <thead><tr><th>الدواء</th><th>الحد الأدنى</th><th>الكمية المتبقية</th><th>الحالة</th></tr></thead>
                        <tbody>
                            @forelse($lowStockItems as $pm)
                                <tr>
                                    <td><strong>{{ $pm->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $pm->medicine->active_ingredient }}</small></td>
                                    <td>{{ $pm->min_stock ?? 10 }}</td>
                                    <td>{{ $pm->quantity }}</td>
                                    <td><span class='ph-badge low'>منخفض</span></td>
                                </tr>
                            @empty
                                <tr><td colspan='4'><div class='ph-empty'><i class='fas fa-check-circle'></i><h3>لا توجد تنبيهات</h3><p>جميع الأدوية بكميات آمنة</p></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-comment-dots'></i> آخر استفسارات المرضى</h2>
                    <p>أحدث الاستفسارات الواردة</p>
                </div>
                <div class='ph-card-body'>
                    @forelse($inquiriesList as $inquiry)
                        <div class='ph-inquiry-mini'>
                            <div class='who'>
                                <strong>{{ $inquiry->user->name ?? 'مريض' }}</strong>
                                <span>{{ $inquiry->created_at->diffForHumans() }}</span>
                            </div>
                            <p>هل يتوفر دواء {{ $inquiry->medicine->trade_name ?? '' }}؟</p>
                        </div>
                    @empty
                        <div class='ph-empty'><i class='far fa-comment-alt'></i><h3>لا توجد استفسارات بعد</h3></div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
