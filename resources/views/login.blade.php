<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>دوائي | تسجيل الدخول</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=Tajawal:wght@300;400;500;700&display=swap"
        rel="stylesheet">
    <style>
        /* ===== نظام الألوان والمتغيرات (Design Tokens) ===== */
        :root {
            --teal-main: #00657A;
            --teal-primary: #0B8FAC;
            --teal-light: #7BC1B7;
            --teal-dark: #004B5E;
            --mint-50: #F4FAF7;
            --mint-100: #E7F4EF;
            --white: #FFFFFF;
            --accent: #F4762E;
            --accent-dark: #DA5F1B;
            --text-muted: #5B7A73;
            --border: #D9EAE4;
            --danger: #E0483F;
            --success: #177C4C;
            --radius-lg: 24px;
            --radius-md: 14px;
            --shadow-card: 0 30px 60px -25px rgba(0, 101, 122, 0.35);
            --shadow-focus: 0 0 0 4px rgba(11, 143, 172, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--mint-50);
            font-family: 'Tajawal', sans-serif;
            color: var(--teal-main);
            padding: 20px;
        }

        .screen {
            width: 100%;
            max-width: 1000px;
            min-height: 620px;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            display: grid;
            grid-template-columns: 1fr 1.1fr;
            overflow: hidden;
        }

        /* ===== اللوحة الجانبية - الهوية البصرية ===== */
        .brand-panel {
            position: relative;
            background:
                radial-gradient(circle at 30% 20%, rgba(255, 255, 255, 0.08), transparent 45%),
                linear-gradient(160deg, var(--teal-primary) 0%, var(--teal-main) 65%, var(--teal-dark) 100%);
            color: var(--white);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -30%;
            right: -30%;
            width: 80%;
            height: 80%;
            border-radius: 50%;
            background: rgba(244, 118, 46, 0.08);
            pointer-events: none;
        }

        .brand-top {
            position: relative;
            z-index: 2;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 24px;
            letter-spacing: 0.5px;
        }

        .logo-mark {
            width: 40px;
            height: 40px;
            background: var(--accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px -6px rgba(244, 118, 46, 0.6);
        }

        .logo-mark svg {
            width: 22px;
            height: 22px;
        }

        .tagline {
            margin-top: 40px;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 32px;
            line-height: 1.4;
            max-width: 340px;
            color: var(--white);
        }

        .tagline span {
            color: var(--accent);
        }

        .sub-tagline {
            margin-top: 14px;
            font-size: 15px;
            color: rgba(255, 255, 255, 0.75);
            max-width: 300px;
            line-height: 1.9;
        }

        .radar-wrap {
            position: relative;
            z-index: 2;
            width: 180px;
            height: 180px;
            margin: 10px auto 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .radar-ring {
            position: absolute;
            border: 1.5px solid rgba(255, 255, 255, 0.25);
            border-radius: 50%;
        }

        .r1 {
            width: 60px;
            height: 60px;
        }

        .r2 {
            width: 110px;
            height: 110px;
        }

        .r3 {
            width: 160px;
            height: 160px;
        }

        .radar-pulse {
            position: absolute;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244, 118, 46, 0.5), transparent 70%);
            animation: pulse 2.8s ease-out infinite;
        }

        .pin {
            position: relative;
            z-index: 3;
            width: 48px;
            height: 48px;
            background: var(--accent);
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.5);
        }

        .pin svg {
            transform: rotate(45deg);
            width: 22px;
            height: 22px;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.4);
                opacity: 0.9;
            }

            100% {
                transform: scale(2.8);
                opacity: 0;
            }
        }

        /* ===== إخفاء النص السفلي مع الحفاظ على التوزيع ===== */
        .brand-bottom {
            position: relative;
            z-index: 2;
            visibility: hidden;
            height: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        @media (prefers-reduced-motion: reduce) {
            .radar-pulse {
                animation: none;
                opacity: 0.3;
            }
        }

        /* ===== لوحة النموذج ===== */
        .form-panel {
            padding: 52px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-head h1 {
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 28px;
            margin: 0 0 6px;
            color: var(--teal-main);
        }

        .form-head p {
            margin: 0 0 32px;
            color: var(--text-muted);
            font-size: 14.5px;
            line-height: 1.8;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--teal-main);
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            background: var(--mint-100);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .input-wrap:focus-within {
            border-color: var(--teal-primary);
            background: var(--white);
            box-shadow: var(--shadow-focus);
        }

        .input-wrap.error {
            border-color: var(--danger);
        }

        .input-wrap input,
        .input-wrap select {
            flex: 1;
            border: none;
            background: transparent;
            outline: none;
            padding: 14px 16px;
            font-family: 'Tajawal', sans-serif;
            font-size: 15px;
            color: var(--teal-main);
            direction: rtl;
        }

        .input-wrap select {
            appearance: none;
            cursor: pointer;
        }

        .input-wrap input::placeholder {
            color: #9BB3AC;
        }

        .icon-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0 16px;
            color: #7FA69D;
            display: flex;
            align-items: center;
            font-size: 13px;
            font-family: 'Tajawal', sans-serif;
            user-select: none;
            transition: color 0.2s ease;
        }

        .icon-btn:hover {
            color: var(--teal-primary);
        }

        .error-msg {
            font-size: 12.5px;
            color: var(--danger);
            min-height: 16px;
            display: none;
        }

        .field.invalid .error-msg {
            display: block;
        }

        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13.5px;
            margin-top: -2px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            cursor: pointer;
        }

        .remember input {
            accent-color: var(--teal-primary);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .forgot-link {
            color: var(--teal-primary);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .forgot-link:hover {
            color: var(--teal-main);
            text-decoration: underline;
        }

        .submit-btn {
            margin-top: 8px;
            background: var(--accent);
            color: var(--white);
            border: none;
            border-radius: var(--radius-md);
            padding: 16px;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.2s ease, transform 0.12s ease;
        }

        .submit-btn:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: scale(0.98);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2.5px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }

        .submit-btn.loading .spinner {
            display: inline-block;
        }

        .submit-btn.loading .btn-text {
            opacity: 0.85;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .form-status {
            font-size: 13.5px;
            text-align: center;
            padding: 12px;
            border-radius: var(--radius-md);
            display: none;
        }

        .form-status.show {
            display: block;
        }

        .form-status.success {
            background: #E6F6EF;
            color: var(--success);
        }

        .form-status.fail {
            background: #FCEBEA;
            color: var(--danger);
        }

        .login-hint {
            font-size: 12px;
            color: var(--text-muted);
            padding: 4px 2px 0;
            min-height: 20px;
            transition: opacity 0.2s ease;
        }

        .login-hint strong {
            color: var(--teal-primary);
        }

        .signup-note {
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .signup-note a {
            color: var(--teal-primary);
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .signup-note a:hover {
            color: var(--teal-main);
            text-decoration: underline;
        }

        /* ===== الاستجابة للشاشات الصغيرة ===== */
        @media (max-width: 820px) {
            .screen {
                grid-template-columns: 1fr;
                min-height: auto;
                max-width: 480px;
            }

            .brand-panel {
                padding: 32px 28px;
                order: 1;
                min-height: 280px;
            }

            .radar-wrap {
                width: 120px;
                height: 120px;
                margin-top: 20px;
            }

            .r1 {
                width: 40px;
                height: 40px;
            }

            .r2 {
                width: 74px;
                height: 74px;
            }

            .r3 {
                width: 108px;
                height: 108px;
            }

            .pin {
                width: 40px;
                height: 40px;
            }

            .pin svg {
                width: 18px;
                height: 18px;
            }

            .tagline {
                font-size: 24px;
                margin-top: 28px;
            }

            .brand-bottom {
                display: none;
            }

            .form-panel {
                padding: 32px 24px;
                order: 2;
            }

            .form-head h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 420px) {
            body {
                padding: 12px;
            }

            .form-panel {
                padding: 24px 18px;
            }

            .radar-wrap {
                width: 100px;
                height: 100px;
            }

            .r1 {
                width: 34px;
                height: 34px;
            }

            .r2 {
                width: 62px;
                height: 62px;
            }

            .r3 {
                width: 90px;
                height: 90px;
            }

            .pin {
                width: 34px;
                height: 34px;
            }
        }

        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible {
            outline: 3px solid var(--teal-primary);
            outline-offset: 2px;
        }
    </style>
</head>

<body>

    <div class="screen">

        <!-- ===== اللوحة الجانبية: هوية دوائي ===== -->
        <div class="brand-panel">
            <div class="brand-top">
                <div class="logo">
                    <span class="logo-mark">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Z" stroke="#fff" stroke-width="1.8" />
                            <path d="M12 8v8M8 12h8" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </span>
                    دوائي
                </div>
                <div class="tagline">لوحة <span>الإدارة</span></div>
                <p class="sub-tagline">إدارة الصيدليات، الأدوية، والمستخدمين من مكان واحد.</p>
            </div>

            <div class="radar-wrap" aria-hidden="true">
                <div class="radar-ring r3"></div>
                <div class="radar-ring r2"></div>
                <div class="radar-ring r1"></div>
                <div class="radar-pulse"></div>
                <div class="pin">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 2C7 2 4 5.5 4 9.5c0 5 8 12.5 8 12.5s8-7.5 8-12.5C20 5.5 17 2 12 2Z" fill="#fff" />
                        <path d="M9 9h2V7h2v2h2v2h-2v2h-2v-2H9V9Z" fill="#F4762E" />
                    </svg>
                </div>
            </div>

            <!-- النص السفلي مخفي تماماً -->
            <div class="brand-bottom">
                <span>مشروع تخرج · هندسة برمجيات</span>
                <span>&#8226;</span>
                <span>Laravel + Blade</span>
            </div>
        </div>

        <!-- ===== لوحة نموذج تسجيل الدخول ===== -->
        <div class="form-panel">
            <div class="form-head">
                <h1>تسجيل الدخول</h1>
                <p>اختر نوع الحساب وأدخل بياناتك للوصول إلى لوحة التحكم.</p>
            </div>

            <form id="loginForm" method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                <!-- ===== نوع الحساب ===== -->
                <div class="field" id="typeField">
                    <label for="userType">نوع الحساب</label>
                    <div class="input-wrap">
                        <select id="userType" name="user_type" required>
                            <option value="">-- اختر نوع الحساب --</option>
                            <option value="pharmacy">صيدلية</option>
                            <option value="admin">أدمن (مدير النظام)</option>
                        </select>
                    </div>
                    <span class="error-msg">الرجاء اختيار نوع الحساب.</span>
                </div>

                <!-- ===== معرف الدخول (يختلف حسب النوع) ===== -->
                <div class="field" id="loginIdField">
                    <label for="loginId" id="loginIdLabel">معرف الصيدلية (Pharmacy ID)</label>
                    <div class="input-wrap">
                        <input type="text" id="loginId" name="login_id" placeholder="أدخل Pharmacy ID الخاص بالصيدلية"
                            autocomplete="off" required>
                    </div>
                    <span class="error-msg" id="loginIdError">الرجاء إدخال معرف صحيح.</span>
                    <div class="login-hint" id="loginHint">
                        💡 <strong>صيدلية:</strong> استخدم Pharmacy ID الذي منحه الأدمن.
                    </div>
                </div>

                <!-- ===== كلمة المرور ===== -->
                <div class="field" id="passwordField">
                    <label for="password">كلمة المرور</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" placeholder="••••••••"
                            autocomplete="current-password" required minlength="6">
                        <button type="button" class="icon-btn" id="togglePassword">إظهار</button>
                    </div>
                    <span class="error-msg">كلمة المرور يجب ألا تقل عن 6 أحرف.</span>
                </div>

                <div class="row-between">
                    <label class="remember">
                        <input type="checkbox" id="remember" name="remember">
                        تذكرني
                    </label>
                    <a href="#" class="forgot-link">نسيت كلمة المرور؟</a>
                </div>

                <!-- ===== عرض الأخطاء من Laravel ===== -->
                @if ($errors->any())
                    <div class="form-status show fail">
                        @foreach ($errors->all() as $error)
                            {{ $error }}<br>
                        @endforeach
                    </div>
                @endif

                <div class="form-status" id="formStatus"></div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">تسجيل الدخول</span>
                </button>

                <div class="signup-note">
                    ليس لديك حساب؟ <a href="#">تواصل مع الأدمن لإنشاء حساب</a>
                </div>
            </form>
        </div>

    </div>

    <script>
        /* ============================================================
           منطق التحقق من صحة النموذج وإرسال بيانات تسجيل الدخول
           متوافق مع Laravel Authentication
           ============================================================ */

        (function () {
            'use strict';

            const form = document.getElementById('loginForm');
            const typeField = document.getElementById('typeField');
            const loginIdField = document.getElementById('loginIdField');
            const passField = document.getElementById('passwordField');

            const typeSelect = document.getElementById('userType');
            const loginInput = document.getElementById('loginId');
            const loginLabel = document.getElementById('loginIdLabel');
            const loginHint = document.getElementById('loginHint');
            const loginError = document.getElementById('loginIdError');
            const passInput = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePassword');
            const submitBtn = document.getElementById('submitBtn');
            const statusBox = document.getElementById('formStatus');

            const HINTS = {
                pharmacy: {
                    label: 'معرف الصيدلية (Pharmacy ID)',
                    placeholder: 'أدخل Pharmacy ID الخاص بالصيدلية',
                    hint: '💡 <strong>صيدلية:</strong> استخدم Pharmacy ID الذي منحه الأدمن.'
                },
                admin: {
                    label: 'البريد الإلكتروني',
                    placeholder: 'admin@daway.com',
                    hint: '💡 <strong>أدمن:</strong> استخدم البريد الإلكتروني وكلمة المرور الخاصة بك.'
                }
            };

            // ===== تغيير الحقول حسب نوع الحساب =====
            typeSelect.addEventListener('change', function () {
                const val = this.value;
                let config;

                if (val === 'pharmacy') {
                    config = HINTS.pharmacy;
                    loginInput.type = 'text';
                    loginInput.autocomplete = 'off';
                    loginError.textContent = 'الرجاء إدخال Pharmacy ID صحيح.';
                } else if (val === 'admin') {
                    config = HINTS.admin;
                    loginInput.type = 'email';
                    loginInput.autocomplete = 'email';
                    loginError.textContent = 'الرجاء إدخال بريد إلكتروني صحيح.';
                } else {
                    loginLabel.textContent = 'معرف الدخول';
                    loginInput.placeholder = 'أدخل معرف الدخول';
                    loginHint.innerHTML = '⚠️ يرجى اختيار نوع الحساب أولاً.';
                    return;
                }

                loginLabel.textContent = config.label;
                loginInput.placeholder = config.placeholder;
                loginHint.innerHTML = config.hint;
                loginInput.value = '';
                setFieldError(loginIdField, false);
            });

            // ===== إظهار / إخفاء كلمة المرور =====
            toggleBtn.addEventListener('click', function () {
                const isPassword = passInput.type === 'password';
                passInput.type = isPassword ? 'text' : 'password';
                this.textContent = isPassword ? 'إخفاء' : 'إظهار';
            });

            // ===== دوال مساعدة =====
            function setFieldError(fieldEl, hasError) {
                fieldEl.classList.toggle('invalid', hasError);
                const wrap = fieldEl.querySelector('.input-wrap');
                if (wrap) wrap.classList.toggle('error', hasError);
            }

            function showStatus(message, type) {
                statusBox.textContent = message;
                statusBox.className = 'form-status show ' + type;
            }

            function clearStatus() {
                statusBox.className = 'form-status';
                statusBox.textContent = '';
            }

            function isValidEmail(value) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            }

            function isValidPharmacyId(value) {
                return /^[A-Za-z0-9\-_]{6,20}$/.test(value);
            }

            // ===== التحقق من صحة النموذج قبل الإرسال =====
            function validateForm() {
                let valid = true;

                const type = typeSelect.value;
                const loginVal = loginInput.value.trim();
                const passVal = passInput.value;

                if (!type) {
                    setFieldError(typeField, true);
                    valid = false;
                } else {
                    setFieldError(typeField, false);
                }

                if (type === 'pharmacy') {
                    if (!isValidPharmacyId(loginVal)) {
                        setFieldError(loginIdField, true);
                        valid = false;
                    } else {
                        setFieldError(loginIdField, false);
                    }
                } else if (type === 'admin') {
                    if (!isValidEmail(loginVal)) {
                        setFieldError(loginIdField, true);
                        valid = false;
                    } else {
                        setFieldError(loginIdField, false);
                    }
                } else {
                    setFieldError(loginIdField, true);
                    valid = false;
                }

                if (passVal.length < 6) {
                    setFieldError(passField, true);
                    valid = false;
                } else {
                    setFieldError(passField, false);
                }

                return valid;
            }

            // ===== معالجة إرسال النموذج =====
            form.addEventListener('submit', function (e) {
                clearStatus();

                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }

                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
            });

            // ===== إزالة حالة الخطأ عند التعديل =====
            typeSelect.addEventListener('change', function () {
                setFieldError(typeField, false);
                if (this.value) {
                    const loginVal = loginInput.value.trim();
                    if (this.value === 'pharmacy' && isValidPharmacyId(loginVal)) {
                        setFieldError(loginIdField, false);
                    } else if (this.value === 'admin' && isValidEmail(loginVal)) {
                        setFieldError(loginIdField, false);
                    }
                }
            });

            loginInput.addEventListener('input', function () {
                const type = typeSelect.value;
                if (!type) return;

                if (type === 'pharmacy' && isValidPharmacyId(this.value.trim())) {
                    setFieldError(loginIdField, false);
                } else if (type === 'admin' && isValidEmail(this.value.trim())) {
                    setFieldError(loginIdField, false);
                } else {
                    if (this.value.trim().length > 0) {
                        setFieldError(loginIdField, true);
                    }
                }
            });

            passInput.addEventListener('input', function () {
                if (this.value.length >= 6) {
                    setFieldError(passField, false);
                }
            });

            window.addEventListener('load', function () {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            });

        })();
    </script>

</body>

</html>
