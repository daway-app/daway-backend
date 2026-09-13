<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم إنشاء الحساب — دواك</title>
    <meta name="theme-color" content="#1C72A6">
    <meta name="robots" content="noindex">

    @vite(['resources/css/app.css', 'resources/css/auth/forms.css'])
</head>

<body>

    <div class="auth-container">

        <!-- الجانب الأيسر: نتيجة التسجيل -->
        <div class="auth-form-side">
            <div style="text-align:center; margin-bottom:22px">
                <div style="font-size:46px; line-height:1">✅</div>
            </div>

            <h1 class="form-title" style="text-align:center">تم إنشاء الحساب بنجاح</h1>
            <p class="form-subtitle" style="text-align:center">
                @if ($pharmacyName)
                    حساب <strong>{{ $pharmacyName }}</strong> بانتظار موافقة الإدارة.
                @else
                    حسابك بانتظار موافقة الإدارة.
                @endif
            </p>

            <!-- معرّف الصيدلية — هو اللي بتدخل فيه -->
            <div
                style="background:var(--teal-mist); border:1.5px dashed var(--teal-primary); border-radius:var(--r-md); padding:18px; text-align:center; margin-bottom:18px">
                <div style="font-size:12.5px; font-weight:700; color:var(--ink-soft); margin-bottom:8px">
                    معرّف الصيدلية (Pharmacy ID)
                </div>

                <div id="pharmacyId"
                    style="font-size:30px; font-weight:800; letter-spacing:3px; color:var(--teal-deep); direction:ltr">
                    {{ $pharmacyId }}
                </div>

                <button type="button" class="toggle-btn" id="copyBtn" onclick="copyId()"
                    style="position:static; transform:none; margin-top:10px; background:var(--paper); border:1.5px solid var(--line)">
                    نسخ المعرّف
                </button>
            </div>

            <div class="info-hint">
                💡 <strong>احفظ هذا المعرّف</strong> — رح تحتاجه لتسجيل الدخول مع كلمة المرور التي اخترتها. تقدر
                تدخل بعد ما توافق الإدارة على حسابك.
            </div>

            <a href="{{ route('login.show') }}" class="btn-p" style="margin-top:18px; text-decoration:none">
                الذهاب لصفحة الدخول
            </a>

            <div class="auth-footer">
                نسخت المعرّف؟ <a href="{{ route('login.show') }}">سجّل الدخول من هنا</a>
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
                <h2 class="hero-title">خطوة أخيرة</h2>
                <p class="hero-desc">فريق الإدارة بيراجع طلبات الصيدليات الجديدة، وبعد الموافقة رح تقدر تدير مخزونك
                    وتستقبل استفسارات المرضى.</p>
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
        function copyId() {
            const id = document.getElementById('pharmacyId').innerText.trim();
            const btn = document.getElementById('copyBtn');

            const done = () => {
                btn.innerText = 'تم النسخ ✓';
                setTimeout(() => { btn.innerText = 'نسخ المعرّف'; }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(id).then(done).catch(() => fallbackCopy(id, done));
            } else {
                fallbackCopy(id, done);
            }
        }

        // احتياطي للمتصفحات/السياقات غير الآمنة (بلا Clipboard API)
        function fallbackCopy(text, done) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* لا شيء */ }
            document.body.removeChild(ta);
        }
    </script>

</body>

</html>
