<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}"> {{-- Added CSRF token meta tag --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#0B8FAC">
    <script>
        if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
            navigator.serviceWorker.register('/sw.js').catch(function () {});
        }
    </script>
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
    <title>{{ __('layout.app_title') }} - @yield('title', __('dashboard.title'))</title>

    {{-- Firebase Web Push config — قيم عامة للعميل فقط، تُقرأ من config/services.php --}}
    {{-- H-3: config() بدل env() — env() يعيد null بعد php artisan config:cache --}}
    @php
        $fcmConfig = config('services.firebase.api_key') ? [
            'apiKey' => config('services.firebase.api_key'),
            'authDomain' => config('services.firebase.auth_domain'),
            'projectId' => config('services.firebase.project_id'),
            'storageBucket' => config('services.firebase.storage_bucket'),
            'messagingSenderId' => config('services.firebase.messaging_sender_id'),
            'appId' => config('services.firebase.app_id'),
        ] : null;
    @endphp
    <script>
        window.DAWAY_FIREBASE_CONFIG = @js($fcmConfig);
        window.DAWAY_FCM_VAPID_KEY = @js(config('services.firebase.vapid_key'));
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    @vite(['resources/css/layout/app_layout.css', 'resources/css/layout/topbar.css', 'resources/css/layout/sidebar.css', 'resources/js/offline/index.js'])
</head>
<body @if(auth()->check()) class="authed-user" @endif>
<script>
    (function () {
        try {
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark-mode');
                document.documentElement.classList.add('dark-mode');
            }
        } catch (e) {}
    })();
</script>
{{-- خلفية ثابتة هادئة (التنسيق في app_layout.css) --}}
<div class="bg-anim-layer" aria-hidden="true"></div>
<div class="app-layout">
    {{-- الشريط الجانبي --}}
    @include('components.sidebar')

    <div class="main-wrapper">
        {{-- شريط حالة المزامنة — فوق الشريط العلوي في تدفق الصفحة (يدفعه للأسفل عند العرض) --}}
        @include('partials.sync-banner')

        {{-- الشريط العلوي --}}
        @include('components.topbar')

        {{-- المحتوى الرئيسي --}}
        <main class="main-content">
            @hasSection('breadcrumb')
                <nav class="breadcrumb-nav" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}">{{ app()->getLocale() === 'ar' ? 'الرئيسية' : 'Dashboard' }}</a>
                    @yield('breadcrumb')
                </nav>
            @endif
            @yield('content')
        </main>
    </div>
</div>
<link rel="stylesheet" href="{{ asset('css/responsive.css') }}">
@yield('scripts')
@stack('scripts')
</body>
</html>
