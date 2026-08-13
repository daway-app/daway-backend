<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'تسجيل الدخول — دوائي')</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login-pg">
    <div class="login-vis">
        <div class="brand">
            <div class="lv-icon">💊</div>
            <h2>دوائي<br>Daway</h2>
            <p>منصة ذكية تجمع رحلة الدواء في مكان واحد — للمرضى والصيدليات والإدارة.</p>
            <div class="lv-feats">
                <div class="lv-feat"><div class="ck">✓</div>إدارة الصيدليات ومنح الصلاحيات</div>
                <div class="lv-feat"><div class="ck">✓</div>لوحة إحصائيات شاملة ومحدّثة</div>
                <div class="lv-feat"><div class="ck">✓</div>إدارة الأدوية والمخزون والبدائل</div>
                <div class="lv-feat"><div class="ck">✓</div>مساعد ذكي مدعوم بـ Gemini AI</div>
            </div>
            <div class="lv-stats">
                <div class="lv-stat"><strong>8,929</strong><span>مستخدم نشط</span></div>
                <div class="lv-stat"><strong>342</strong><span>صيدلية مسجلة</span></div>
                <div class="lv-stat"><strong>12,450</strong><span>دواء في المنظومة</span></div>
            </div>
        </div>
    </div>
    <div class="login-form-side">
        @yield('content')
    </div>
</div>
</body>
</html>
