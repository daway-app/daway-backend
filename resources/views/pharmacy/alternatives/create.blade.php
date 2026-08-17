@extends('layouts.app')

@section('title', __('pharmacy.alternatives.create.title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css', 'resources/css/pages/pharmacy_medicine_create.css'])

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
            </div>
            <div>
                <h1>@lang('pharmacy.alternatives.create.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.alternatives.create.card_title')</p>
            </div>
        </div>

        <div class="premium-card">
            <div class="card-head">
                <div class="card-head-content">
                    <div class="card-icon teal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <div>
                        <h2>@lang('pharmacy.alternatives.create.card_title')</h2>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('pharmacy.alternatives.store') }}" method="POST">
                    @csrf

                    <div class="fg">
                        <label class="fl" for="base_medicine_id">@lang('pharmacy.alternatives.create.base_label') <span class="req">*</span></label>
                        <select name="base_medicine_id" id="base_medicine_id" class="fc @error('base_medicine_id') is-invalid @enderror" required>
                            <option value="">@lang('pharmacy.alternatives.create.base_placeholder')</option>
                            @foreach ($pharmacyMedicines as $pm)
                                <option value="{{ $pm->id }}" {{ old('base_medicine_id', $pharmacyMedicine ? $pharmacyMedicine->id : '') == $pm->id ? 'selected' : '' }}>
                                    {{ $pm->medicine->trade_name }} ({{ $pm->medicine->active_ingredient }})
                                </option>
                            @endforeach
                        </select>
                        @error('base_medicine_id')
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="fg">
                        <label class="fl" for="alternative_medicine_id">@lang('pharmacy.alternatives.create.alternative_label') <span class="req">*</span></label>
                        <select name="alternative_medicine_id" id="alternative_medicine_id" class="fc @error('alternative_medicine_id') is-invalid @enderror" required>
                            <option value="">@lang('pharmacy.alternatives.create.alternative_placeholder')</option>
                            @foreach ($allMedicines as $medicine)
                                <option value="{{ $medicine->id }}" {{ old('alternative_medicine_id') == $medicine->id ? 'selected' : '' }}>
                                    {{ $medicine->trade_name }} ({{ $medicine->active_ingredient }})
                                </option>
                            @endforeach
                        </select>
                        @error('alternative_medicine_id')
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('pharmacy.alternatives.index') }}" class="btn-cancel">@lang('pharmacy.alternatives.create.cancel_button')</a>
                        <button type="submit" class="btn-submit">@lang('pharmacy.alternatives.create.add_button')</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
