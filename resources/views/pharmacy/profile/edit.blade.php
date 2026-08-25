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

        <form action='{{ route('pharmacy.profile.update') }}' method='POST' enctype='multipart/form-data' class='ph-profile-form'>
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
                                    <input type='text' name='latitude' id='latitude' class='ph-control' value='{{ old('latitude', $pharmacy->latitude) }}'>
                                </div>
                                <div class='ph-group'>
                                    <label class='ph-form-label' for='longitude'>@lang('pharmacy.profile.longitude_label')</label>
                                    <input type='text' name='longitude' id='longitude' class='ph-control' value='{{ old('longitude', $pharmacy->longitude) }}'>
                                </div>
                            </div>
                            <div id='pharmacyMap' class='ph-map ph-map-sm' data-lat='{{ old('latitude', $pharmacy->latitude) }}' data-lng='{{ old('longitude', $pharmacy->longitude) }}'></div>
                            <p class='ph-hint'>@lang('pharmacy.profile.map_hint')</p>
                        </div>
                    </div>

                    <div class='ph-card ph-hours-card'>
                        <div class='ph-card-head'>
                            <h2><i class='fas fa-clock'></i> @lang('pharmacy.profile.hours_title')</h2>
                            <button type='button' class='ph-btn sm outline' id='hoursEditBtn' onclick='toggleHoursEdit()'><i class='fas fa-pen'></i> @lang('pharmacy.profile.edit_hours')</button>
                        </div>
                        <div class='ph-card-body ph-hours-compact' id='hoursList'>
                            <div class='ph-hours-quickbar'>
                                <button type='button' class='ph-btn sm' onclick='applyPreset("unified")'>@lang('pharmacy.profile.hours_quick.unified')</button>
                                <button type='button' class='ph-btn sm' onclick='applyPreset("24h")'>@lang('pharmacy.profile.hours_quick.h24')</button>
                                <button type='button' class='ph-btn sm' onclick='applyPreset("friday_off")'>@lang('pharmacy.profile.hours_quick.friday_off')</button>
                                <button type='button' class='ph-btn sm outline' onclick='applyPreset("clear")'>@lang('pharmacy.profile.hours_quick.clear')</button>
                            </div>
                            @foreach($daysOfWeek as $dayKey => $dayName)
                                @php
                                    $hour = $pharmacyHours[$dayKey] ?? null;
                                    $isClosed = old('hours.'.$dayKey.'.is_closed', $hour?->is_closed ?? false);
                                    $openVal = old('hours.'.$dayKey.'.open_time', $hour?->open_time?->format('H:i'));
                                    $closeVal = old('hours.'.$dayKey.'.close_time', $hour?->close_time?->format('H:i'));
                                @endphp
                                <div class='hc-row' data-day='{{ $dayKey }}'>
                                    <span class='hc-day'>{{ $dayName }}</span>
                                    <span class='hc-time'>
                                        @if($isClosed) @lang('pharmacy.profile.closed') @else
                                            {{ $closeVal }} – {{ $openVal }}
                                        @endif
                                    </span>
                                    <span class='hc-edit'>
                                        <label class='hc-closed'>
                                            <input type='checkbox' name='hours[{{ $dayKey }}][is_closed]' value='1' {{ $isClosed ? 'checked' : '' }} onchange='toggleTime("{{ $dayKey }}")'>
                                            @lang('pharmacy.profile.closed')
                                        </label>
                                        <input type='text' inputmode='numeric' maxlength='5' placeholder='09:00' name='hours[{{ $dayKey }}][open_time]' id='open_{{ $dayKey }}' class='ph-control hc-time-input' value='{{ $openVal }}' {{ $isClosed ? 'disabled' : '' }} oninput='formatTimeInput(this)' onchange='formatTimeInput(this, true)'>
                                        <span class='hc-dash'>–</span>
                                        <input type='text' inputmode='numeric' maxlength='5' placeholder='17:00' name='hours[{{ $dayKey }}][close_time]' id='close_{{ $dayKey }}' class='ph-control hc-time-input' value='{{ $closeVal }}' {{ $isClosed ? 'disabled' : '' }} oninput='formatTimeInput(this)' onchange='formatTimeInput(this, true)'>
                                        <span class='day-quick'>
                                            <button type='button' class='ph-btn xs' title='@lang('pharmacy.profile.hours_quick.copy_title')' onclick='copyDay("{{ $dayKey }}")'>@lang('pharmacy.profile.hours_quick.copy')</button>
                                            <button type='button' class='ph-btn xs' title='@lang('pharmacy.profile.hours_quick.h24')' onclick='setDay("{{ $dayKey }}", "00:00", "23:59")'>24h</button>
                                            <button type='button' class='ph-btn xs' title='@lang('pharmacy.profile.hours_quick.closed_title')' onclick='closeDay("{{ $dayKey }}")'>@lang('pharmacy.profile.hours_quick.closed')</button>
                                        </span>
                                    </span>
                                    <i class='fas fa-calendar-days'></i>
                                </div>
                            @endforeach
                            @php($hoursError = collect($errors->keys())->first(fn ($k) => str_starts_with($k, 'hours.')))
                            @if($hoursError)
                                <span style='display:block;color:var(--ph-red);font-size:.8rem;padding-block-start:10px;'>{{ $errors->first($hoursError) }}</span>
                            @endif
                        </div>
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
                                <img src='{{ \App\Support\Image::url($pharmacy->logo) }}' alt='{{ $pharmacy->pharmacy_name }}' class='ph-avatar' style='cursor:pointer;' title='@lang('pharmacy.profile.logo_change')' onclick='openModal("logoModal")'>
                            @else
                                <div class='ph-avatar' style='display:grid;place-items:center;background:var(--ph-teal-mist);color:var(--ph-teal);font-size:2rem;font-weight:700;cursor:pointer;' title='@lang('pharmacy.profile.logo_change')' onclick='openModal("logoModal")'>{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</div>
                            @endif
                        </div>
                        <div class='ph-card-body'>
                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='pharmacy_name'>@lang('pharmacy.profile.name_label')</label>
                                <input type='text' name='pharmacy_name' id='pharmacy_name' class='ph-control' value='{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}'>
                                @error('pharmacy_name')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            @if(isset($pharmacy->email))
                                <div class='ph-group' style='margin-block-end:18px;'>
                                    <label class='ph-form-label' for='email'>@lang('pharmacy.profile.email_label')</label>
                                    <input type='email' name='email' id='email' class='ph-control' value='{{ old('email', $pharmacy->email) }}'>
                                    @error('email')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                                </div>
                            @endif

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='phone_number'>@lang('pharmacy.profile.phone_label')</label>
                                <input type='text' name='phone_number' id='phone_number' class='ph-control' value='{{ old('phone_number', $pharmacy->phone_number) }}'>
                                @error('phone_number')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='address'>@lang('pharmacy.profile.address_label')</label>
                                <textarea name='address' id='address' class='ph-textarea' style='width:100%;'>{{ old('address', $pharmacy->address) }}</textarea>
                                @error('address')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div class='ph-group' style='margin-block-end:18px;'>
                                <label class='ph-form-label' for='region'>@lang('pharmacy.profile.complete.region_label')</label>
                                <input type='text' name='region' id='region' class='ph-control' value='{{ old('region', $pharmacy->region) }}'>
                                @error('region')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                            </div>

                            <div style='display:flex;gap:24px;flex-wrap:wrap;margin-block-start:10px;'>
                                <button type='button' class='ph-text-action' onclick='openModal("logoModal")'><i class='fas fa-camera'></i> @lang('pharmacy.profile.logo_change')</button>
                                <button type='button' class='ph-text-action' onclick='openModal("passwordModal")'><i class='fas fa-key'></i> @lang('pharmacy.profile.password_change.title')</button>
                            </div>
                        </div>
                        <div style='display:flex;gap:10px;padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>
                            <button type='submit' class='ph-btn primary'><i class='fas fa-save'></i> @lang('pharmacy.profile.save_button')</button>
                            <a href='{{ route('pharmacy.dashboard.index') }}' class='ph-btn ghost'>@lang('pharmacy.profile.cancel_button')</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- حوار تغيير كلمة المرور -->
            <div class='ph-modal-overlay' id='passwordModal' role='dialog' aria-modal='true'>
                <div class='ph-modal'>
                    <div class='ph-modal-head'>
                        <h3>@lang('pharmacy.profile.password_change.title')</h3>
                        <button type='button' class='ph-close' onclick='closeModal("passwordModal")'>&times;</button>
                    </div>
                    <div class='ph-modal-body'>
                        <p class='ph-hint' style='margin-block-end:14px;'>@lang('pharmacy.profile.password_change.hint')</p>
                        <div class='ph-group' style='margin-block-end:18px;'>
                            <label class='ph-form-label' for='current_password'>@lang('pharmacy.profile.password_change.current_password')</label>
                            <input type='password' name='current_password' id='current_password' class='ph-control'>
                            @error('current_password')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group' style='margin-block-end:18px;'>
                            <label class='ph-form-label' for='new_password'>@lang('pharmacy.profile.password_change.new_password')</label>
                            <input type='password' name='password' id='new_password' class='ph-control'>
                            <p class='ph-hint'>@lang('pharmacy.profile.password_change.password_hint')</p>
                            @error('password')<span style='color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        </div>
                        <div class='ph-group'>
                            <label class='ph-form-label' for='password_confirmation'>@lang('pharmacy.profile.password_change.confirm_password')</label>
                            <input type='password' name='password_confirmation' id='password_confirmation' class='ph-control'>
                        </div>
                    </div>
                    <div class='ph-modal-foot'>
                        <button type='button' class='ph-btn ghost' onclick='closeModal("passwordModal")'>@lang('pharmacy.profile.cancel_button')</button>
                        <button type='button' class='ph-btn primary' onclick='saveFromModal("passwordModal")'>@lang('pharmacy.profile.save_button')</button>
                    </div>
                </div>
            </div>

            <!-- حوار تغيير شعار الصيدلية -->
            <div class='ph-modal-overlay' id='logoModal' role='dialog' aria-modal='true'>
                <div class='ph-modal'>
                    <div class='ph-modal-head'>
                        <h3>@lang('pharmacy.profile.logo_label')</h3>
                        <button type='button' class='ph-close' onclick='closeModal("logoModal")'>&times;</button>
                    </div>
                    <div class='ph-modal-body' style='text-align:center;'>
                        @if($pharmacy->logo)
                            <img src='{{ \App\Support\Image::url($pharmacy->logo) }}' alt='' id='logoPreview' class='ph-logo-preview'>
                        @else
                            <div id='logoPreviewPlaceholder' class='ph-logo-preview' style='display:grid;place-items:center;background:var(--ph-teal-mist);color:var(--ph-teal);font-size:2.2rem;font-weight:700;'>{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</div>
                        @endif
                        <input type='file' name='logo' id='logoInput' accept='image/*' style='display:none;' onchange='previewLogo(this)'>
                        <button type='button' class='ph-btn sm outline' style='margin-block-start:14px;' onclick='document.getElementById("logoInput").click()'>@lang('pharmacy.profile.choose_image')</button>
                        @error('logo')<span style='display:block;color:var(--ph-red);font-size:.8rem;margin-block-start:10px;'>{{ $message }}</span>@enderror
                    </div>
                    <div class='ph-modal-foot'>
                        <button type='button' class='ph-btn ghost' onclick='closeModal("logoModal")'>@lang('pharmacy.profile.cancel_button')</button>
                        <button type='button' class='ph-btn primary' onclick='saveFromModal("logoModal")'>@lang('pharmacy.profile.save_button')</button>
                    </div>
                </div>
            </div>

            @if($errors->hasAny(['current_password', 'password', 'password_confirmation']))
                <script>document.addEventListener('DOMContentLoaded', function () { openModal('passwordModal'); });</script>
            @elseif($errors->has('logo'))
                <script>document.addEventListener('DOMContentLoaded', function () { openModal('logoModal'); });</script>
            @endif

            @if(isset($hoursError) && $hoursError)
                <script>document.addEventListener('DOMContentLoaded', function () { toggleHoursEdit(); });</script>
            @endif
        </form>
    </div>

    <script>
        // تعديل الساعات في نفس المكان: تبديل عرض/تحرير بدون أي قفز بالصفحة
        function toggleHoursEdit() {
            var list = document.getElementById('hoursList');
            var btn = document.getElementById('hoursEditBtn');
            var editing = list.classList.toggle('editing');
            btn.innerHTML = editing
                ? '<i class="fas fa-check"></i> @lang('pharmacy.profile.done')'
                : '<i class="fas fa-pen"></i> @lang('pharmacy.profile.edit_hours')';
            if (editing) {
                var first = list.querySelector('.hc-time-input:not(:disabled)');
                if (first) { first.focus({ preventScroll: true }); }
            }
        }

        // إجبار صيغة 24 ساعة: أثناء الكتابة أرقام فقط (حد 4 أرقام)، وعند الخروج تُكمل الصيغة HH:MM
        function formatTimeInput(el, strict) {
            var d = el.value.replace(/\D/g, '').slice(0, 4);
            if (!strict) {
                el.value = d;
                return;
            }
            if (d.length === 0) { el.value = ''; return; }
            var h, m;
            if (d.length <= 2) {
                h = d; m = '00';          // "9" أو "09" → 09:00
            } else if (d.length === 3) {
                h = d.slice(0, 1); m = d.slice(1);  // "900" → 09:00
            } else {
                h = d.slice(0, 2); m = d.slice(2, 4);  // "0930" → 09:30
            }
            var hh = Math.min(parseInt(h, 10) || 0, 23);
            var mm = Math.min(parseInt(m, 10) || 0, 59);
            el.value = ('0' + hh).slice(-2) + ':' + ('0' + mm).slice(-2);
        }

        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function saveFromModal(id) {
            closeModal(id);
            var form = document.querySelector('form.ph-profile-form');
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }

        function previewLogo(input) {
            var file = input.files && input.files[0];
            if (!file) return;
            var img = document.getElementById('logoPreview');
            var placeholder = document.getElementById('logoPreviewPlaceholder');
            if (!img) {
                img = document.createElement('img');
                img.id = 'logoPreview';
                img.className = 'ph-logo-preview';
                img.alt = '';
                if (placeholder) { placeholder.parentNode.replaceChild(img, placeholder); }
                else { input.parentNode.insertBefore(img, input); }
            }
            img.src = URL.createObjectURL(file);
            img.style.display = 'block';
            if (placeholder) { placeholder.style.display = 'none'; }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.ph-modal-overlay.active').forEach(function (m) { m.classList.remove('active'); });
            }
        });

        function toggleTime(day) {
            const closed = document.querySelector('input[name="hours['+day+'][is_closed]"]').checked;
            document.getElementById('open_'+day).disabled = closed;
            document.getElementById('close_'+day).disabled = closed;
        }

        var hoursDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

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
@endsection