@extends('layouts.app')

@section('title', __('pharmacy.medicines.edit.title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css'])

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </div>
            <div>
                <h1>@lang('pharmacy.medicines.edit.heading', ['medicine' => $pharmacyMedicine->medicine->trade_name])</h1>
                <p>@lang('pharmacy.medicines.edit.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
            </div>
        </div>

        <form action="{{ route('pharmacy.medicines.update', $pharmacyMedicine->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon teal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </div>
                        <div>
                            <h2>@lang('pharmacy.medicines.edit.info_section')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="fg">
                        <label class="fl">@lang('pharmacy.medicines.edit.medicine_name')</label>
                        <input class="fc" type="text" value="{{ $pharmacyMedicine->medicine->trade_name }} ({{ $pharmacyMedicine->medicine->active_ingredient }})" disabled>
                    </div>
                    <div class="form-row" style="margin-top:16px;">
                        <div class="fg">
                            <label class="fl" for="price">@lang('pharmacy.medicines.edit.price_label') <span class="req">*</span></label>
                            <input class="fc" type="number" id="price" name="price" step="0.01" min="0" value="{{ old('price', $pharmacyMedicine->price) }}" required>
                            @error('price')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="quantity">@lang('pharmacy.medicines.edit.quantity_label') <span class="req">*</span></label>
                            <input class="fc" type="number" id="quantity" name="quantity" min="0" value="{{ old('quantity', $pharmacyMedicine->quantity) }}" required>
                            @error('quantity')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="fg" style="margin-top:16px;">
                        <label class="fl-check">
                            <input type="checkbox" name="is_available" id="is_available" value="1" {{ old('is_available', $pharmacyMedicine->is_available) ? 'checked' : '' }}>
                            @lang('pharmacy.medicines.edit.available_now')
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">@lang('pharmacy.medicines.edit.update_button')</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn-cancel">@lang('pharmacy.medicines.edit.cancel_button')</a>
            </div>
        </form>
    </div>
@endsection
