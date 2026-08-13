@extends('layouts.app')

@section('title', __('medicines.edit_medicine_title', ['name' => $medicine->trade_name]))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css'])
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </div>
            <div>
                <h1>@lang('medicines.edit_medicine_title', ['name' => $medicine->trade_name])</h1>
                <p>@lang('medicines.main_description')</p>
            </div>
        </div>

        <form action="{{ route('medicines.update', $medicine->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon teal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </div>
                        <div>
                            <h2>@lang('medicines.basic_medicine_data')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="name_ar">@lang('medicines.medicine_name_ar') <span class="req">*</span></label>
                            <input class="fc" type="text" id="name_ar" name="name_ar" value="{{ old('name_ar', $medicine->trade_name) }}" required>
                        </div>
                        <div class="fg">
                            <label class="fl" for="active_ingredient">@lang('medicines.active_ingredient_label') <span class="req">*</span></label>
                            <input class="fc" type="text" id="active_ingredient" name="active_ingredient" value="{{ old('active_ingredient', $medicine->active_ingredient) }}" required>
                        </div>
                    </div>
                    <div class="fg">
                        <label class="fl" for="description">@lang('medicines.description_usage_label')</label>
                        <textarea class="fc" id="description" name="description" rows="3">{{ old('description', $medicine->description) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon purple">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="5" x2="6" y2="17"></line><line x1="6" y1="5" x2="18" y2="17"></line></svg>
                        </div>
                        <div>
                            <h2>@lang('medicines.alternative_medicines')</h2>
                            <p>@lang('medicines.alternative_medicines_desc')</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="fg">
                        <label class="fl" for="alternatives">@lang('medicines.add_alternative_placeholder')</label>
                        <select id="alternatives" name="alternatives[]" multiple>
                            @foreach($allMedicines as $altMedicine)
                                <option value="{{ $altMedicine->id }}" {{ $medicine->alternatives->contains($altMedicine->id) ? 'selected' : '' }}>
                                    {{ $altMedicine->trade_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('medicines.index') }}" class="btn-cancel">@lang('medicines.cancel_button')</a>
                <button type="submit" class="btn-submit">@lang('medicines.update_medicine_button')</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            new TomSelect('#alternatives',{
                plugins: ['remove_button'],
                create: false,
                placeholder: '@lang('medicines.add_alternative_placeholder')',
            });
        });
    </script>
@endsection
