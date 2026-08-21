@extends('layouts.app')

@section('title', __('pharmacy.medicines.edit.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>تعديل دواء</h1>
                <p>{{ $pharmacyMedicine->medicine->trade_name }} — {{ $pharmacy->pharmacy_name }}</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.alternatives.create', $pharmacyMedicine) }}' class='ph-btn outline'><i class='fas fa-exchange-alt'></i> إضافة بديل</a>
                <form action='{{ route('pharmacy.medicines.destroy', $pharmacyMedicine->id) }}' method='POST' onsubmit='return confirm("هل أنت متأكد من حذف هذا الدواء؟");' style='display:inline;'>
                    @csrf
                    @method('DELETE')
                    <button type='submit' class='ph-btn danger'><i class='fas fa-trash'></i> حذف</button>
                </form>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-card'>
            <div class='ph-card-head'><h2><i class='fas fa-pills'></i> بيانات الدواء</h2></div>
            <div class='ph-card-body'>
                <form action='{{ route('pharmacy.medicines.update', $pharmacyMedicine->id) }}' method='POST'>
                    @csrf
                    @method('PUT')

                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label'>الاسم التجاري</label>
                            <input type='text' class='ph-control' value='{{ $pharmacyMedicine->medicine->trade_name }}' disabled>
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label'>المادة الفعالة</label>
                            <input type='text' class='ph-control' value='{{ $pharmacyMedicine->medicine->active_ingredient }}' disabled>
                        </div>
                    </div>

                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='price'>السعر <span class='req'>*</span></label>
                            <input type='number' step='0.01' min='0' name='price' id='price' class='ph-control' value='{{ old('price', $pharmacyMedicine->price) }}' required>
                            @error('price')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='quantity'>الكمية <span class='req'>*</span></label>
                            <input type='number' min='0' name='quantity' id='quantity' class='ph-control' value='{{ old('quantity', $pharmacyMedicine->quantity) }}' required>
                            @error('quantity')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='min_stock'>الحد الأدنى للمخزون</label>
                            <input type='number' min='0' name='min_stock' id='min_stock' class='ph-control' value='{{ old('min_stock', $pharmacyMedicine->min_stock ?? 10) }}'>
                        </div>
                    </div>

                    <div class='ph-group' style='margin-block-end:18px;'>
                        <label class='ph-form-label' style='display:flex;align-items:center;gap:8px;cursor:pointer;'>
                            <input type='checkbox' name='is_available' value='1' {{ old('is_available', $pharmacyMedicine->is_available) ? 'checked' : '' }} style='width:18px;height:18px;accent-color:var(--ph-teal);'>
                            متوفر الآن
                        </label>
                    </div>

                    <div style='display:flex;gap:10px;'>
                        <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> تحديث</button>
                        <a href='{{ route('pharmacy.medicines.index') }}' class='ph-btn ghost'>إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection