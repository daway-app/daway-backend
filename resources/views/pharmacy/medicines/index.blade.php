@extends('layouts.app')

@section('title', __('pharmacy.medicines.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    @php
        $total = $pharmacyMedicines->total();
        $available = $availableCount ?? 0;
        $low = $lowCount ?? 0;
        $out = $outCount ?? 0;
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>قائمة الأدوية</h1>
                <p>إدارة أدوية الصيدلية ومخزونها</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.medicines.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> إضافة دواء</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>الكل</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>متوفر</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>منخفض</span></div></div>
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>نافذ</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-tabs' data-ph-tabs='.ph-medicine-grid'>
                <button class='ph-tab active' data-filter='all'>الكل</button>
                <button class='ph-tab' data-filter='ok'>متوفر</button>
                <button class='ph-tab' data-filter='low'>منخفض</button>
                <button class='ph-tab' data-filter='out'>نافذ</button>
            </div>
            <div class='ph-search'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='ابحث باسم الدواء أو المادة الفعالة...' data-ph-search='.ph-med'>
            </div>
        </div>

        <div class='ph-medicine-grid'>
            @forelse($pharmacyMedicines as $pm)
                @php
                    $q = $pm->quantity;
                    $status = $q <= 0 ? 'out' : ($q <= 10 ? 'low' : 'ok');
                    $statusText = $status === 'ok' ? 'متوفر' : ($status === 'low' ? 'منخفض' : 'نافذ');
                @endphp
                <div class='ph-med' data-status='{{ $status }}' data-min='{{ $pm->min_stock ?? 10 }}'>
                    <div class='ph-med-img'>
                        @if($pm->medicine->image)
                            <img src='{{ \App\Support\Image::url($pm->medicine->image) }}' alt='{{ $pm->medicine->trade_name }}'>
                        @else
                            <i class='fas fa-pills fallback'></i>
                        @endif
                    </div>
                    <div class='ph-med-body'>
                        <h3>{{ $pm->medicine->trade_name }}</h3>
                        <p>{{ $pm->medicine->active_ingredient }}</p>
                        <div class='ph-med-meta'>
                            <span class='ph-med-price'>{{ number_format($pm->price, 2) }} ر.س</span>
                            <span>كمية: {{ $q }}</span>
                        </div>
                        <div class='ph-med-meta'>
                            <span class='ph-badge {{ $status }}'>{{ $statusText }}</span>
                            <div class='ph-med-actions'>
                                <a href='{{ route('pharmacy.medicines.edit', $pm->id) }}' class='ph-btn icon outline' title='تعديل'><i class='fas fa-pen'></i></a>
                                <form action='{{ route('pharmacy.medicines.destroy', $pm->id) }}' method='POST' onsubmit='return confirm("هل أنت متأكد من حذف هذا الدواء؟");' style='display:inline;'>
                                    @csrf
                                    @method('DELETE')
                                    <button type='submit' class='ph-btn icon danger' title='حذف'><i class='fas fa-trash'></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class='ph-empty' style='grid-column:1/-1;'><i class='fas fa-box-open'></i><h3>لا توجد أدوية</h3><p>ابدأ بإضافة دواء جديد</p></div>
            @endforelse
        </div>

        @if($pharmacyMedicines->hasPages())
            <div style='margin-block-start:24px;'>{{ $pharmacyMedicines->links() }}</div>
        @endif
    </div>
@endsection