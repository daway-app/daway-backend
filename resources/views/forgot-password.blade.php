<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>دوائي | نسيت كلمة المرور</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=Tajawal:wght@300;400;500;700&display=swap"
        rel="stylesheet">
    <style>
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
            max-width: 480px;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 48px 40px;
            overflow: hidden;
        }

        .logo-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--teal-main);
        }

        .logo-mark {
            width: 48px;
            height: 48px;
            background: var(--accent);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px -6px rgba(244, 118, 46, 0.6);
        }

        .logo-mark svg {
            width: 24px;
            height: 24px;
        }

        .form-head {
            text-align: center;
            margin-bottom: 32px;
        }

        .form-head h1 {
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 26px;
            margin: 0 0 8px;
            color: var(--teal-main);
        }

        .form-head p {
            margin: 0;
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

        .input-wrap input {
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

        .input-wrap input::placeholder {
            color: #9BB3AC;
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

        .back-link {
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .back-link a {
            color: var(--teal-primary);
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .back-link a:hover {
            color: var(--teal-main);
            text-decoration: underline;
        }

        @media (max-width: 420px) {
            body {
                padding: 12px;
            }

            .screen {
                padding: 32px 24px;
            }

            .logo {
                font-size: 24px;
            }

            .logo-mark {
                width: 40px;
                height: 40px;
            }

            .logo-mark svg {
                width: 20px;
                height: 20px;
            }

            .form-head h1 {
                font-size: 22px;
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

        <!-- ===== الشعار في المنتصف ===== -->
        <div class="logo-center">
            <div class="logo">
                <span class="logo-mark">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Z" stroke="#fff" stroke-width="1.8" />
                        <path d="M12 8v8M8 12h8" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                </span>
                دوائي
            </div>
        </div>

        <!-- ===== عنوان الصفحة ===== -->
        <div class="form-head">
            <h1>نسيت كلمة المرور؟</h1>
            <p>أدخل <strong>معرف الصيدلية</strong> (Pharmacy ID) أو <strong>البريد الإلكتروني</strong> الخاص بحسابك.<br>
                سنتواصل معك خلال 24 ساعة لإعادة تعيين كلمة المرور.</p>
        </div>

        <!-- ===== النموذج ===== -->
        <form id="forgotForm" method="POST" action="{{ route('password.request') }}" novalidate>
            @csrf

            <div class="field" id="loginIdField">
                <label for="loginId">معرف الصيدلية أو البريد الإلكتروني</label>
                <div class="input-wrap">
                    <input type="text" id="loginId" name="login_id" placeholder="أدخل Pharmacy ID أو البريد الإلكتروني"
                        autocomplete="off" required>
                </div>
                <span class="error-msg">الرجاء إدخال معرف صحيح.</span>
            </div>

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
                <span class="btn-text">إرسال طلب استعادة</span>
            </button>

            <div class="back-link">
                تذكرت كلمة المرور؟ <a href="{{ route('login') }}">عودة لتسجيل الدخول</a>
            </div>
        </form>

    </div>

    <script>
        (function () {
            'use strict';

            const form = document.getElementById('forgotForm');
            const loginIdField = document.getElementById('loginIdField');
            const loginInput = document.getElementById('loginId');
            const submitBtn = document.getElementById('submitBtn');
            const statusBox = document.getElementById('formStatus');

            function setFieldError(fieldEl, hasError) {
                fieldEl.classList.toggle('invalid', hasError);
                const wrap = fieldEl.querySelector('.input-wrap');
                if (wrap) wrap.classList.toggle('error', hasError);
            }

            function clearStatus() {
                statusBox.className = 'form-status';
                statusBox.textContent = '';
            }

            function isValidInput(value) {
                // يقبل البريد الإلكتروني أو Pharmacy ID (أحرف وأرقام وشرطات)
                return /^[A-Za-z0-9\-_@.]+$/.test(value) && value.trim().length > 0;
            }

            form.addEventListener('submit', function (e) {
                clearStatus();

                const loginVal = loginInput.value.trim();

                if (!isValidInput(loginVal)) {
                    setFieldError(loginIdField, true);
                    e.preventDefault();
                    return;
                }

                setFieldError(loginIdField, false);
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
            });

            loginInput.addEventListener('input', function () {
                if (isValidInput(this.value.trim())) {
                    setFieldError(loginIdField, false);
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