@extends('layouts.app')

@section('title', __('pharmacy.profile.title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css', 'resources/css/pages/pharmacy_medicine_create.css', 'resources/css/pages/medicines.css'])

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </div>
            <div>
                <h1>@lang('pharmacy.profile.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.profile.card_title')</p>
            </div>
        </div>

        @if (session('success'))
            <div class="alert-message success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert-message error">{{ session('error') }}</div>
        @endif

        <div class="premium-card">
            <div class="card-head">
                <div class="card-head-content">
                    <div class="card-icon teal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </div>
                    <div>
                        <h2>@lang('pharmacy.profile.card_title')</h2>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('pharmacy.profile.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="pharmacy_name">@lang('pharmacy.profile.name_label') <span class="req">*</span></label>
                            <input type="text" name="pharmacy_name" id="pharmacy_name" class="fc @error('pharmacy_name') is-invalid @enderror" value="{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}" required>
                            @error('pharmacy_name')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="phone_number">@lang('pharmacy.profile.phone_label') <span class="req">*</span></label>
                            <input type="text" name="phone_number" id="phone_number" class="fc @error('phone_number') is-invalid @enderror" value="{{ old('phone_number', $pharmacy->phone_number) }}" required>
                            @error('phone_number')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>

                    <div class="fg">
                        <label class="fl" for="address">@lang('pharmacy.profile.address_label') <span class="req">*</span></label>
                        <input type="text" name="address" id="address" class="fc @error('address') is-invalid @enderror" value="{{ old('address', $pharmacy->address) }}" required>
                        @error('address')
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="latitude">@lang('pharmacy.profile.latitude_label') <span class="req">*</span></label>
                            <input type="text" name="latitude" id="latitude" class="fc @error('latitude') is-invalid @enderror" value="{{ old('latitude', $pharmacy->latitude) }}" required>
                            @error('latitude')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="longitude">@lang('pharmacy.profile.longitude_label') <span class="req">*</span></label>
                            <input type="text" name="longitude" id="longitude" class="fc @error('longitude') is-invalid @enderror" value="{{ old('longitude', $pharmacy->longitude) }}" required>
                            @error('longitude')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>

                    <div class="fg">
                        <label class="fl" for="logo">@lang('pharmacy.profile.logo_label')</label>
                        <input type="file" name="logo" id="logo" class="fc @error('logo') is-invalid @enderror" style="height:auto; padding: 10px 14px;">
                        @if ($pharmacy->logo)
                            <img src="{{ Storage::url($pharmacy->logo) }}" alt="@lang('pharmacy.profile.logo_alt')" style="max-width: 150px; margin-top: 10px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        @endif
                        @error('logo')
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <h5 style="margin: 24px 0 14px; font-size: 15px; font-weight: 800; color: #0f172a;">@lang('pharmacy.profile.hours_title')</h5>
                    @foreach ($daysOfWeek as $dayKey => $dayName)
                        <div class="form-row" style="padding: 12px 14px; background: #f8fafc; border: 1px solid #eef2f7; border-radius: 12px;">
                            <div class="fg">
                                <label class="fl-check" for="is_closed_{{ $dayKey }}">
                                    <input type="checkbox" name="hours[{{ $dayKey }}][is_closed]" id="is_closed_{{ $dayKey }}" value="1" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'checked' : '' }} onchange="toggleTimeInputs('{{ $dayKey }}')">
                                    {{ $dayName }} — @lang('pharmacy.profile.closed')
                                </label>
                            </div>
                            <div class="fg">
                                <label class="fl" for="open_time_{{ $dayKey }}">@lang('pharmacy.profile.from')</label>
                                <input type="time" name="hours[{{ $dayKey }}][open_time]" id="open_time_{{ $dayKey }}" class="fc @error('hours.' . $dayKey . '.open_time') is-invalid @enderror" value="{{ old('hours.' . $dayKey . '.open_time', $pharmacyHours[$dayKey]->open_time ?? '') }}" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'disabled' : '' }}>
                                @error('hours.' . $dayKey . '.open_time')
                                    <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                            <div class="fg">
                                <label class="fl" for="close_time_{{ $dayKey }}">@lang('pharmacy.profile.to')</label>
                                <input type="time" name="hours[{{ $dayKey }}][close_time]" id="close_time_{{ $dayKey }}" class="fc @error('hours.' . $dayKey . '.close_time') is-invalid @enderror" value="{{ old('hours.' . $dayKey . '.close_time', $pharmacyHours[$dayKey]->close_time ?? '') }}" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'disabled' : '' }}>
                                @error('hours.' . $dayKey . '.close_time')
                                    <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        </div>
                    @endforeach

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">@lang('pharmacy.profile.update_button')</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleTimeInputs(dayKey) {
            const isClosedCheckbox = document.getElementById(`is_closed_${dayKey}`);
            const openTimeInput = document.getElementById(`open_time_${dayKey}`);
            const closeTimeInput = document.getElementById(`close_time_${dayKey}`);

            if (isClosedCheckbox.checked) {
                openTimeInput.setAttribute('disabled', 'disabled');
                closeTimeInput.setAttribute('disabled', 'disabled');
                openTimeInput.value = ''; // Clear values when closed
                closeTimeInput.value = '';
            } else {
                openTimeInput.removeAttribute('disabled');
                closeTimeInput.removeAttribute('disabled');
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            @foreach ($daysOfWeek as $dayKey => $dayName)
                toggleTimeInputs('{{ $dayKey }}');
            @endforeach
        });
    </script>
@endsection
