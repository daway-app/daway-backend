<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'تسجيل الدخول — دوائي')</title>

    @vite(['resources/css/auth.css'])
</head>
<body>

<div class="auth-container">
    @yield('content')
</div>

</body>
</html>
