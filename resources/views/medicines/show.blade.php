@extends('layouts.app')

@section('title', 'تفاصيل الدواء — ' . $medicine->trade_name)

@section('breadcrumb')
    <span class="sep">›</span>
    <a href="{{ route('medicines.index') }}">الأدوية</a>
    <span class="sep">›</span>
    <span class="cur">{{ $medicine->trade_name }}</span>
@endsection

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px">
        <div style="display:flex; align-items:center; gap:14px">
            <div class="t-av med" style="width:52px; height:52px; font-size:1.4rem">💊</div>
            <div>
                <h2 style="font-size:1.3rem; font-weight:800; color:var(--gray-900)">{{ $medicine->trade_name }}</h2>
                <div style="display:flex; gap:10px; align-items:center; margin-top:2px">
                    <span class="pid">MED-{{ $medicine->id }} <button onclick="copyId(this,'MED-{{ $medicine->id }}')" title="نسخ">⎘</button></span>
                    <span class="bdg bdg-ok">متوفر في {{ $medicine->pharmacyMedicines->count() }} صيدلية</span>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:8px">
            <a href="{{ route('medicines.edit', $medicine->id) }}" class="btn btn-s">✏️ تعديل الدواء</a>
            <form action="{{ route('medicines.destroy', $medicine->id) }}" method="POST" onsubmit="return confirm('هل أنت متأكد أنك تريد حذف هذا الدواء؟');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-d">🗑 حذف من الكتالوج</button>
            </form>
        </div>
    </div>

    <div class="col-7-5">
        <div>
            <!-- البيانات العامة للدواء -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-head"><h2>📋 المعلومات التخصصية</h2></div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px">
                        <div>
                            <div style="font-size:.75rem; color:var(--gray-400)">المادة الفعالة</div>
                            <div style="font-weight:600">{{ $medicine->active_ingredient }}</div>
                        </div>
                        <div>
                            <div style="font-size:.75rem; color:var(--gray-400)">الفئة الطبية</div>
                            <div style="font-weight:600">{{ $medicine->category ?? 'غير محدد' }}</div>
                        </div>
                        <div>
                            <div style="font-size:.75rem; color:var(--gray-400)">الشكل الدوائي</div>
                            <div style="font-weight:600">{{ $medicine->form ?? 'غير محدد' }}</div>
                        </div>
                        <div>
                            <div style="font-size:.75rem; color:var(--gray-400)">متوسط السعر المعياري</div>
                            <div style="font-weight:600; color:var(--teal-700)">{{ $medicine->price_range ?? 'N/A' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- البدائل التناظرية والمكافئة -->
            <div class="card">
                <div class="card-head">
                    <h2>🔄 البدائل المتاحة ({{ $medicine->alternatives->count() }})</h2>
                    <span class="bdg bdg-teal">نفس المادة الفعالة</span>
                </div>
                <div class="card-body np">
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>اسم البديل</th><th>الشركة المصنعة</th><th>التركيز</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($medicine->alternatives as $alternative)
                            <tr>
                                <td><strong>{{ $alternative->trade_name }}</strong></td>
                                <td>{{ $alternative->manufacturer ?? 'N/A' }}</td>
                                <td>{{ $alternative->dosage ?? 'N/A' }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 20px;">لا توجد بدائل متاحة حالياً.</td>
                            </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- الصيدليات المتوفر بها -->
        <div>
            <div class="card">
                <div class="card-head"><h2>📍 صيدليات يتوفر بها الدواء ({{ $medicine->pharmacyMedicines->count() }})</h2></div>
                <div class="card-body np">
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>الصيدلية</th><th>السعر</th><th>الكمية</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($medicine->pharmacyMedicines as $pharmacyMedicine)
                            <tr>
                                <td><strong>{{ $pharmacyMedicine->pharmacy->pharmacy_name }}</strong></td>
                                <td>{{ $pharmacyMedicine->price }} ₪</td>
                                <td><span class="bdg bdg-ok">{{ $pharmacyMedicine->quantity }} علبة</span></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 20px;">لا يتوفر هذا الدواء في أي صيدلية حالياً.</td>
                            </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyId(button, id) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(id).then(function () {
                    const original = button.innerHTML;
                    button.innerHTML = '✓';
                    setTimeout(function () { button.innerHTML = original; }, 1000);
                }).catch(function () {});
            }
        }
    </script>
@endsection
