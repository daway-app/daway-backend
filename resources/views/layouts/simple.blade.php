<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('layout.app_title') }} - @yield('title', __('dashboard.title'))</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    @vite(['resources/css/layout/app_layout.css'])
</head>
<body>
    <main class="main-content" style="margin: 0; padding: 24px; max-width: 100%;">
        @if (session('success'))
            <div class="ph-card" style="margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="ph-card" style="margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
