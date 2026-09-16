<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب صيدلية — دواك</title>
    <meta name="theme-color" content="#1C72A6">
    {{-- الخطوط (Cairo + Tajawal) تُستضاف ذاتياً عبر Vite/bunny — لا طلب خارجي حاجب للعرض --}}

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

    <!-- نفس نظام تصميم صفحات المصادقة (بلا ملف CSS جديد) -->
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

        <!-- Progress Loader Overlay -->
        <div class="loader-overlay" id="loaderOverlay" role="status" aria-live="polite" aria-busy="true">
            <div class="loader-spinner-box">
                <div class="spinner"></div>
                <span class="loader-icon" aria-hidden="true">💊</span>
            </div>
            <div class="loader-text">جاري إنشاء الحساب...</div>

            {{-- شبكة أمان: تظهر إن تجاوز الطلب المهلة (شبكة بطيئة / خادم لا يستجيب) --}}
            <div class="loader-timeout">
                <div class="loader-timeout-icon" aria-hidden="true">⚠️</div>
                <div class="loader-timeout-msg">
                    استغرق إنشاء الحساب وقتاً أطول من المتوقّع. تحقّق من اتصال الإنترنت وحاول مرة أخرى.
                </div>
                <button type="button" class="loader-timeout-btn" onclick="resetLoginState()">إعادة المحاولة</button>
            </div>
        </div>

        <!-- الجانب الأيسر: النموذج -->
        <div class="auth-form-side">
            <h1 class="form-title">إنشاء حساب صيدلية</h1>
            <p class="form-subtitle">عبّئ بيانات صيدليتك، وبعد موافقة الإدارة رح يوصلك معرّف الدخول.</p>

            <form id="registerForm" action="{{ route('register') }}" method="POST">
                @csrf

                <!-- اسم الصيدلية -->
                <div class="fg">
                    <label class="fl" for="pharmacy_name">اسم الصيدلية <span style="color:#D64545">*</span></label>
                    <input class="fc @error('pharmacy_name') is-invalid @enderror" type="text" id="pharmacy_name"
                        name="pharmacy_name" value="{{ old('pharmacy_name') }}" placeholder="مثال: صيدلية النور"
                        required>
                    @error('pharmacy_name')
                        <div class="error-message">{{ $message }}</div>
                    @enderror
                </div>

                <!-- رقم الهاتف -->
                <div class="fg">
                    <label class="fl" for="phone">رقم الهاتف <span style="color:#D64545">*</span></label>
                    <input class="fc @error('phone') is-invalid @enderror" type="text" id="phone"
                        name="phone" value="{{ old('phone') }}" placeholder="0599 000 000" required>
                    @error('phone')
                        <div class="error-message">{{ $message }}</div>
                    @enderror
                </div>

                <!-- المنطقة -->
                <div class="fg">
                    <label class="fl" for="region">المنطقة <span style="color:#D64545">*</span></label>
                    <input class="fc @error('region') is-invalid @enderror" type="text" id="region" name="region"
                        value="{{ old('region') }}" placeholder="مثال: الرمال" required>
                    @error('region')
                        <div class="error-message">{{ $message }}</div>
                    @enderror
                </div>

                <!-- كلمة المرور -->
                <div class="fg">
                    <label class="fl" for="password">كلمة المرور <span style="color:#D64545">*</span></label>
                    <div class="fc-wrapper">
                        <input class="fc @error('password') is-invalid @enderror" type="password" id="password"
                            name="password" placeholder="8 أحرف على الأقل" required>
                        <button type="button" class="toggle-btn" onclick="togglePass('password', this)">إظهار</button>
                    </div>
                    @error('password')
                        <div class="error-message">{{ $message }}</div>
                    @enderror
                </div>

                <div class="info-hint">
                    💡 بعد موافقة الإدارة رح يوصلك <strong>معرّف الدخول (Pharmacy ID)</strong> —
                    استخدمه مع <strong>كلمة المرور يلي اخترتها هون</strong> لتسجيل الدخول.
                </div>

                <button type="submit" class="btn-p" id="submitBtn" style="margin-top:18px">إنشاء الحساب</button>
            </form>

            <div class="auth-footer">
                عندك حساب بالفعل؟ <a href="{{ route('login.show') }}">تسجيل الدخول</a>
            </div>
        </div>

        <!-- الجانب الأيمن: الهوية البصرية -->
        <div class="auth-hero">
            <div class="hero-content">
                <div class="logo-wrapper">
                    <img src="{{ asset('images/dawak-logo-384.jpg') }}"
                        srcset="{{ asset('images/dawak-logo-256.jpg') }} 256w, {{ asset('images/dawak-logo-384.jpg') }} 384w"
                        sizes="92px" width="92" height="92" decoding="async"
                        alt="شعار دواك" class="brand-logo-img">
                </div>

                <span class="hero-subtitle-tag">منصة دواك</span>
                <h2 class="hero-title">انضم إلينا</h2>
                <p class="hero-desc">سجّل صيدليتك وساعد المرضى يلاقوا الدواء المتوفر عندك قبل ما يتحركوا من مكانهم.
                </p>
            </div>

            <!-- جرافيك الرادار والدبوس التفاعلي -->
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
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerText = 'إخفاء';
            } else {
                input.type = 'password';
                btn.innerText = 'إظهار';
            }
        }

        // مهلة الإنشاء: بعدها نُظهر خيار "إعادة المحاولة" بدل حبس المستخدم.
        var LOGIN_TIMEOUT_MS = 15000;
        var registerForm = document.getElementById('registerForm');
        var loaderOverlay = document.getElementById('loaderOverlay');
        var submitBtn = document.getElementById('submitBtn');
        var loginTimer = null;

        function resetLoginState() {
            if (loginTimer !== null) { clearTimeout(loginTimer); loginTimer = null; }
            loaderOverlay.classList.remove('active', 'show-timeout');
            loaderOverlay.removeAttribute('aria-busy');
            submitBtn.disabled = false;
            submitBtn.focus();
        }

        registerForm.addEventListener('submit', function () {
            loaderOverlay.classList.add('active');
            loaderOverlay.setAttribute('aria-busy', 'true');
            submitBtn.disabled = true;

            if (loginTimer !== null) { clearTimeout(loginTimer); }
            loginTimer = setTimeout(function () {
                loaderOverlay.classList.add('show-timeout');
                loaderOverlay.removeAttribute('aria-busy');
                submitBtn.disabled = false;
            }, LOGIN_TIMEOUT_MS);
        });

        // عند العودة بالـ back أو استعادة الصفحة من كاش الـ Service Worker.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted || loaderOverlay.classList.contains('active')) {
                resetLoginState();
            }
        });
    </script>

</body>

</html>
