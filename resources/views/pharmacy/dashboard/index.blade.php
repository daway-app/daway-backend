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
        $inquiries = $newInquiries ?? 0;
        $avg = number_format($averageRating ?? 0, 1);
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>{{ $pharmacy->pharmacy_name }}</h1>
                <p>لوحة تحكم الصيدلية — نظرة عامة على أداء الصيدلية</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> إضافة دواء</a>
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn outline'><i class='fas fa-rotate'></i> تحديث المخزون</a>
            </div>
        </div>

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>عدد الأدوية</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>متوفر</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>منخفض</span></div></div>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>نافذ</span></div></div>
            <div class='ph-stat'><i class='fas fa-comments blue'></i><div><strong>{{ $inquiries }}</strong><span>استفسارات جديدة</span></div></div>
            <div class='ph-stat'><i class='fas fa-star teal'></i><div><strong>{{ $avg }}</strong><span>متوسط التقييم</span></div></div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-chart-column'></i> حالة المخزون</h2></div>
                <div class='ph-card-body'><div class='chart-box'><canvas data-ph-chart='bar' data-ph-labels='["متوفر","منخفض","نافذ"]' data-ph-data='[{{ $available }},{{ $low }},{{ $out }}]' data-ph-colors='["#16A34A","#CA8A04","#DC2626"]'></canvas></div></div>
            </div>
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-chart-pie'></i> نسبة التوفر</h2></div>
                <div class='ph-card-body'><div class='chart-box'><canvas data-ph-chart='donut' data-ph-labels='["متوفر","منخفض","نافذ"]' data-ph-data='[{{ $available }},{{ $low }},{{ $out }}]' data-ph-colors='["#16A34A","#CA8A04","#DC2626"]'></canvas></div></div>
            </div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-bell'></i> تنبيهات المخزون المنخفض</h2></div>
                <div class='ph-card-body ph-table-wrap'>
                    <table class='ph-table'>
                        <thead><tr><th>الدواء</th><th>الكمية</th><th>الحالة</th><th>إجراء</th></tr></thead>
                        <tbody>
                            @forelse($lowStockItems as $pm)
                                <tr>
                                    <td><strong>{{ $pm->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $pm->medicine->active_ingredient }}</small></td>
                                    <td>{{ $pm->quantity }}</td>
                                    <td><span class='ph-badge low'>منخفض</span></td>
                                    <td><a href='{{ route('pharmacy.medicines.edit', $pm->id) }}' class='ph-btn sm outline'>تعديل</a></td>
                                </tr>
                            @empty
                                <tr><td colspan='4'><div class='ph-empty'><i class='fas fa-check-circle'></i><h3>لا توجد تنبيهات</h3><p>جميع الأدوية بكميات آمنة</p></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-comment-dots'></i> آخر التقييمات</h2></div>
                <div class='ph-card-body'>
                    @forelse($latestRatings as $rating)
                        <div class='ph-rating-card' style='margin-block-end:12px;'>
                            <div class='head'><strong>{{ $rating->user->name ?? 'مريض' }}</strong><span>{{ $rating->created_at->diffForHumans() }}</span></div>
                            <div class='ph-stars' style='margin-block-end:8px;'>@for($i=1;$i<=5;$i++)<i class='{{ $i <= $rating->stars_rating ? 'fas' : 'far' }} fa-star'></i>@endfor</div>
                            <p>{{ $rating->comment }}</p>
                        </div>
                    @empty
                        <div class='ph-empty'><i class='far fa-comment-alt'></i><h3>لا توجد تقييمات بعد</h3></div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection