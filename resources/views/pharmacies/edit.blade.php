@extends('layouts.app')

@section('title', __('pharmacies.edit_pharmacy_title'))

@section('breadcrumb')
    <span class="sep">›</span>
    <a href="{{ route('pharmacies.index') }}">@lang('pharmacies.breadcrumb_current')</a>
    <span class="sep">›</span>
    <span class="cur">@lang('pharmacies.edit_pharmacy_breadcrumb')</span>
@endsection

@section('content')
    <!-- Leaflet.js for map selection -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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

        <form action="{{ route('pharmacies.update', $pharmacy->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card">
                <div class="card-head"><h2>@lang('pharmacies.pharmacy_details_section')</h2></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.pharmacy_name_label') <span class="req">*</span></label>
                            <input class="fc" type="text" name="pharmacy_name" placeholder="@lang('pharmacies.pharmacy_name_placeholder')" value="{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}" required>
                            @error('pharmacy_name')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.phone_number_label') <span class="req">*</span></label>
                            <div class="fc-icon">
                                <span class="ico">📱</span>
                                <input class="fc" type="tel" name="phone_number" placeholder="@lang('pharmacies.phone_number_placeholder')" value="{{ old('phone_number', $pharmacy->phone_number) }}" required>
                            </div>
                            @error('phone_number')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.address_line_label') <span class="req">*</span></label>
                            <input class="fc" type="text" name="address_line" placeholder="@lang('pharmacies.address_line_placeholder')" value="{{ old('address_line', $pharmacy->address_line) }}" required>
                            @error('address_line')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.city_label') <span class="req">*</span></label>
                            <select class="fc" name="city" required>
                                <option value="">@lang('pharmacies.city_placeholder')</option>
                                <option value="Gaza" {{ old('city', $pharmacy->city) == 'Gaza' ? 'selected' : '' }}>@lang('pharmacies.city_gaza')</option>
                                <option value="Khan Yunis" {{ old('city', $pharmacy->city) == 'Khan Yunis' ? 'selected' : '' }}>@lang('pharmacies.city_khan_yunis')</option>
                                <option value="Rafah" {{ old('city', $pharmacy->city) == 'Rafah' ? 'selected' : '' }}>@lang('pharmacies.city_rafah')</option>
                                <option value="Deir al-Balah" {{ old('city', $pharmacy->city) == 'Deir al-Balah' ? 'selected' : '' }}>@lang('pharmacies.city_deir_al_balah')</option>
                                <option value="North" {{ old('city', $pharmacy->city) == 'North' ? 'selected' : '' }}>@lang('pharmacies.city_north')</option>
                                <option value="Nablus" {{ old('city', $pharmacy->city) == 'Nablus' ? 'selected' : '' }}>@lang('pharmacies.city_nablus')</option>
                                <option value="Ramallah" {{ old('city', $pharmacy->city) == 'Ramallah' ? 'selected' : '' }}>@lang('pharmacies.city_ramallah')</option>
                                <option value="Hebron" {{ old('city', $pharmacy->city) == 'Hebron' ? 'selected' : '' }}>@lang('pharmacies.city_hebron')</option>
                            </select>
                            @error('city')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="fg">
                        <label class="fl">@lang('pharmacies.area_label')</label>
                        <input class="fc" name="area" placeholder="@lang('pharmacies.area_placeholder')" value="{{ old('area', $pharmacy->area) }}">
                        @error('area')<div class="error-message">{{ $message }}</div>@enderror
                    </div>

                    <div class="fg">
                        <div class="map-title-row">
                            <label class="fl">@lang('pharmacies.location_on_map_section')</label>
                            <button type="button" id="getCurrentLocationBtn" class="btn-get-location" title="@lang('pharmacies.get_current_location')">
                                <svg class="location-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <svg class="loading-spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                            </button>
                        </div>
                        <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                            <input class="fc" type="text" id="latitude" name="latitude" placeholder="@lang('pharmacies.latitude_placeholder')" value="{{ old('latitude', $pharmacy->latitude) }}" readonly style="background: #f8fafc;">
                            <input class="fc" type="text" id="longitude" name="longitude" placeholder="@lang('pharmacies.longitude_placeholder')" value="{{ old('longitude', $pharmacy->longitude) }}" readonly style="background: #f8fafc;">
                        </div>
                        <div id="map"></div>
                        <div class="form-hint">@lang('pharmacies.map_location_hint')</div>
                        @error('latitude')<div class="error-message">{{ $message }}</div>@enderror
                        @error('longitude')<div class="error-message">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>@lang('pharmacies.user_account_section')</h2></div>
                <div class="card-body">
                    <p class="card-section-desc" style="margin-bottom: 20px;">@lang('pharmacies.login_credentials_desc')</p>
                    <div class="fg">
                        <label class="fl">@lang('pharmacies.email_label') <span class="req">*</span></label>
                        <input class="fc" type="email" name="email" placeholder="@lang('pharmacies.email_placeholder')" value="{{ old('email', $pharmacy->user->email) }}" required>
                        @error('email')<div class="error-message">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.password_label')</label>
                            <div class="fc-icon">
                                <span class="ico">🔒</span>
                                <input class="fc" type="password" name="password" placeholder="@lang('pharmacies.password_placeholder_edit')">
                            </div>
                            @error('password')<div class="error-message">{{ $message }}</div>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl">@lang('pharmacies.password_confirmation_label')</label>
                            <div class="fc-icon">
                                <span class="ico">🔒</span>
                                <input class="fc" type="password" name="password_confirmation" placeholder="@lang('pharmacies.confirm_password_placeholder')">
                            </div>
                            @error('password_confirmation')<div class="error-message">{{ $message }}</div>@enderror
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

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var defaultLat = {{ old('latitude', $pharmacy->latitude) }};
            var defaultLng = {{ old('longitude', $pharmacy->longitude) }};

            var map = L.map('map').setView([defaultLat, defaultLng], 12);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);

            function updateInputs(lat, lng) {
                document.getElementById('latitude').value = lat.toFixed(6);
                document.getElementById('longitude').value = lng.toFixed(6);
            }

            updateInputs(defaultLat, defaultLng);

            map.on('click', function (e) {
                var lat = e.latlng.lat;
                var lng = e.latlng.lng;
                marker.setLatLng([lat, lng]);
                updateInputs(lat, lng);
            });

            marker.on('dragend', function (e) {
                var lat = marker.getLatLng().lat;
                var lng = marker.getLatLng().lng;
                updateInputs(lat, lng);
            });

            const getLocationBtn = document.getElementById('getCurrentLocationBtn');
            getLocationBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    showToast("@lang('pharmacies.location_not_supported')", 'warning');
                    return;
                }

                this.classList.add('loading');
                this.disabled = true;

                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    updateInputs(lat, lng);

                    getLocationBtn.classList.remove('loading');
                    getLocationBtn.disabled = false;
                }, function(error) {
                    showToast("@lang('pharmacies.location_permission_denied')", 'error');
                    getLocationBtn.classList.remove('loading');
                    getLocationBtn.disabled = false;
                }, { enableHighAccuracy: true });
            });
        });
    </script>
@endsection
