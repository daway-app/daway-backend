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
                <h1>إدارة الأدوية</h1>
                <p>إضافة وتعديل أدوية صيدليتك</p>
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
            <div class='ph-stat'><i class='fas fa-xmark red'></i><div><strong>{{ $out }}</strong><span>ناقد</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $low }}</strong><span>مخزون منخفض</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $available }}</strong><span>متوفر</span></div></div>
            <div class='ph-stat'><i class='fas fa-pills teal'></i><div><strong>{{ $total }}</strong><span>إجمالي الأدوية</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-tabs' data-ph-tabs='.ph-medicine-table'>
                <button class='ph-tab active' data-filter='all'>الكل</button>
                <button class='ph-tab' data-filter='out'>ناقد</button>
                <button class='ph-tab' data-filter='low'>منخفض</button>
                <button class='ph-tab' data-filter='ok'>متوفر</button>
            </div>
            <div class='ph-search'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='ابحث باسم الدواء أو المادة الفعالة...' data-ph-search='.ph-medicine-table tbody tr'>
            </div>
        </div>

        <div class='ph-card ph-medicine-table'>
            <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                <table class='ph-table'>
                    <thead>
                        <tr>
                            <th>الدواء</th>
                            <th>المادة الفعالة</th>
                            <th>السعر</th>
                            <th>الكمية</th>
                            <th>الحالة</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pharmacyMedicines as $pm)
                            @php
                                $q = $pm->quantity;
                                $status = $q <= 0 ? 'out' : ($q <= 10 ? 'low' : 'ok');
                                $statusText = $status === 'ok' ? 'متوفر' : ($status === 'low' ? 'منخفض' : 'ناقد');
                            @endphp
                            <tr data-status='{{ $status }}' data-min='{{ $pm->min_stock ?? 10 }}'>
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
                                <td>{{ number_format($pm->price, 2) }} ر.س</td>
                                <td>{{ $q }}</td>
                                <td><span class='ph-badge {{ $status }}'>{{ $statusText }}</span></td>
                                <td>
                                    <div style='display:flex;gap:8px;'>
                                        <a href='{{ route('pharmacy.medicines.edit', $pm->id) }}' class='ph-btn icon outline' title='تعديل'><i class='fas fa-pen'></i></a>
                                        <form action='{{ route('pharmacy.medicines.destroy', $pm->id) }}' method='POST' onsubmit='return confirm("هل أنت متأكد من حذف هذا الدواء؟");' style='display:inline;'>
                                            @csrf
                                            @method('DELETE')
                                            <button type='submit' class='ph-btn icon danger' title='حذف'><i class='fas fa-trash'></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan='6'><div class='ph-empty'><i class='fas fa-box-open'></i><h3>لا توجد أدوية</h3><p>ابدأ بإضافة دواء جديد</p></div></td></tr>
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
