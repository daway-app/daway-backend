@extends('layouts.app')

@section('title', __('pharmacy.profile.complete.title'))

@section('content')
    {{-- pharmacy_hub.js يهيّئ الخريطة (initMap) ويدير النوافذ — نفس آلية صفحة تعديل البروفايل --}}
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js', 'resources/css/pages/users_create.css'])
    @include('partials.pharmacy-hub-i18n')

    <div class="page-wrapper" style="max-width: 1100px;">
        <div class="main-card">

            <div class="card-header-modern">
                <div class="header-title-area">
                    <h2>@lang('pharmacy.profile.complete.heading')</h2>
                    <p>@lang('pharmacy.profile.complete.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
                </div>
                <div class="header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
            </div>

            <div class="complete-layout">

                <!-- العمود الأول: بيانات الصيدلية + كلمة المرور -->
                <div class="complete-col">

                    <!-- بطاقة الصيدلية -->
                    <div class="complete-hero">
                        <div class="complete-avatar">
                            <span>{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</span>
                        </div>
                        <div class="complete-hero-text">
                            <strong>{{ $pharmacy->pharmacy_name }}</strong>
                            <span class="complete-badge">@lang('pharmacy.profile.complete.title')</span>
                        </div>
                    </div>

                    <form action="{{ route('pharmacy.profile.complete') }}" method="POST">
                        @csrf

                        @if (session('success'))
                            <div class="success-alert-modern" style="margin-bottom: 16px;">{{ session('success') }}</div>
                        @endif
                        @if (session('error'))
                            <div class="alert-danger-modern" style="margin-bottom: 16px;">{{ session('error') }}</div>
                        @endif

                        @if ($errors->any())
                            <div class="alert-danger-modern" style="margin-bottom: 16px;">
                                <ul style="margin: 0; padding-right: 18px;">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.phone_label') <span>*</span></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                <input type="text" name="phone_number" id="phone_number" class="form-control" value="{{ old('phone_number', $pharmacy->phone_number) }}" required>
                            </div>
                            @error('phone_number')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.address_label') <span>*</span></label>
                            <div class="input-with-icon input-with-icon-top">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <textarea name="address" id="address" class="form-control" rows="2" required>{{ old('address', $pharmacy->address) }}</textarea>
                            </div>
                            @error('address')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.complete.region_label') <span>*</span></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <input type="text" name="region" id="region" class="form-control" value="{{ old('region', $pharmacy->region) }}" required>
                            </div>
                            @error('region')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.email_label')</label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}">
                            </div>
                            <p class="hint-under">@lang('pharmacy.profile.complete.email_hint')</p>
                            @error('email')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- قسم كلمة المرور (اختياري — الصيدلية اختارت كلمة مرورها عند التسجيل) -->
                        <div class="complete-security-head">
                            <div class="lock-ic"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></div>
                            <div>
                                <h3>@lang('pharmacy.profile.complete.password_section')</h3>
                                <p>@lang('pharmacy.profile.complete.password_optional_hint')</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.complete.new_password')</label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="password" id="newPass" class="form-control" minlength="8" autocomplete="new-password" placeholder="@lang('pharmacy.profile.complete.password_optional_placeholder')">
                                <button type="button" class="eye-toggle" onclick="togglePass('newPass', this)" tabindex="-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            @error('password')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('pharmacy.profile.complete.confirm_password')</label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="password_confirmation" id="confPass" class="form-control" autocomplete="new-password" placeholder="@lang('pharmacy.profile.complete.password_optional_placeholder')">
                                <button type="button" class="eye-toggle" onclick="togglePass('confPass', this)" tabindex="-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </div>

                </div>

                <!-- العمود الثاني: الموقع + ساعات العمل -->
                <div class="complete-col">

                    @push('scripts')
                        <link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' />
                        <script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'></script>
                    @endpush

                    <div class="complete-card">
                        <div class="complete-card-head">
                            <span class="complete-card-ic"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
                            <h3>@lang('pharmacy.profile.location_title')</h3>
                        </div>
                        <div class="complete-card-body">
                            {{-- الخريطة التفاعلية تُدار من pharmacy_hub.js (نفس حوار تعديل الموقع في البروفايل) --}}
                            <div id='pharmacyMapEdit' class='complete-map' data-lat='{{ old('latitude', 31.5016) }}' data-lng='{{ old('longitude', 34.4668) }}'></div>
                            <input type='hidden' name='latitude' id='latitude' value='{{ old('latitude') }}'>
                            <input type='hidden' name='longitude' id='longitude' value='{{ old('longitude') }}'>
                            <p class="hint-under">@lang('pharmacy.profile.map_hint')</p>
                            @error('latitude')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                            @error('longitude')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <div class="complete-card">
                        <div class="complete-card-head">
                            <span class="complete-card-ic"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
                            <h3>@lang('pharmacy.profile.hours_title') <span style="color:#ef4444;">*</span></h3>
                        </div>
                        <div class="complete-card-body">
                            <div class="complete-hours-quickbar">
                                <button type="button" class="btn-quick" onclick="applyPreset('unified')">@lang('pharmacy.profile.hours_quick.unified')</button>
                                <button type="button" class="btn-quick" onclick="applyPreset('24h')">@lang('pharmacy.profile.hours_quick.h24')</button>
                                <button type="button" class="btn-quick" onclick="applyPreset('friday_off')">@lang('pharmacy.profile.hours_quick.friday_off')</button>
                                <button type="button" class="btn-quick btn-quick-ghost" onclick="applyPreset('clear')">@lang('pharmacy.profile.hours_quick.clear')</button>
                            </div>

                            @foreach($daysOfWeek as $dayKey => $dayName)
                                @php
                                    $isClosed = old('hours.'.$dayKey.'.is_closed', false);
                                @endphp
                                <div class="complete-day-row">
                                    <label class="complete-day-label">
                                        <input type="checkbox" name="hours[{{ $dayKey }}][is_closed]" value="1" {{ $isClosed ? 'checked' : '' }} onchange="toggleTime('{{ $dayKey }}')">
                                        {{ $dayName }}
                                    </label>
                                    <div class="complete-day-times">
                                        <input type="time" name="hours[{{ $dayKey }}][open_time]" id="open_{{ $dayKey }}" class="form-control" value="{{ old('hours.'.$dayKey.'.open_time') }}" {{ $isClosed ? 'disabled' : '' }}>
                                        <span class="complete-day-sep">–</span>
                                        <input type="time" name="hours[{{ $dayKey }}][close_time]" id="close_{{ $dayKey }}" class="form-control" value="{{ old('hours.'.$dayKey.'.close_time') }}" {{ $isClosed ? 'disabled' : '' }}>
                                    </div>
                                    <div class="complete-day-quick">
                                        <button type="button" class="btn-mini" title="@lang('pharmacy.profile.hours_quick.copy_title')" onclick="copyDay('{{ $dayKey }}')">@lang('pharmacy.profile.hours_quick.copy')</button>
                                        <button type="button" class="btn-mini" title="@lang('pharmacy.profile.hours_quick.h24')" onclick="setDay('{{ $dayKey }}', '00:00', '23:59')">24h</button>
                                        <button type="button" class="btn-mini btn-mini-danger" title="@lang('pharmacy.profile.hours_quick.closed_title')" onclick="closeDay('{{ $dayKey }}')">@lang('pharmacy.profile.hours_quick.closed')</button>
                                    </div>
                                </div>
                            @endforeach
                            @error('hours')
                                <span class="field-error" style="display:block;">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <div class="complete-footer">
                        <button type="submit" class="btn-submit"><i class="fas fa-save"></i> @lang('pharmacy.profile.complete.save_button')</button>
                    </div>

                </div>
            </div>

                    </form>
        </div>

    </div>

    <script>
        var hoursDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('eye-active', show);
        }

        function setDay(day, open, close, closed) {
            var openEl = document.getElementById('open_'+day);
            var closeEl = document.getElementById('close_'+day);
            var closedEl = document.querySelector('input[name="hours['+day+'][is_closed]"]');
            closedEl.checked = !!closed;
            openEl.disabled = !!closed;
            closeEl.disabled = !!closed;
            if (!closed) {
                openEl.value = open;
                closeEl.value = close;
            }
        }

        function closeDay(day) { setDay(day, '', '', true); }

        function copyDay(day) {
            var open = document.getElementById('open_'+day).value;
            var close = document.getElementById('close_'+day).value;
            var closed = document.querySelector('input[name="hours['+day+'][is_closed]"]').checked;
            hoursDays.forEach(function(d) {
                if (d !== day) setDay(d, open, close, closed);
            });
        }

        function applyPreset(mode) {
            var fridayOff = ['Friday'];
            if (mode === 'unified' || mode === 'friday_off') {
                hoursDays.forEach(function(d) {
                    var closed = mode === 'friday_off' && fridayOff.indexOf(d) !== -1;
                    setDay(d, '09:00', '17:00', closed);
                });
            } else if (mode === '24h') {
                hoursDays.forEach(function(d) { setDay(d, '00:00', '23:59', false); });
            } else if (mode === 'clear') {
                hoursDays.forEach(function(d) { closeDay(d); });
            }
        }
    </script>

    <style>
        .complete-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            padding: 24px;
        }
        @media (max-width: 900px) {
            .complete-layout { grid-template-columns: 1fr; }
        }
        .complete-col {
            background: #f8fafc;
            border: 1px solid #DEE8E7;
            border-radius: 14px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        body.dark-mode .complete-col { background: var(--paper); border-color: var(--line); }

        .complete-hero {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-bottom: 18px;
            border-bottom: 1px solid #E2E8F0;
        }
        body.dark-mode .complete-hero { border-color: var(--line-strong); }
        .complete-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            padding: 3px;
            background: conic-gradient(#1C72A6, #7BC1B7, #3b82f6, #1C72A6);
            flex-shrink: 0;
            display: flex;
        }
        .complete-avatar > span {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #36a5a5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 26px;
            font-weight: 700;
        }
        .complete-hero-text { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .complete-hero-text strong { font-size: 17px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        body.dark-mode .complete-hero-text strong { color: #f4f4f5; }
        .complete-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            width: fit-content;
            background: rgba(6,182,212,0.12);
            color: #0891b2;
        }

        .complete-security-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 26px 0 18px;
            padding-top: 20px;
            border-top: 1px solid #E2E8F0;
        }
        body.dark-mode .complete-security-head { border-color: var(--line-strong); }
        .complete-security-head .lock-ic {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(28,114,166,0.1);
            color: #1C72A6;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .complete-security-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
        body.dark-mode .complete-security-head h3 { color: #f4f4f5; }
        .complete-security-head p { margin: 2px 0 0; font-size: 12.5px; color: #64748b; }
        body.dark-mode .complete-security-head p { color: var(--ink-soft); }

        .complete-card {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            overflow: hidden;
        }
        body.dark-mode .complete-card { background: var(--paper); border-color: var(--line); }
        .complete-card-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid #E2E8F0;
        }
        body.dark-mode .complete-card-head { border-color: var(--line); }
        .complete-card-head h3 { margin: 0; font-size: 14.5px; font-weight: 700; color: #0f172a; }
        body.dark-mode .complete-card-head h3 { color: #f4f4f5; }
        .complete-card-ic {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: rgba(28,114,166,0.1);
            color: #1C72A6;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .complete-card-body { padding: 16px; }

        .complete-map {
            height: 220px;
            border-radius: 10px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            z-index: 0;
        }
        body.dark-mode .complete-map { border-color: var(--line); }

        .complete-hours-quickbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .btn-quick {
            background: rgba(28,114,166,0.08);
            color: #1C72A6;
            border: none;
            border-radius: 9px;
            padding: 7px 13px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.2s ease;
        }
        .btn-quick:hover { background: rgba(28,114,166,0.16); }
        .btn-quick-ghost { background: transparent; border: 1px solid #cbd5e1; color: #64748b; }
        .btn-quick-ghost:hover { background: #f1f5f9; }
        body.dark-mode .btn-quick-ghost { border-color: var(--line-strong); color: var(--ink-soft); }
        body.dark-mode .btn-quick-ghost:hover { background: var(--line-soft); }

        .complete-day-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed #E2E8F0;
            flex-wrap: wrap;
        }
        body.dark-mode .complete-day-row { border-color: var(--line); }
        .complete-day-row:last-of-type { border-bottom: none; }
        .complete-day-label {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            width: 110px;
            flex-shrink: 0;
            cursor: pointer;
        }
        body.dark-mode .complete-day-label { color: var(--ink); }
        .complete-day-times {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 200px;
        }
        .complete-day-sep { color: #94a3b8; }
        .complete-day-times .form-control { padding: 7px 10px; font-size: 12.5px; }
        .complete-day-quick { display: flex; gap: 5px; }
        .btn-mini {
            background: #f1f5f9;
            border: none;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.2s ease;
        }
        .btn-mini:hover { background: #e2e8f0; }
        .btn-mini-danger { color: #dc2626; background: #fef2f2; }
        .btn-mini-danger:hover { background: #fee2e2; }
        body.dark-mode .btn-mini { background: var(--paper); color: var(--ink); }
        body.dark-mode .btn-mini:hover { background: var(--line-soft); }
        body.dark-mode .btn-mini-danger { background: rgba(239,68,68,0.12); color: #fca5a5; }

        .complete-footer {
            margin-top: auto;
            padding-top: 6px;
        }
        .complete-footer .btn-submit { width: 100%; padding: 13px; font-size: 14px; }

        .input-with-icon { position: relative; }
        .input-with-icon > svg:first-child {
            position: absolute;
            inset-inline-start: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            z-index: 1;
        }
        .input-with-icon.input-with-icon-top > svg:first-child { top: 20px; transform: none; }
        .input-with-icon .form-control { padding-inline-start: 38px; }
        .input-with-icon .form-control[type="password"] { padding-inline-end: 38px; }
        textarea.form-control { resize: vertical; min-height: 70px; }
        .field-error { color: #dc2626; font-size: 12px; margin-top: 5px; display: block; }
        body.dark-mode .field-error { color: #fca5a5; }
        .hint-under { margin: 6px 0 0; font-size: 12px; color: #94a3b8; }
        body.dark-mode .hint-under { color: var(--ink-faint); }
        .eye-toggle {
            position: absolute;
            inset-inline-end: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: color 0.2s ease, background-color 0.2s ease;
        }
        .eye-toggle:hover { color: #1C72A6; background: rgba(28,114,166,0.08); }
        .eye-toggle.eye-active { color: #1C72A6; }
    </style>
@endsection
