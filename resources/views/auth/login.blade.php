<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول — دوائي</title>

    <!-- ربط ملف الـ CSS الخارجي عبر Vite -->
    @vite(['resources/css/app.css', 'resources/css/forms.css'])
</head>
<body>

<div class="auth-container">

    <!-- Progress Loader Overlay -->
    <div class="loader-overlay" id="loaderOverlay">
        <div class="loader-spinner-box">
            <div class="spinner"></div>
            <span class="loader-icon">💊</span>
        </div>
        <div class="loader-text">جاري التحقق والدخول...</div>
    </div>

    <!-- الجانب الأيسر: النموذج -->
    <div class="auth-form-side">
        <h1 class="form-title">تسجيل الدخول</h1>
        <p class="form-subtitle">اختر نوع الحساب وأدخل بياناتك للوصول إلى اللوحة.</p>

        <form id="loginForm" action="{{ route('login') }}" method="POST">
            @csrf

            <!-- نوع الحساب -->
            <div class="fg">
                <label class="fl">نوع الحساب</label>
                <select id="account_type" name="account_type" class="select-fc" onchange="switchRole(this.value)">
                    <option value="admin" {{ old('account_type') == 'admin' ? 'selected' : '' }}>أدمن (مدير النظام)</option>
                    <option value="pharmacy" {{ old('account_type') == 'pharmacy' ? 'selected' : '' }}>صيدلية</option>
                </select>
                @error('account_type')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>

            <!-- معرف الحساب / البريد -->
            <div class="fg">
                <label class="fl" id="identityLabel">البريد الإلكتروني</label>
                <div class="fc-wrapper">
                    <input class="fc" type="text" id="identityInput" name="identity" value="{{ old('identity') }}" placeholder="admin@daway.com" required>
                </div>
                <div class="info-hint" id="infoHint">
                    💡 <strong>أدمن:</strong> استخدم البريد الإلكتروني وكلمة المرور الخاصة بك.
                </div>
                @error('identity')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>

            <!-- كلمة المرور -->
            <div class="fg">
                <label class="fl">كلمة المرور</label>
                <div class="fc-wrapper">
                    <input class="fc" type="password" id="passwordInput" name="password" placeholder="••••••••" required>
                    <button type="button" class="toggle-btn" onclick="togglePass()">إظهار</button>
                </div>
                @error('password')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-footer-options">
                <a href="{{ route('login.show') }}" style="color:#0B8FAC; text-decoration:none; font-weight:700">نسيت كلمة المرور؟</a>
                <label class="remember-label">
                    <span>تذكرني</span>
                    <input type="checkbox" name="remember">
                </label>
            </div>

            <button type="submit" class="btn-p" id="submitBtn">تسجيل الدخول</button>
        </form>

        <div class="auth-footer">
            ليس لديك حساب؟ <a href="#">تواصل مع الأدمن لإنشاء حساب</a>
        </div>
    </div>

    <!-- الجانب الأيمن: الهوية البصرية مع الدوائر المتحركة ورادار الموقع -->
    <div class="auth-hero">
        <div class="bubble-shape bubble-1"></div>
        <div class="bubble-shape bubble-2"></div>
        <div class="bubble-shape bubble-3"></div>

        <div class="hero-content">
            <div class="logo-wrapper">
                <img src="{{ asset('images/dawaei-logo.jpg') }}" alt="شعار دوائي" class="brand-logo-img">
            </div>

            <span class="hero-subtitle-tag">منصة دوائي</span>
            <h2 class="hero-title">مرحباً بك مجدداً</h2>
            <p class="hero-desc">إدارة الصيدليات، الأدوية، والطلبات ومتابعة كافة العمليات من مكان واحد بسهولة وأمان.</p>
        </div>

        <!-- جرافيك الرادار والدبوس التفاعلي الاحترافي -->
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
    function switchRole(type) {
        const label = document.getElementById('identityLabel');
        const input = document.getElementById('identityInput');
        const hint = document.getElementById('infoHint');

        if (type === 'pharmacy') {
            label.innerText = 'معرف الصيدلية (Pharmacy ID)';
            input.placeholder = 'أدخل Pharmacy ID الخاص بالصيدلية';
            input.value = '{{ old('identity', '') }}'; // Keep old value or clear
            hint.innerHTML = '💡 <strong>صيدلية:</strong> استخدم Pharmacy ID الذي منحه الأدمن.';
        } else {
            label.innerText = 'البريد الإلكتروني';
            input.placeholder = 'admin@daway.com';
            input.value = '{{ old('identity', 'admin@daway.com') }}'; // Keep old value or default for admin
            hint.innerHTML = '💡 <strong>أدمن:</strong> استخدم البريد الإلكتروني وكلمة المرور الخاصة بك.';
        }
    }

    function togglePass() {
        const input = document.getElementById('passwordInput');
        const btn = document.querySelector('.toggle-btn');
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerText = 'إخفاء';
        } else {
            input.type = 'password';
            btn.innerText = 'إظهار';
        }
    }

    document.getElementById('loginForm').addEventListener('submit', function(e) {
        document.getElementById('loaderOverlay').classList.add('active');
        document.getElementById('submitBtn').disabled = true;
    });

    // Initialize role display on page load based on old input
    document.addEventListener('DOMContentLoaded', function() {
        const accountTypeSelect = document.getElementById('account_type');
        switchRole(accountTypeSelect.value);
    });
</script>

</body>
</html>
