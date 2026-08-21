@extends('layouts.app')

@section('title', 'إدارة المخزون')

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    @push('scripts')
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    @endpush

    @php
        $available = $available ?? 0;
        $low = $low ?? 0;
        $out = $out ?? 0;
        $labels = json_encode($trendLabels ?? []);
        $data = json_encode($trendData ?? []);
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>إدارة المخزون</h1>
                <p>تحديث كميات الأدوية وحالة التوفر</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> إضافة دواء</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>متوفر</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>منخفض</span></div></div>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>نافذ</span></div></div>
        </div>

        <div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-chart-pie'></i> حالة المخزون</h2></div>
                <div class='ph-card-body'><div class='chart-box'><canvas data-ph-chart='donut' data-ph-labels='["متوفر","منخفض","نافذ"]' data-ph-data='[{{ $available }},{{ $low }},{{ $out }}]' data-ph-colors='["#16A34A","#CA8A04","#DC2626"]'></canvas></div></div>
            </div>
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-chart-line'></i> اتجاه المخزون</h2></div>
                <div class='ph-card-body'><div class='chart-box'><canvas data-ph-chart='line' data-ph-labels='{{ $labels }}' data-ph-data='{{ $data }}'></canvas></div></div>
            </div>
        </div>

        <form action='{{ route('pharmacy.inventory.update') }}' method='POST'>
            @csrf
            @method('PUT')
            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-boxes-stacked'></i> تحديث الكميات</h2></div>
                <div class='ph-card-body ph-table-wrap'>
                    <table class='ph-table'>
                        <thead><tr><th>الدواء</th><th>الحد الأدنى</th><th>الكمية الحالية</th><th>تعديل</th><th>الحالة</th></tr></thead>
                        <tbody>
                            @forelse($items as $item)
                                @php
                                    $q = $item->quantity;
                                    $min = $item->min_stock ?? 10;
                                    $status = $q <= 0 ? 'out' : ($q <= $min ? 'low' : 'ok');
                                    $statusText = $status === 'ok' ? 'متوفر' : ($status === 'low' ? 'منخفض' : 'نافذ');
                                @endphp
                                <tr data-status='{{ $status }}' data-min='{{ $min }}'>
                                    <td><strong>{{ $item->medicine->trade_name }}</strong><br><small style='color:var(--ph-ink-faint);'>{{ $item->medicine->active_ingredient }}</small></td>
                                    <td>{{ $min }}</td>
                                    <td>
                                        <div class='ph-stepper'>
                                            <button type='button' class='dec'><i class='fas fa-minus'></i></button>
                                            <input type='number' name='quantities[{{ $item->id }}]' value='{{ $q }}' min='0'>
                                            <button type='button' class='inc'><i class='fas fa-plus'></i></button>
                                        </div>
                                    </td>
                                    <td><a href='{{ route('pharmacy.medicines.edit', $item->id) }}' class='ph-btn sm outline'>تعديل الدواء</a></td>
                                    <td><span class='ph-badge {{ $status }}'>{{ $statusText }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan='5'><div class='ph-empty'><i class='fas fa-box-open'></i><h3>لا توجد أدوية في المخزون</h3></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($items->count())
                    <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>
                        <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> حفظ التحديثات</button>
                    </div>
                @endif
            </div>
        </form>
    </div>
@endsection