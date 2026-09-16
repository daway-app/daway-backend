<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@lang('pharmacy.password.change.title') — دواك</title>
    <meta name="theme-color" content="#1C72A6">
    <meta name="robots" content="noindex, nofollow">

    {{-- الوضع الداكن: يُقرأ قبل الرسم الأول لمنع وميض الصفحة البيضاء. --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('theme') === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/tokens.css', 'resources/css/app.css', 'resources/css/auth/forms.css'])
</head>

<body>
    <script>
        (function () {
            try {
                if (localStorage.getItem('theme') === 'dark') {
                    document.body.classList.add('dark-mode');
                }
            } catch (e) {}
        })();
    </script>

    <div class="auth-container">

        <!-- الجانب: النموذج -->
        <div class="auth-form-side">
            <h1 class="form-title">@lang('pharmacy.password.change.heading')</h1>
            <p class="form-subtitle">@lang('pharmacy.password.change.subtitle')</p>

            @if(session('warning'))
                <div class="info-hint" role="alert" style="margin-bottom:18px;">
                    ⚠️ {{ session('warning') }}
                </div>
            @endif

            <form id="passwordChangeForm" action="{{ route('pharmacy.password.change') }}" method="POST" novalidate>
                @csrf

                <!-- كلمة المرور الحالية (المؤقتة) -->
                <div class="fg">
                    <label class="fl" for="currentPasswordInput">@lang('pharmacy.password.change.current_label')</label>
                    <div class="fc-wrapper">
                        <input class="fc" type="password" id="currentPasswordInput" name="current_password"
                            placeholder="••••••••" autocomplete="current-password" required autofocus
                            @error('current_password') aria-invalid="true" aria-describedby="currentPasswordError" @enderror>
                    </div>
                    @error('current_password')
                        <div class="error-message" id="currentPasswordError">{{ $message }}</div>
                    @enderror
                </div>

                <!-- كلمة المرور الجديدة -->
                <div class="fg">
                    <label class="fl" for="passwordInput">@lang('pharmacy.password.change.new_label')</label>
                    <div class="fc-wrapper">
                        <input class="fc" type="password" id="passwordInput" name="password" placeholder="••••••••"
                            autocomplete="new-password" required minlength="8"
                            @error('password') aria-invalid="true" aria-describedby="passwordError" @enderror>
                        <button type="button" class="toggle-btn" onclick="togglePass('passwordInput', this)"
                            aria-label="@lang('pharmacy.password.change.show_password')">
                            @lang('pharmacy.password.change.show')
                        </button>
                    </div>
                    <div class="info-hint" role="note">
                        💡 @lang('pharmacy.password.change.rules')
                    </div>
                    @error('password')
                        <div class="error-message" id="passwordError">{{ $message }}</div>
                    @enderror
                </div>

                <!-- تأكيد كلمة المرور -->
                <div class="fg">
                    <label class="fl" for="passwordConfirmInput">@lang('pharmacy.password.change.confirm_label')</label>
                    <div class="fc-wrapper">
                        <input class="fc" type="password" id="passwordConfirmInput" name="password_confirmation"
                            placeholder="••••••••" autocomplete="new-password" required minlength="8"
                            @error('password_confirmation') aria-invalid="true" @enderror>
                        <button type="button" class="toggle-btn" onclick="togglePass('passwordConfirmInput', this)"
                            aria-label="@lang('pharmacy.password.change.show_password')">
                            @lang('pharmacy.password.change.show')
                        </button>
                    </div>
                </div>

                <div class="fg" style="margin-top:24px;">
                    <button type="submit" class="btn-p" id="submitBtn">
                        @lang('pharmacy.password.change.submit')
                    </button>
                </div>
            </form>

            <div class="auth-footer">
                <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit"
                        style="background:none;border:none;color:var(--teal-text);font-weight:700;cursor:pointer;font-family:inherit;font-size:13px;">
                        @lang('pharmacy.password.change.logout_instead')
                    </button>
                </form>
            </div>
        </div>

        <!-- الجانب: الهوية البصرية -->
        <div class="auth-hero">
            <div class="hero-content">
                <div class="logo-wrapper">
                    <img src="{{ asset('images/dawak-logo-384.jpg') }}"
                        srcset="{{ asset('images/dawak-logo-256.jpg') }} 256w, {{ asset('images/dawak-logo-384.jpg') }} 384w"
                        sizes="92px" width="92" height="92" fetchpriority="high" decoding="async"
                        alt="شعار دواك" class="brand-logo-img">
                </div>

                <span class="hero-subtitle-tag">@lang('pharmacy.password.change.hero_tag')</span>
                <h2 class="hero-title">@lang('pharmacy.password.change.hero_title')</h2>
                <p class="hero-desc">@lang('pharmacy.password.change.hero_desc')</p>
            </div>

            <div class="graphic-wrapper">
                <div class="radar-circle">
                    <div class="radar-ripple-1"></div>
                    <div class="radar-ripple-2"></div>

                    <div class="pin-container">
                        <div class="map-pin"></div>
                        <div class="pin-base-platform"></div>
                        <div class="pin-shadow"></div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function togglePass(inputId, btn) {
            var input = document.getElementById(inputId);
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? '@lang('pharmacy.password.change.hide')' : '@lang('pharmacy.password.change.show')';
        }

        // منع الإرسال المزدوج دون حبس المستخدم (لا overlay هنا — الصفحة سريعة).
        document.getElementById('passwordChangeForm').addEventListener('submit', function () {
            var btn = document.getElementById('submitBtn');
            btn.disabled = true;
            // شبكة أمان: إن لم تكتمل العملية لأي سبب، أعد تمكين الزر.
            setTimeout(function () { btn.disabled = false; }, 12000);
        });
    </script>

</body>

</html>
