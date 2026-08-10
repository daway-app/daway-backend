<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}"> {{-- Added CSRF token meta tag --}}
    <title>دوائي - @yield('title', 'لوحة الإحصائيات')</title>

    @vite(['resources/css/app_layout.css'])
</head>
<body>
<div class="app-layout">
    {{-- الشريط الجانبي --}}
    @include('components.sidebar')

    <div class="main-wrapper">
        {{-- الشريط العلوي --}}
        @include('components.topbar')

        {{-- المحتوى الرئيسي --}}
        <main class="main-content">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
