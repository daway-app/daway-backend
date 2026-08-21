@extends('layouts.app')

@section('title', __('pharmacy.profile.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    @push('scripts')
        <link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' />
        <script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'></script>
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    @endpush

    <div class='ph-page'>
        <div class='ph-banner'>
            <div>
                <h2>{{ $pharmacy->pharmacy_name }}</h2>
                <p>{{ $pharmacy->address }}</p>
            </div>
            @if($pharmacy->logo)
                <img src='{{ \App\Support\Image::url($pharmacy->logo) }}' alt='{{ $pharmacy->pharmacy_name }}' class='ph-avatar'>
            @else
                <div class='ph-avatar' style='display:grid;place-items:center;background:var(--ph-teal-mist);color:var(--ph-teal);font-size:2rem;font-weight:700;'>{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</div>
            @endif
        </div>

        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>الملف التعريفي للصيدلية</h1>
                <p>تعديل بيانات الصيدلية وساعات العمل والموقع</p>
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

            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-info-circle'></i> البيانات الأساسية</h2></div>
                <div class='ph-card-body'>
                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='pharmacy_name'>اسم الصيدلية <span class='req'>*</span></label>
                            <input type='text' name='pharmacy_name' id='pharmacy_name' class='ph-control' value='{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}' required>
                            @error('pharmacy_name')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='phone_number'>رقم التواصل <span class='req'>*</span></label>
                            <input type='text' name='phone_number' id='phone_number' class='ph-control' value='{{ old('phone_number', $pharmacy->phone_number) }}' required>
                            @error('phone_number')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='address'>العنوان <span class='req'>*</span></label>
                            <input type='text' name='address' id='address' class='ph-control' value='{{ old('address', $pharmacy->address) }}' required>
                            @error('address')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='logo'>شعار الصيدلية</label>
                            <input type='file' name='logo' id='logo' class='ph-control' accept='image/*' style='height:auto;padding:10px;'>
                            @error('logo')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-map-marker-alt'></i> تحديد الموقع</h2></div>
                <div class='ph-card-body'>
                    <div class='ph-form-row'>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='latitude'>خط العرض</label>
                            <input type='text' name='latitude' id='latitude' class='ph-control' value='{{ old('latitude', $pharmacy->latitude) }}' required>
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='longitude'>خط الطول</label>
                            <input type='text' name='longitude' id='longitude' class='ph-control' value='{{ old('longitude', $pharmacy->longitude) }}' required>
                        </div>
                    </div>
                    <div id='pharmacyMap' class='ph-map' data-lat='{{ old('latitude', $pharmacy->latitude) }}' data-lng='{{ old('longitude', $pharmacy->longitude) }}'></div>
                    <p class='ph-hint'>انقر على الخريطة أو حرك الدبوس لتحديد الموقع بدقة</p>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'><h2><i class='fas fa-clock'></i> ساعات العمل</h2></div>
                <div class='ph-card-body ph-hours'>
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
                                <label class='ph-form-label'>من</label>
                                <input type='time' name='hours[{{ $dayKey }}][open_time]' id='open_{{ $dayKey }}' class='ph-control' value='{{ old('hours.'.$dayKey.'.open_time', $hour?->open_time ?? '') }}' {{ $isClosed ? 'disabled' : '' }}>
                            </div>
                            <div class='ph-group'>
                                <label class='ph-form-label'>إلى</label>
                                <input type='time' name='hours[{{ $dayKey }}][close_time]' id='close_{{ $dayKey }}' class='ph-control' value='{{ old('hours.'.$dayKey.'.close_time', $hour?->close_time ?? '') }}' {{ $isClosed ? 'disabled' : '' }}>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div style='display:flex;gap:10px;margin-block-start:20px;'>
                <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> حفظ التعديلات</button>
                <a href='{{ route('pharmacy.dashboard.index') }}' class='ph-btn ghost'>إلغاء</a>
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