@extends('layouts.app')

@section('title', 'تعديل دواء في الصيدلية')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">تعديل دواء: {{ $pharmacyMedicine->medicine->trade_name }} في صيدلية {{ $pharmacy->pharmacy_name }}</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">بيانات الدواء</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('pharmacy.medicines.update', $pharmacyMedicine->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="medicine_name">اسم الدواء:</label>
                    <input type="text" id="medicine_name" class="form-control" value="{{ $pharmacyMedicine->medicine->trade_name }} ({{ $pharmacyMedicine->medicine->scientific_name }})" disabled>
                </div>

                <div class="form-group">
                    <label for="price">السعر:</label>
                    <input type="number" name="price" id="price" class="form-control @error('price') is-invalid @enderror" step="0.01" min="0" value="{{ old('price', $pharmacyMedicine->price) }}" required>
                    @error('price')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="quantity">المخزون:</label> {{-- Changed from 'stock' to 'quantity' --}}
                    <input type="number" name="quantity" id="quantity" class="form-control @error('quantity') is-invalid @enderror" min="0" value="{{ old('quantity', $pharmacyMedicine->quantity) }}" required> {{-- Changed from 'stock' to 'quantity' --}}
                    @error('quantity') {{-- Changed from 'stock' to 'quantity' --}}
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group form-check">
                    <input type="checkbox" name="is_available" id="is_available" class="form-check-input" value="1" {{ old('is_available', $pharmacyMedicine->is_available) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_available">متوفر حالياً</label>
                    @error('is_available')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">تحديث الدواء</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>
@endsection
