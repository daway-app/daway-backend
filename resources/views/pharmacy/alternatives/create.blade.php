@extends('layouts.app')

@section('title', __('pharmacy.alternatives.create.title'))

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">@lang('pharmacy.alternatives.create.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">@lang('pharmacy.alternatives.create.card_title')</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('pharmacy.alternatives.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="base_medicine_id">@lang('pharmacy.alternatives.create.base_label')</label>
                    <select name="base_medicine_id" id="base_medicine_id" class="form-control @error('base_medicine_id') is-invalid @enderror" required>
                        <option value="">@lang('pharmacy.alternatives.create.base_placeholder')</option>
                        @foreach ($pharmacyMedicines as $pm)
                            <option value="{{ $pm->id }}" {{ old('base_medicine_id', $pharmacyMedicine ? $pharmacyMedicine->id : '') == $pm->id ? 'selected' : '' }}>
                                {{ $pm->medicine->trade_name }} ({{ $pm->medicine->active_ingredient }})
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
                    <label for="alternative_medicine_id">@lang('pharmacy.alternatives.create.alternative_label')</label>
                    <select name="alternative_medicine_id" id="alternative_medicine_id" class="form-control @error('alternative_medicine_id') is-invalid @enderror" required>
                        <option value="">@lang('pharmacy.alternatives.create.alternative_placeholder')</option>
                        @foreach ($allMedicines as $medicine)
                            <option value="{{ $medicine->id }}" {{ old('alternative_medicine_id') == $medicine->id ? 'selected' : '' }}>
                                {{ $medicine->trade_name }} ({{ $medicine->active_ingredient }})
                            </option>
                        @endforeach
                    </select>
                    @error('alternative_medicine_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">@lang('pharmacy.alternatives.create.add_button')</button>
                <a href="{{ route('pharmacy.alternatives.index') }}" class="btn btn-secondary">@lang('pharmacy.alternatives.create.cancel_button')</a>
            </form>
        </div>
    </div>
</div>
@endsection
