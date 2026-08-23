@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    @php
        $needsAlternative = $pharmacyMedicines->filter(fn($pm) => $pm->quantity <= 0 && $pm->medicine->alternatives->isEmpty());
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>إدارة البدائل</h1>
                <p>ربط الأدوية ببدائل لها نفس المادة الفعالة</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-arrows-rotate teal'></i><div><strong>{{ $totalAlternatives }}</strong><span>بدائل محددة</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $needsAlternative->count() }}</strong><span>أدوية تحتاج بديلاً</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-search' style='min-width:320px;'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='ابحث عن دواء أو مادة فعالة...' data-ph-search='.ph-alt-block'>
            </div>
        </div>

        @forelse($pharmacyMedicines as $pm)
            @php
                $isOut = $pm->quantity <= 0;
                $currentAlts = $pm->medicine->alternatives;
                $candidates = $allMedicines->filter(fn($m) => $m->active_ingredient === $pm->medicine->active_ingredient && $m->id !== $pm->medicine->id);
            @endphp
            <div class='ph-card ph-alt-block' style='margin-block-end:20px;'>
                <div class='ph-card-head'><h2><i class='fas fa-pills'></i> {{ $pm->medicine->trade_name }}</h2></div>
                <div class='ph-card-body' style='display:grid;grid-template-columns:280px 1fr;gap:22px;'>
                    <div>
                        @if($isOut)
                            <div class='ph-badge out' style='margin-block-end:14px;'>دواء غير متوفر حالياً</div>
                        @endif
                        <div class='ph-alt-detail'>
                            <div class='row'><span>المادة الفعالة</span><strong>{{ $pm->medicine->active_ingredient }}</strong></div>
                            <div class='row'><span>التركيز</span><strong>{{ $pm->medicine->strength ?? '—' }}</strong></div>
                            <div class='row'><span>الكمية المتوفرة</span><strong style='color:{{ $isOut ? 'var(--ph-red)' : 'var(--ph-ink)' }}'>{{ $pm->quantity }}</strong></div>
                            <div class='row'><span>آخر تحديث</span><strong>{{ $pm->updated_at->format('Y-m-d h:i A') }}</strong></div>
                        </div>
                        @if($currentAlts->isEmpty())
                            <div class='ph-alt-notice'><i class='fas fa-circle-info'></i> لم يتم تحديد بديل لهذا الدواء بعد. اختر بديلاً من القائمة المجاورة.</div>
                        @endif
                    </div>

                    <div class='ph-table-wrap'>
                        <table class='ph-table'>
                            <thead><tr><th>اسم الدواء</th><th>المادة الفعالة</th><th>التركيز</th><th>الكمية المتوفرة</th><th>السعر</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                @forelse($candidates as $cand)
                                    @php $selected = $currentAlts->contains('id', $cand->id); @endphp
                                    <tr @if($selected) style="background:rgba(22,163,74,.06);" @endif>
                                        <td><strong>{{ $cand->trade_name }}</strong></td>
                                        <td>{{ $cand->active_ingredient }}</td>
                                        <td>{{ $cand->strength ?? '—' }}</td>
                                        <td><span class='ph-badge ok'>متوفر</span></td>
                                        <td>{{ isset($cand->price) ? number_format($cand->price, 2).' ر.س' : '—' }}</td>
                                        <td>
                                            @if($selected)
                                                <form action='{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $cand->id]) }}' method='POST' onsubmit='return confirm("حذف البديل؟");'>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type='submit' class='ph-btn sm' style='background:var(--ph-green);color:#fff;border-color:var(--ph-green);'><i class='fas fa-check'></i> تم اختياره</button>
                                                </form>
                                            @else
                                                <form action='{{ route('pharmacy.alternatives.store') }}' method='POST'>
                                                    @csrf
                                                    <input type='hidden' name='base_medicine_id' value='{{ $pm->id }}'>
                                                    <input type='hidden' name='alternative_medicine_id' value='{{ $cand->id }}'>
                                                    <button type='submit' class='ph-btn sm outline'>اختيار البديل</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan='6'><div class='ph-empty' style='padding:24px;'><i class='fas fa-box-open'></i><h3>لا توجد بدائل بنفس المادة الفعالة</h3></div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style='padding:14px 22px;border-block-start:1px solid var(--ph-line-soft);font-size:.8rem;color:var(--ph-ink-faint);'>
                    <i class='fas fa-circle-info'></i> جميع البدائل المعروضة لها نفس المادة الفعالة والتركيز.
                </div>
            </div>
        @empty
            <div class='ph-empty'><i class='fas fa-box-open'></i><h3>لا توجد أدوية</h3></div>
        @endforelse

        @if($pharmacyMedicines->hasPages())
            <div style='margin-block-start:24px;'>{{ $pharmacyMedicines->links() }}</div>
        @endif
    </div>
@endsection
