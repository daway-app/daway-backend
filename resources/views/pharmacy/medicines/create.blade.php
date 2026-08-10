@extends('layouts.app')

@section('title', 'إضافة دواء جديد للصيدلية')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">إضافة دواء جديد لصيدلية {{ $pharmacy->pharmacy_name }}</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">بيانات الدواء</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('pharmacy.medicines.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="medicine_id">اختر الدواء:</label>
                    <select name="medicine_id" id="medicine_id" class="form-control @error('medicine_id') is-invalid @enderror" required>
                        <option value="">-- اختر دواء من القائمة العامة --</option>
                        @foreach ($allMedicines as $medicine)
                            <option value="{{ $medicine->id }}" {{ old('medicine_id') == $medicine->id ? 'selected' : '' }}>
                                {{ $medicine->trade_name }} ({{ $medicine->scientific_name }})
                            </option>
                        @endforeach
                    </select>
                    @error('medicine_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="price">السعر:</label>
                    <input type="number" name="price" id="price" class="form-control @error('price') is-invalid @enderror" step="0.01" min="0" value="{{ old('price') }}" required>
                    @error('price')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="quantity">المخزون:</label> {{-- Changed from 'stock' to 'quantity' --}}
                    <input type="number" name="quantity" id="quantity" class="form-control @error('quantity') is-invalid @enderror" min="0" value="{{ old('quantity') }}" required> {{-- Changed from 'stock' to 'quantity' --}}
                    @error('quantity') {{-- Changed from 'stock' to 'quantity' --}}
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group form-check">
                    <input type="checkbox" name="is_available" id="is_available" class="form-check-input" value="1" {{ old('is_available', true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_available">متوفر حالياً</label>
                    @error('is_available')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">إضافة الدواء</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>
@endsection
