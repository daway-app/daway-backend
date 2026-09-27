@extends('layouts.app')

@section('title', __('pharmacy.medicines.request.title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css'])
    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div>
                <h1>@lang('pharmacy.medicines.request.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.medicines.request.subtitle')</p>
            </div>
        </div>

        <form action="{{ route('pharmacy.medicines.request.store') }}" method="POST" class="premium-card">
            @csrf
            <div class="card-body">

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="trade_name">@lang('pharmacy.medicines.request.trade_name') <span class="req">*</span></label>
                        <input class="fc" type="text" id="trade_name" name="trade_name" dir="ltr" value="{{ old('trade_name') }}" required>
                        @error('trade_name')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div class="fg">
                        <label class="fl" for="trade_name_ar">@lang('pharmacy.medicines.request.trade_name_ar')</label>
                        <input class="fc" type="text" id="trade_name_ar" name="trade_name_ar" value="{{ old('trade_name_ar') }}">
                        @error('trade_name_ar')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="generic_name">@lang('pharmacy.medicines.request.generic_name')</label>
                        <input class="fc" type="text" id="generic_name" name="generic_name" dir="ltr" value="{{ old('generic_name') }}">
                    </div>
                    <div class="fg">
                        <label class="fl" for="manufacturer">@lang('pharmacy.medicines.request.manufacturer')</label>
                        <input class="fc" type="text" id="manufacturer" name="manufacturer" dir="ltr" value="{{ old('manufacturer') }}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="active_ingredient">@lang('pharmacy.medicines.request.active_ingredient')</label>
                        <input class="fc" type="text" id="active_ingredient" name="active_ingredient" dir="ltr" value="{{ old('active_ingredient') }}">
                    </div>
                    <div class="fg">
                        <label class="fl" for="dosage_form">@lang('pharmacy.medicines.request.dosage_form')</label>
                        <input class="fc" type="text" id="dosage_form" name="dosage_form" value="{{ old('dosage_form') }}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="barcode">@lang('pharmacy.medicines.request.barcode')</label>
                        <input class="fc" type="text" id="barcode" name="barcode" dir="ltr" value="{{ old('barcode') }}">
                    </div>
                    <div class="fg">
                        <label class="fl" for="official_price">@lang('pharmacy.medicines.request.official_price')</label>
                        <input class="fc" type="number" id="official_price" name="official_price" step="0.01" min="0" value="{{ old('official_price') }}">
                    </div>
                </div>

                <div class="form-row" style="margin-top:14px;">
                    <div class="fg">
                        <label class="fl" for="category_id">@lang('pharmacy.medicines.request.category') <span class="req">*</span></label>
                        <select class="fc" id="category_id" name="category_id" required>
                            <option value="">—</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name_ar }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div class="fg">
                        <label class="fl" for="subcategory_id">@lang('pharmacy.medicines.request.subcategory')</label>
                        <select class="fc" id="subcategory_id" name="subcategory_id">
                            <option value="">—</option>
                        </select>
                        @error('subcategory_id')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

            </div>
            <div class="card-foot">
                <button type="submit" class="btn-submit">@lang('pharmacy.medicines.request.submit')</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn-cancel">@lang('pharmacy.medicines.request.cancel')</a>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
@php
    $subByCat = $subcategories->map(function ($subs) {
        return $subs->pluck('name_ar', 'id')->all();
    })->toJson();
@endphp
<script>
(function () {
    const subByCat = @json($subByCat);
    const catSel = document.getElementById('category_id');
    const subSel = document.getElementById('subcategory_id');
    function fillSubs(catId) {
        subSel.innerHTML = '<option value="">—</option>';
        (subByCat[catId] || []).forEach(function (name, id) {
            const opt = document.createElement('option');
            opt.value = id; opt.textContent = name;
            subSel.appendChild(opt);
        });
    }
    catSel.addEventListener('change', function () { fillSubs(this.value); });
    fillSubs(catSel.value);
})();
</script>
@endsection
