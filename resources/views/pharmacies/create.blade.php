@extends('layouts.app')

@section('title', __('pharmacies.create_pharmacy_title'))

@section('breadcrumb')
    <span class="sep">›</span>
    <a href="{{ route('pharmacies.index') }}">@lang('pharmacies.breadcrumb_current')</a>
    <span class="sep">›</span>
    <span class="cur">@lang('pharmacies.add_pharmacy_breadcrumb')</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacies_create.css'])

    <div class="pharmacy-create-container">

        {{-- Validation Errors --}}
        @if($errors->any())
            <div class="alert alert-error">
                <span class="alert-icon">!</span>
                <div>
                    <div class="alert-title">@lang('pharmacies.validation_errors_title')</div>
                    <ul class="error-list">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="alert alert-ok">
            <span style="font-size:20px">🎫</span>
            <div>
                <div style="font-weight:700;margin-bottom:2px">@lang('pharmacies.pharmacy_id_auto_generate')</div>
                <div style="font-size:.78rem">@lang('pharmacies.pharmacy_id_hint')</div>
            </div>
        </div>

        <div class="alert alert-info" style="background:#e0f2fe;border-color:#0ea5e9;color:#0c4a6e;">
            <span style="font-size:20px">ℹ️</span>
            <div>
                <div style="font-weight:700;margin-bottom:2px">@lang('pharmacies.first_login_data_title')</div>
                <div style="font-size:.78rem">@lang('pharmacies.first_login_data_desc')</div>
            </div>
        </div>

        <form action="{{ route('pharmacies.store') }}" method="POST">
            @csrf
            <div class="card">
                <div class="card-head"><h2>@lang('pharmacies.pharmacy_details_section')</h2></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="fg" style="flex: 1 1 100%;">
                            <label class="fl">@lang('pharmacies.pharmacy_name_label') <span class="req">*</span></label>
                            <input class="fc" type="text" name="pharmacy_name" placeholder="@lang('pharmacies.pharmacy_name_placeholder')" value="{{ old('pharmacy_name') }}" required>
                            @error('pharmacy_name')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('pharmacies.index') }}" class="btn-cancel">@lang('pharmacies.cancel_button')</a>
                <button type="submit" class="btn-submit">@lang('pharmacies.submit_button')</button>
            </div>
        </form>
    </div>
@endsection
