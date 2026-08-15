<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}"> {{-- Added CSRF token meta tag --}}
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
    <title>دوائي - @yield('title', 'لوحة الإحصائيات')</title>

    @vite(['resources/css/layout/app_layout.css', 'resources/css/layout/topbar.css'])
</head>
<body>
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
{{-- عناصر الخلفية المتحركة التفاعلية --}}
<div class="bg-anim-layer" id="bgAnimLayer" aria-hidden="true">
    <span class="bg-mover" data-depth="0.05"><span class="bg-shape bg-shape--capsule"></span></span>
    <span class="bg-mover" data-depth="0.12"><span class="bg-shape bg-shape--cross"></span></span>
    <span class="bg-mover" data-depth="0.08"><span class="bg-shape bg-shape--pill"></span></span>
    <span class="bg-mover" data-depth="0.15"><span class="bg-shape bg-shape--ring"></span></span>
    <span class="bg-mover" data-depth="0.06"><span class="bg-shape bg-shape--cross"></span></span>
    <span class="bg-mover" data-depth="0.13"><span class="bg-shape bg-shape--capsule"></span></span>
    <span class="bg-mover" data-depth="0.07"><span class="bg-shape bg-shape--ring"></span></span>
    <span class="bg-mover" data-depth="0.11"><span class="bg-shape bg-shape--pill"></span></span>
    <span class="bg-mover" data-depth="0.09"><span class="bg-shape bg-shape--capsule"></span></span>
    <span class="bg-mover" data-depth="0.14"><span class="bg-shape bg-shape--ring"></span></span>
    <span class="bg-mover" data-depth="0.1"><span class="bg-shape bg-shape--cross"></span></span>
    <span class="bg-mover" data-depth="0.04"><span class="bg-shape bg-shape--pill"></span></span>
</div>
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
<link rel="stylesheet" href="{{ asset('css/responsive.css') }}">
<script>
    (function () {
        var layer = document.getElementById('bgAnimLayer');
        if (!layer) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        var movers = Array.prototype.slice.call(layer.querySelectorAll('.bg-mover'));
        var nx = 0, ny = 0, raf = null;

        window.addEventListener('mousemove', function (e) {
            nx = (e.clientX / window.innerWidth) - 0.5;
            ny = (e.clientY / window.innerHeight) - 0.5;
            if (!raf) raf = requestAnimationFrame(apply);
        }, { passive: true });

        function apply() {
            raf = null;
            for (var i = 0; i < movers.length; i++) {
                var m = movers[i];
                var d = parseFloat(m.getAttribute('data-depth')) || 0.08;
                m.style.transform = 'translate3d(' + (nx * 34 * d).toFixed(1) + 'px,' + (ny * 34 * d).toFixed(1) + 'px,0)';
            }
        }
    })();
</script>
@yield('scripts')
</body>
</html>
