@extends('layouts.app')

@section('title', 'إضافة بديل لدواء')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">إضافة بديل لدواء في صيدلية {{ $pharmacy->pharmacy_name }}</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">ربط دواء ببديل</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('pharmacy.alternatives.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="base_medicine_id">اختر الدواء الأساسي:</label>
                    <select name="base_medicine_id" id="base_medicine_id" class="form-control @error('base_medicine_id') is-invalid @enderror" required>
                        <option value="">-- اختر دواء من مخزون صيدليتك --</option>
                        @foreach ($pharmacyMedicines as $pm)
                            <option value="{{ $pm->id }}" {{ old('base_medicine_id', $pharmacyMedicine ? $pharmacyMedicine->id : '') == $pm->id ? 'selected' : '' }}>
                                {{ $pm->medicine->trade_name }} ({{ $pm->medicine->scientific_name }})
                            </option>
                        @endforeach
                    </select>
                    @error('base_medicine_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="alternative_medicine_id">اختر الدواء البديل:</label>
                    <select name="alternative_medicine_id" id="alternative_medicine_id" class="form-control @error('alternative_medicine_id') is-invalid @enderror" required>
                        <option value="">-- اختر دواء بديلاً من القائمة العامة --</option>
                        @foreach ($allMedicines as $medicine)
                            <option value="{{ $medicine->id }}" {{ old('alternative_medicine_id') == $medicine->id ? 'selected' : '' }}>
                                {{ $medicine->trade_name }} ({{ $medicine->scientific_name }})
                            </option>
                        @endforeach
                    </select>
                    @error('alternative_medicine_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">إضافة البديل</button>
                <a href="{{ route('pharmacy.alternatives.index') }}" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>
@endsection
