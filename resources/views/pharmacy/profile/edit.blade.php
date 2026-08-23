@extends('layouts.app')

@section('title', __('pharmacy.profile.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @push('scripts')
        <link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' />
        <script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'></script>
    @endpush

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.profile.heading_page')</h1>
                <p>@lang('pharmacy.profile.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <form action='{{ route('pharmacy.profile.update') }}' method='POST' enctype='multipart/form-data'>
            @csrf
            @method('PUT')

            <div class='ph-profile-grid'>
                <div class='ph-profile-side'>
                    <div class='ph-card'>
                        <div class='ph-card-head'><h2><i class='fas fa-map-marker-alt'></i> @lang('pharmacy.profile.location_title')</h2></div>
                        <div class='ph-card-body'>
                            <div class='ph-form-row' style='grid-template-columns:1fr 1fr;margin-block-end:12px;'>
                                <div class='ph-group'>
                                    <label class='ph-form-label' for='latitude'>@lang('pharmacy.profile.latitude_label')</label>
                                    <input type='text' name='latitude' id='latitude' class='ph-control' value='{{ old('latitude', $pharmacy->latitude) }}' required>
                                </div>
                                <div class='ph-group'>
                                    <label class='ph-form-label' for='longitude'>@lang('pharmacy.profile.longitude_label')</label>
                                    <input type='text' name='longitude' id='longitude' class='ph-control' value='{{ old('longitude', $pharmacy->longitude) }}' required>
                                </div>
                            </div>
                            <div id='pharmacyMap' class='ph-map ph-map-sm' data-lat='{{ old('latitude', $pharmacy->latitude) }}' data-lng='{{ old('longitude', $pharmacy->longitude) }}'></div>
                            <p class='ph-hint'>@lang('pharmacy.profile.map_hint')</p>
                        </div>
                    </div>

                    <div class='ph-card'>
                        <div class='ph-card-head'><h2><i class='fas fa-clock'></i> @lang('pharmacy.profile.hours_title')</h2></div>
                        <div class='ph-card-body ph-hours-compact'>
                            @foreach($daysOfWeek as $dayKey => $dayName)
                                @php
                                    $hour = $pharmacyHours[$dayKey] ?? null;
                                    $isClosed = old('hours.'.$dayKey.'.is_closed', $hour?->is_closed ?? false);
                                @endphp
                                <div class='hc-row'>
                                    <span class='hc-day'>{{ $dayName }}</span>
                                    <span class='hc-time'>
                                        @if($isClosed) @lang('pharmacy.profile.closed') @else
                                            {{ old('hours.'.$dayKey.'.close_time', $hour?->close_time ?? '') }} – {{ old('hours.'.$dayKey.'.open_time', $hour?->open_time ?? '') }}
                                        @endif
                                    </span>
                                    <i class='fas fa-calendar-days'></i>
                                </div>
                            @endforeach
                        </div>
                        <details class='ph-hours-editor'>
                            <summary class='ph-btn sm outline' style='width:calc(100% - 44px);margin:0 22px 18px;'><i class='fas fa-pen'></i> @lang('pharmacy.profile.edit_hours')</summary>
                            <div class='ph-card-body ph-hours' style='padding-block-start:0;'>
                                @foreach($daysOfWeek as $dayKey => $dayName)
                                    @php
                                        $hour = $pharmacyHours[$dayKey] ?? null;
                                        $isClosed = old('hours.'.$dayKey.'.is_closed', $hour?->is_closed ?? false);
                                    @endphp
                                    <div class='day-row'>
                                        <label>
                                            <input type='checkbox' name='hours[{{ $dayKey }}][is_closed]' value='1' {{ $isClosed ? 'checked' : '' }} onchange='toggleTime("{{ $dayKey }}")'>
                                            {{ $dayName }}
                                        </label>
                                        <div class='ph-group'>
                                            <label class='ph-form-label'>@lang('pharmacy.profile.from')</label>
                                            <input type='time' name='hours[{{ $dayKey }}][open_time]' id='open_{{ $dayKey }}' class='ph-control' value='{{ old('hours.'.$dayKey.'.open_time', $hour?->open_time ?? '') }}' {{ $isClosed ? 'disabled' : '' }}>
                                        </div>
                                        <div class='ph-group'>
                                            <label class='ph-form-label'>@lang('pharmacy.profile.to')</label>
                                            <input type='time' name='hours[{{ $dayKey }}][close_time]' id='close_{{ $dayKey }}' class='ph-control' value='{{ old('hours.'.$dayKey.'.close_time', $hour?->close_time ?? '') }}' {{ $isClosed ? 'disabled' : '' }}>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </div>
                </div>

                <div class='ph-profile-main'>
                    <div class='ph-card'>
                        <div class='ph-banner'>
                            <div>
                                <h2>{{ $pharmacy->pharmacy_name }}</h2>
                                <p>@lang('pharmacy.profile.tagline')</p>
                            </div>
                            @if($pharmacy->logo)
                                <img src='{{ \App\Support\Image::url($pharmacy->logo) }}' alt='{{ $pharmacy->pharmacy_name }}' class='ph-avatar'>
                            @else
                                <div class='ph-avatar' style='display:grid;place-items:center;background:var(--ph-teal-mist);color:var(--ph-teal);font-size:2rem;font-weight:700;'>{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</div>
                            @endif
                        </div>
                        <div class='ph-card-body'>
                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='pharmacy_name'>@lang('pharmacy.profile.name_label') <span class='req'>*</span></label>
                                <input type='text' name='pharmacy_name' id='pharmacy_name' class='ph-control' value='{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}' required>
                                @error('pharmacy_name')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            @if(isset($pharmacy->email))
                                <div class='ph-group' style='margin-block-end:18px;'>
                                    <label class='ph-form-label' for='email'>@lang('pharmacy.profile.email_label') <span class='req'>*</span></label>
                                    <input type='email' name='email' id='email' class='ph-control' value='{{ old('email', $pharmacy->email) }}'>
                                    @error('email')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                                </div>
                            @endif

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='phone_number'>@lang('pharmacy.profile.phone_label') <span class='req'>*</span></label>
                                <input type='text' name='phone_number' id='phone_number' class='ph-control' value='{{ old('phone_number', $pharmacy->phone_number) }}' required>
                                @error('phone_number')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='address'>@lang('pharmacy.profile.address_label') <span class='req'>*</span></label>
                                <textarea name='address' id='address' class='ph-textarea' style='width:100%;' required>{{ old('address', $pharmacy->address) }}</textarea>
                                @error('address')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='region'>@lang('pharmacy.profile.complete.region_label') <span class='req'>*</span></label>
                                <input type='text' name='region' id='region' class='ph-control' value='{{ old('region', $pharmacy->region) }}' required>
                                @error('region')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div class='ph-group'>
                                <label class='ph-form-label' for='logo'>@lang('pharmacy.profile.logo_label')</label>
                                <input type='file' name='logo' id='logo' class='ph-control' accept='image/*' style='height:auto;padding:10px;'>
                                @error('logo')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div style='display:flex;gap:10px;padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>
                            <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> @lang('pharmacy.profile.save_button')</button>
                            <a href='{{ route('pharmacy.dashboard.index') }}' class='ph-btn ghost'>@lang('pharmacy.profile.cancel_button')</a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function toggleTime(day) {
            const closed = document.querySelector('input[name="hours['+day+'][is_closed]"]').checked;
            document.getElementById('open_'+day).disabled = closed;
            document.getElementById('close_'+day).disabled = closed;
        }
    </script>
@endsection