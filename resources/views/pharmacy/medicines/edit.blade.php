@extends('layouts.app')

@section('title', __('pharmacy.medicines.edit.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.medicines.edit.heading_page')</h1>
                <p>{{ $pharmacyMedicine->medicine->trade_name }} — {{ $pharmacy->pharmacy_name }}</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.alternatives.create', $pharmacyMedicine) }}' class='ph-btn outline'><i class='fas fa-exchange-alt'></i> @lang('pharmacy.medicines.edit.add_alternative')</a>
                <form action='{{ route('pharmacy.medicines.destroy', $pharmacyMedicine->id) }}' method='POST' onsubmit='return confirm("@lang('pharmacy.medicines.edit.delete_confirm')");' style='display:inline;'>
                    @csrf
                    @method('DELETE')
                    <button type='submit' class='ph-btn danger'><i class='fas fa-trash'></i> @lang('pharmacy.medicines.edit.delete_button')</button>
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
            <div class='ph-card-head'><h2><i class='fas fa-pills'></i> @lang('pharmacy.medicines.edit.info_section')</h2></div>
            <div class='ph-card-body'>
                <form action='{{ route('pharmacy.medicines.update', $pharmacyMedicine->id) }}' method='POST' data-offline-form='medicine-edit' data-pharmacy-medicine-id='{{ $pharmacyMedicine->id }}'>
                    @csrf
                    @method('PUT')

                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label'>@lang('pharmacy.medicines.edit.trade_name')</label>
                            <input type='text' class='ph-control' value='{{ $pharmacyMedicine->medicine->trade_name }}' disabled>
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label'>@lang('pharmacy.medicines.edit.active_ingredient')</label>
                            <input type='text' class='ph-control' value='{{ $pharmacyMedicine->medicine->active_ingredient }}' disabled>
                        </div>
                    </div>

                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='price'>@lang('pharmacy.medicines.edit.price_label') <span class='req'>*</span></label>
                            <input type='number' step='0.01' min='0' name='price' id='price' class='ph-control' value='{{ old('price', $pharmacyMedicine->price) }}' required>
                            @error('price')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='quantity'>@lang('pharmacy.medicines.edit.quantity_label') <span class='req'>*</span></label>
                            <input type='number' min='0' name='quantity' id='quantity' class='ph-control' value='{{ old('quantity', $pharmacyMedicine->quantity) }}' required>
                            @error('quantity')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class='ph-group' style='margin-block-end:18px;'>
                        <label class='ph-form-label' style='display:flex;align-items:center;gap:8px;cursor:pointer;'>
                            <input type='checkbox' name='is_available' value='1' {{ old('is_available', $pharmacyMedicine->is_available) ? 'checked' : '' }} style='width:18px;height:18px;accent-color:var(--ph-teal);'>
                            @lang('pharmacy.medicines.edit.available_now')
                        </label>
                    </div>

                    <div style='display:flex;gap:10px;'>
                        <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> @lang('pharmacy.medicines.edit.update_button')</button>
                        <a href='{{ route('pharmacy.medicines.index') }}' class='ph-btn ghost'>@lang('pharmacy.medicines.edit.cancel_button')</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection