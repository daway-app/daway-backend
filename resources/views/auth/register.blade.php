<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب صيدلية — دواك</title>
    <meta name="theme-color" content="#1C72A6">
    {{-- الخطوط (Cairo + Tajawal) تُستضاف ذاتياً عبر Vite/bunny — لا طلب خارجي حاجب للعرض --}}

    <!-- نفس نظام تصميم صفحات المصادقة (بلا ملف CSS جديد) -->
    @vite(['resources/css/app.css', 'resources/css/auth/forms.css'])
</head>

<body>

    <div class="auth-container">

        <!-- Progress Loader Overlay -->
        <div class="loader-overlay" id="loaderOverlay">
            <div class="loader-spinner-box">
                <div class="spinner"></div>
                <span class="loader-icon">💊</span>
            </div>
            <div class="loader-text">جاري إنشاء الحساب...</div>
        </div>

        <!-- الجانب الأيسر: النموذج -->
        <div class="auth-form-side">
            <h1 class="form-title">إنشاء حساب صيدلية</h1>
            <p class="form-subtitle">عبّئ بيانات صيدليتك، وبعد موافقة الإدارة رح يظهرلك معرّف الدخول.</p>

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

                <div class="info-hint">
                    💡 بعد إنشاء الحساب <strong>لا يظهر أي معرّف</strong> — بيانات الدخول (Pharmacy ID + كلمة المرور)
                    رح تُرسل لك عبر رسالة نصية فور <strong>موافقة الإدارة</strong>.
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

        document.getElementById('registerForm').addEventListener('submit', function () {
            document.getElementById('loaderOverlay').classList.add('active');
            document.getElementById('submitBtn').disabled = true;
        });
    </script>

</body>

</html>
