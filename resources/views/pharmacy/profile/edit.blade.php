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
                            {{-- خريطة عرض فقط — غير تفاعلية، التعديل من الحوار --}}
                            <div id='pharmacyMap' class='ph-map ph-map-sm' data-lat='{{ old('latitude', $pharmacy->latitude) }}' data-lng='{{ old('longitude', $pharmacy->longitude) }}'></div>
                            <p class='ph-hint'>@lang('pharmacy.profile.map_display_hint')</p>
                            <button type='button' class='ph-text-action' style='margin-block-start:12px;' onclick='openLocationModal()'><i class='fas fa-location-dot'></i> @lang('pharmacy.profile.location_change')</button>
                        </div>
                    </div>

                    <div class='ph-card'>
                        <div class='ph-card-head'><h2><i class='fas fa-clock'></i> @lang('pharmacy.profile.hours_title')</h2></div>
                        <div class='ph-card-body ph-hours-compact'>
                            @foreach($daysOfWeek as $dayKey => $dayName)
                                @php
                                    $hour = $pharmacyHours[$dayKey] ?? null;
                                    $isClosed = old('hours.'.$dayKey.'.is_closed', $hour?->is_closed ?? false);
                                    $openVal = old('hours.'.$dayKey.'.open_time', $hour?->open_time?->format('H:i'));
                                    $closeVal = old('hours.'.$dayKey.'.close_time', $hour?->close_time?->format('H:i'));
                                    $is24 = $openVal === '00:00' && $closeVal === '23:59';
                                @endphp
                                <div class='hc-row'>
                                    <span class='hc-day'>{{ $dayName }}</span>
                                    <span class='hc-time'>
                                        @if($isClosed) @lang('pharmacy.profile.closed')
                                        @elseif($is24) @lang('pharmacy.profile.hours_quick.open_24')
                                        @else {{ $openVal.' – '.$closeVal }} @endif
                                    </span>
                                    <i class='fas fa-calendar-days'></i>
                                </div>
                            @endforeach
                        </div>
                        <div style='padding:0 22px 18px;'>
                            <button type='button' class='ph-text-action' onclick='openModal("hoursModal")'><i class='fas fa-pen'></i> @lang('pharmacy.profile.hours_change')</button>
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

            <!-- حوار تعديل موقع الصيدلية -->
            <div class='ph-modal-overlay' id='locationModal' role='dialog' aria-modal='true'>
                <div class='ph-modal'>
                    <div class='ph-modal-head'>
                        <h3>@lang('pharmacy.profile.location_title')</h3>
                        <button type='button' class='ph-close' onclick='closeModal("locationModal")'>&times;</button>
                    </div>
                    <div class='ph-modal-body'>
                        <div id='pharmacyMapEdit' class='ph-map ph-map-edit' data-lat='{{ old('latitude', $pharmacy->latitude) }}' data-lng='{{ old('longitude', $pharmacy->longitude) }}'></div>
                        <p class='ph-hint' style='margin-block-start:10px;'>@lang('pharmacy.profile.map_hint')</p>
                        <input type='hidden' name='latitude' id='latitude' value='{{ old('latitude', $pharmacy->latitude) }}'>
                        <input type='hidden' name='longitude' id='longitude' value='{{ old('longitude', $pharmacy->longitude) }}'>
                        @error('latitude')<span style='display:block;color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                        @error('longitude')<span style='display:block;color:var(--ph-red);font-size:.8rem;'>{{ $message }}</span>@enderror
                    </div>
                    <div class='ph-modal-foot'>
                        <button type='button' class='ph-btn ghost' onclick='closeModal("locationModal")'>@lang('pharmacy.profile.cancel_button')</button>
                        <button type='button' class='ph-btn primary' onclick='saveFromModal("locationModal")'>@lang('pharmacy.profile.save_button')</button>
                    </div>
                </div>
            </div>

            <!-- حوار تعديل ساعات الدوام -->
            <div class='ph-modal-overlay' id='hoursModal' role='dialog' aria-modal='true'>
                <div class='ph-modal'>
                    <div class='ph-modal-head'>
                        <h3>@lang('pharmacy.profile.hours_title')</h3>
                        <button type='button' class='ph-close' onclick='closeModal("hoursModal")'>&times;</button>
                    </div>
                    <div class='ph-modal-body ph-hours-list ph-hours-editor' id='hoursList'>
                        <div class='ph-hours-chips'>
                            <div class='ph-chip ph-chip-accent'>
                                <div class='ph-chip-label'>@lang('pharmacy.profile.hours_quick.uniform')</div>
                                <div class='ph-chip-value' id='chipUnified'>—</div>
                            </div>
                            <div class='ph-chip'>
                                <div class='ph-chip-label'>@lang('pharmacy.profile.hours_quick.exception')</div>
                                <div class='ph-chip-value' id='chipException'>—</div>
                            </div>
                        </div>

                        <div style='margin-block-end:10px;'>
                            <button type='button' class='ph-btn sm outline' onclick='applyAllDays()'>@lang('pharmacy.profile.hours_quick.apply_all')</button>
                        </div>

                        @foreach($daysOfWeek as $dayKey => $dayName)
                            @php
                                $hour = $pharmacyHours[$dayKey] ?? null;
                                $isClosed = old('hours.'.$dayKey.'.is_closed', $hour?->is_closed ?? false);
                                $openVal = old('hours.'.$dayKey.'.open_time', $hour?->open_time?->format('H:i'));
                                $closeVal = old('hours.'.$dayKey.'.close_time', $hour?->close_time?->format('H:i'));
                                $is24 = $openVal === '00:00' && $closeVal === '23:59';
                            @endphp
                            <div class='ph-day-card' data-day='{{ $dayKey }}'>
                                <div class='ph-day-top'>
                                    <span class='ph-day-name'>{{ $dayName }}</span>
                                    <span class='ph-day-status' data-status>
                                        @if($isClosed) @lang('pharmacy.profile.closed')
                                        @elseif($is24) @lang('pharmacy.profile.hours_quick.open_24')
                                        @else {{ $openVal.' – '.$closeVal }} @endif
                                    </span>
                                    <span class='ph-switch'>
                                        <input type='hidden' name='hours[{{ $dayKey }}][is_closed]' value='{{ $isClosed ? 1 : 0 }}' data-closed-input>
                                        <input type='checkbox' class='ph-switch-input' {{ $isClosed ? '' : 'checked' }} aria-label='{{ $dayName }}' onchange='toggleOpenDay(this)'>
                                        <span class='ph-switch-knob'></span>
                                    </span>
                                </div>
                                <div class='ph-day-controls' data-controls @if($isClosed) hidden @endif>
                                    <input type='time' name='hours[{{ $dayKey }}][open_time]' id='open_{{ $dayKey }}' class='ph-control hc-time-input' value='{{ $openVal }}' data-from {{ $isClosed || $is24 ? 'disabled' : '' }} onchange='refreshDayRow(this)'>
                                    <span class='ph-day-to'>@lang('pharmacy.profile.to')</span>
                                    <input type='time' name='hours[{{ $dayKey }}][close_time]' id='close_{{ $dayKey }}' class='ph-control hc-time-input' value='{{ $closeVal }}' data-to {{ $isClosed || $is24 ? 'disabled' : '' }} onchange='refreshDayRow(this)'>
                                    <label class='ph-day-24'>
                                        <input type='checkbox' {{ $is24 ? 'checked' : '' }} onchange='toggle24Day(this)'>
                                        @lang('pharmacy.profile.hours_quick.open_24')
                                    </label>
                                </div>
                            </div>
                        @endforeach

                        @php($hoursError = collect($errors->keys())->first(fn ($k) => str_starts_with($k, 'hours.')))
                        @if($hoursError)
                            <span style='display:block;color:var(--ph-red);font-size:.8rem;'>{{ $errors->first($hoursError) }}</span>
                        @endif
                    </div>
                    <div class='ph-modal-foot'>
                        <button type='button' class='ph-btn ghost' onclick='closeModal("hoursModal")'>@lang('pharmacy.profile.cancel_button')</button>
                        <button type='button' class='ph-btn primary' onclick='saveFromModal("hoursModal")'>@lang('pharmacy.profile.save_button')</button>
                    </div>
                </div>
            </div>

            <!-- حوار تأكيد تغيير موقع الصيدلية على الخريطة -->
            <div class='ph-modal-overlay' id='mapConfirmModal' role='dialog' aria-modal='true'>
                <div class='ph-modal' style='max-width:420px;'>
                    <div class='ph-modal-head'>
                        <h3>@lang('pharmacy.map.confirm_title')</h3>
                        <button type='button' class='ph-close' onclick='mapConfirmCancel()'>&times;</button>
                    </div>
                    <div class='ph-modal-body'>
                        <p style='margin:0;font-size:.9rem;color:var(--ph-ink-soft);line-height:1.8;'>@lang('pharmacy.map.confirm_message')</p>
                    </div>
                    <div class='ph-modal-foot'>
                        <button type='button' class='ph-btn ghost' onclick='mapConfirmCancel()'>@lang('pharmacy.profile.cancel_button')</button>
                        <button type='button' class='ph-btn primary' onclick='mapConfirmOk()'>@lang('pharmacy.map.confirm_ok')</button>
                    </div>
                </div>
            </div>

            @if($errors->hasAny(['current_password', 'password', 'password_confirmation']))
                <script>document.addEventListener('DOMContentLoaded', function () { openModal('passwordModal'); });</script>
            @elseif($errors->has('logo'))
                <script>document.addEventListener('DOMContentLoaded', function () { openModal('logoModal'); });</script>
            @elseif(isset($hoursError) && $hoursError)
                <script>document.addEventListener('DOMContentLoaded', function () { openModal('hoursModal'); });</script>
            @endif

            {{-- زر إرسال النموذج الرئيسي (يُستخدم من حوارات التعديل) --}}
            <button type='submit' style='position:absolute;inset-inline-start:-9999px;width:0;height:0;padding:0;border:0;overflow:hidden;' aria-hidden='true' tabindex='-1'>@lang('pharmacy.profile.save_button')</button>
        </form>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function saveFromModal(id) {
            closeModal(id);
            var form = document.querySelector('form.ph-profile-form');
            if (!form) return;
            // نفعّل أي حقول وقت معطّلة داخل حوار الساعات حتى تُرسل قيمها مع النموذج
            form.querySelectorAll('#hoursList input[disabled]').forEach(function (input) { input.disabled = false; });
            // نستخدم زر إرسال مخفي لضمان إرسال النموذج بشكل موثوق
            var submitter = form.querySelector('button[type="submit"]');
            if (submitter) {
                submitter.click();
            } else if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
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

            // معاينة فورية للشعار في بطاقة البروفايل الرئيسية أيضاً
            var banner = document.querySelector('.ph-avatar');
            if (banner) {
                if (banner.tagName === 'IMG') {
                    banner.src = URL.createObjectURL(file);
                } else {
                    // تحويل العنصر من حرف إلى صورة
                    var newImg = document.createElement('img');
                    newImg.src = URL.createObjectURL(file);
                    newImg.alt = '';
                    newImg.className = 'ph-avatar';
                    newImg.style.cssText = 'cursor:pointer;';
                    newImg.title = banner.title;
                    newImg.setAttribute('onclick', 'openModal("logoModal")');
                    banner.parentNode.replaceChild(newImg, banner);
                }
            }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.ph-modal-overlay.active').forEach(function (m) { m.classList.remove('active'); });
                // إلغاء أي تغيير موقع معلّق على الخريطة عند الضغط على ESC
                if (window.mapConfirmCancel) { window.mapConfirmCancel(); }
            }
        });

        // ===== ساعات الدوام: بطاقة لكل يوم — تعديل مباشر بدون وضع تحرير منفصل =====

        // مفتاح فتح/إغلاق اليوم
        function toggleOpenDay(checkbox) {
            var row = checkbox.closest('.ph-day-card');
            row.querySelector('[data-closed-input]').value = checkbox.checked ? 0 : 1;
            var controls = row.querySelector('[data-controls]');
            controls.hidden = !checkbox.checked;
            refreshDayRow(checkbox);
        }

        // خيار "مفتوح 24 ساعة": 00:00 – 23:59 مع تعطيل الحقول
        function toggle24Day(checkbox) {
            var row = checkbox.closest('.ph-day-card');
            var from = row.querySelector('[data-from]');
            var to = row.querySelector('[data-to]');
            if (checkbox.checked) {
                from.value = '00:00';
                to.value = '23:59';
            }
            from.disabled = checkbox.checked;
            to.disabled = checkbox.checked;
            refreshDayRow(checkbox);
        }

        // تحديث نص الحالة داخل البطاقة + شرائح الملخص
        function refreshDayRow(el) {
            var row = el.closest('.ph-day-card');
            var status = row.querySelector('[data-status]');
            var closedInput = row.querySelector('[data-closed-input]');
            var from = row.querySelector('[data-from]');
            var to = row.querySelector('[data-to]');
            var h24 = row.querySelector('.ph-day-24 input');

            if (closedInput.value === '1') {
                status.textContent = @json(__('pharmacy.profile.closed'));
            } else if (h24.checked) {
                status.textContent = @json(__('pharmacy.profile.hours_quick.open_24'));
            } else {
                status.textContent = (from.value || '—') + ' – ' + (to.value || '—');
            }
            updateHoursSummary();
        }

        // تطبيق إعدادات اليوم الأول على كل الأيام
        function applyAllDays() {
            var rows = document.querySelectorAll('#hoursList .ph-day-card');
            if (!rows.length) return;
            var ref = rows[0];
            var refOpen = ref.querySelector('[data-from]').value || '09:00';
            var refClose = ref.querySelector('[data-to]').value || '17:00';
            var ref24 = ref.querySelector('.ph-day-24 input').checked;

            rows.forEach(function (row) {
                var h24 = row.querySelector('.ph-day-24 input');
                var from = row.querySelector('[data-from]');
                var to = row.querySelector('[data-to]');
                var sw = row.querySelector('.ph-switch-input');
                h24.checked = ref24;
                from.value = refOpen;
                to.value = refClose;
                from.disabled = ref24;
                to.disabled = ref24;
                sw.checked = true;
                row.querySelector('[data-closed-input]').value = 0;
                row.querySelector('[data-controls]').hidden = false;
                refreshDayRow(sw);
            });
        }

        // شرائح الملخص: دوام موحد + الاستثناءات
        function updateHoursSummary() {
            var rows = document.querySelectorAll('#hoursList .ph-day-card');
            var open = [], closedNames = [], signature = null, uniform = true;

            rows.forEach(function (row) {
                var name = row.querySelector('.ph-day-name').textContent.trim();
                var isOpen = row.querySelector('.ph-switch-input').checked;
                if (!isOpen) { closedNames.push(name); return; }
                var from = row.querySelector('[data-from]').value;
                var to = row.querySelector('[data-to]').value;
                var is24 = row.querySelector('.ph-day-24 input').checked;
                open.push({ from: from, to: to, is24: is24 });
                var sig = from + '|' + to + '|' + (is24 ? 1 : 0);
                if (signature === null) { signature = sig; }
                else if (sig !== signature) { uniform = false; }
            });

            var unifiedEl = document.getElementById('chipUnified');
            var exceptionEl = document.getElementById('chipException');

            if (open.length === rows.length && uniform && open.length) {
                unifiedEl.textContent = open[0].is24
                    ? @json(__('pharmacy.profile.hours_quick.open_24'))
                    : open[0].from + ' – ' + open[0].to;
            } else {
                unifiedEl.textContent = open.length ? '…' : '—';
            }

            exceptionEl.textContent = closedNames.length ? closedNames.join('، ') : @json(__('pharmacy.profile.hours_quick.no_exception'));
        }

        document.addEventListener('DOMContentLoaded', updateHoursSummary);
    </script>
@endsection