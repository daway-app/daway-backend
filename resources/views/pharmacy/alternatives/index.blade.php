@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>البدائل</h1>
                <p>إدارة بدائل الأدوية لدى الصيدلية</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-exchange-alt teal'></i><div><strong>{{ $totalAlternatives }}</strong><span>إجمالي البدائل</span></div></div>
            <div class='ph-stat'><i class='fas fa-check green'></i><div><strong>{{ $availableAlternatives }}</strong><span>أدوية لها بدائل</span></div></div>
        </div>

        <div class='ph-medicine-grid'>
            @forelse($pharmacyMedicines as $pm)
                <div class='ph-med'>
                    <div class='ph-med-body'>
                        <h3>{{ $pm->medicine->trade_name }}</h3>
                        <p>{{ $pm->medicine->active_ingredient }}</p>

                        <div style='margin-block:12px;'>
                            <strong style='font-size:.85rem;color:var(--ph-ink-soft);display:block;margin-block-end:8px;'>البدائل الحالية:</strong>
                            @if($pm->medicine->alternatives->count())
                                <div style='display:flex;flex-wrap:wrap;gap:8px;'>
                                    @foreach($pm->medicine->alternatives as $alt)
                                        <span style='display:inline-flex;align-items:center;gap:6px;background:var(--ph-teal-mist);color:var(--ph-teal);padding:6px 12px;border-radius:var(--ph-r-full);font-size:.8rem;font-weight:600;'>
                                            {{ $alt->trade_name }}
                                            <form action='{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $alt->id]) }}' method='POST' style='display:inline;' onsubmit='return confirm("حذف البديل؟");'>
                                                @csrf
                                                @method('DELETE')
                                                <button type='submit' style='background:none;border:none;color:var(--ph-teal);cursor:pointer;padding:0;font-size:.8rem;'>×</button>
                                            </form>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p style='font-size:.85rem;color:var(--ph-ink-faint);'>لا توجد بدائل مسجلة</p>
                            @endif
                        </div>

                        <form action='{{ route('pharmacy.alternatives.store') }}' method='POST' style='margin-block-start:auto;padding-block-start:12px;border-block-start:1px solid var(--ph-line-soft);'>
                            @csrf
                            <input type='hidden' name='base_medicine_id' value='{{ $pm->id }}'>
                            <div class='ph-group' style='margin-block-end:10px;'>
                                <label class='ph-form-label' style='font-size:.8rem;'>إضافة بديل</label>
                                <select name='alternative_medicine_id' class='ph-select' required>
                                    <option value=''>اختر دواء...</option>
                                    @foreach($allMedicines as $med)
                                        @continue($med->id === $pm->medicine->id)
                                        @continue($pm->medicine->alternatives->contains('id', $med->id))
                                        <option value='{{ $med->id }}'>{{ $med->trade_name }} ({{ $med->active_ingredient }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type='submit' class='ph-btn sm outline'><i class='fas fa-plus'></i> إضافة بديل</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class='ph-empty' style='grid-column:1/-1;'><i class='fas fa-box-open'></i><h3>لا توجد أدوية</h3></div>
            @endforelse
        </div>

        @if($pharmacyMedicines->hasPages())
            <div style='margin-block-start:24px;'>{{ $pharmacyMedicines->links() }}</div>
        @endif
    </div>
@endsection