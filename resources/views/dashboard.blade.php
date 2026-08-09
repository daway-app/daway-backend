<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>دوائي | لوحة التحكم</title>
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
            --shadow-card: 0 4px 12px rgba(0, 101, 122, 0.08);
            --shadow-dropdown: 0 8px 24px rgba(0, 101, 122, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--mint-50);
            color: var(--teal-main);
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* ===== Sidebar ===== */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--teal-main) 0%, var(--teal-dark) 100%);
            color: var(--white);
            display: flex;
            flex-direction: column;
            padding: 24px 20px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 22px;
            margin-bottom: 32px;
            padding: 0 4px;
        }

        .sidebar-logo span {
            color: var(--accent);
        }

        .sidebar-logo .logo-mark {
            width: 36px;
            height: 36px;
            background: var(--accent);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(244, 118, 46, 0.4);
        }

        .sidebar-logo .logo-mark svg {
            width: 20px;
            height: 20px;
        }

        .sidebar-nav {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 14.5px;
            font-weight: 500;
            transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
        }

        .sidebar-nav a:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--white);
            transform: translateX(-4px);
        }

        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.12);
            color: var(--white);
            box-shadow: inset 3px 0 0 var(--accent);
        }

        .sidebar-nav a svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .sidebar-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 20px;
            margin-top: auto;
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-footer a:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--white);
        }

        /* ===== Main Content ===== */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ===== Navbar ===== */
        .navbar {
            background: var(--white);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
            min-height: 72px;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .hamburger {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--teal-main);
        }

        .navbar-title {
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 20px;
            color: var(--teal-main);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .navbar-right .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .navbar-right .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--teal-primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Cairo', sans-serif;
        }

        .notif-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            color: var(--text-muted);
            transition: color 0.2s ease;
            position: relative;
        }

        .notif-btn:hover {
            color: var(--teal-primary);
        }

        .notif-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ===== Page Content ===== */
        .content {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        /* ===== Stats Cards ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 101, 122, 0.1);
        }

        .stat-card .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .stat-card .stat-value {
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--teal-main);
            margin-top: 4px;
        }

        .stat-card .stat-icon {
            float: left;
            color: var(--teal-light);
            opacity: 0.6;
        }

        .stat-card .stat-icon svg {
            width: 28px;
            height: 28px;
        }

        /* ===== Recent Activity ===== */
        .recent-section {
            background: var(--white);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
        }

        .recent-section h3 {
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 16px;
            color: var(--teal-main);
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .recent-item .icon-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--mint-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--teal-primary);
            flex-shrink: 0;
        }

        .recent-item .icon-circle svg {
            width: 18px;
            height: 18px;
        }

        .recent-item .text {
            flex: 1;
        }

        .recent-item .text .title {
            font-weight: 600;
            font-size: 14px;
        }

        .recent-item .text .sub {
            font-size: 13px;
            color: var(--text-muted);
        }

        .recent-item .time {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* ===== Responsive ===== */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                right: 0;
                height: 100vh;
                transform: translateX(100%);
                width: 280px;
                box-shadow: var(--shadow-dropdown);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .hamburger {
                display: block;
            }

            .navbar {
                padding: 12px 20px;
            }

            .navbar-title {
                font-size: 18px;
            }

            .content {
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .navbar-right .user-info .name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 16px;
            }

            .stat-card {
                padding: 16px 20px;
            }
        }

        /* ===== Overlay for mobile ===== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.3);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }
    </style>
</head>

<body>

    <!-- ===== Overlay for mobile ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== Sidebar ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <span class="logo-mark">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Z" stroke="#fff" stroke-width="1.8" />
                    <path d="M12 8v8M8 12h8" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </span>
            <span>دوائي</span>
        </div>

        <nav class="sidebar-nav">
            <!-- مسارات الأدمن -->
            @if(auth()->user()->role === 'admin')
                <a href="{{ route('admin.dashboard') }}" class="active">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path
                            d="M3 12c0-1.5.5-3 1.5-4S6.5 6 8 6c1.5 0 3 .5 4.5 2 1.5-1.5 3-2 4.5-2s3 .5 4.5 2c1 1 1.5 2.5 1.5 4 0 1.5-.5 3-1.5 4S17.5 18 16 18c-1.5 0-3-.5-4.5-2-1.5 1.5-3 2-4.5 2s-3-.5-4.5-2C1.5 15 3 13.5 3 12z" />
                    </svg>
                    لوحة التحكم
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M12 2L2 7l10 5 10-5-10-5z" />
                        <path d="M2 17l10 5 10-5" />
                        <path d="M2 12l10 5 10-5" />
                    </svg>
                    إدارة المستخدمين
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    الأدوية
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M21 12v4a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-4" />
                        <path d="M3 8V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v2" />
                        <rect x="8" y="8" width="8" height="8" rx="1" />
                    </svg>
                    التقارير
                </a>
            @endif

            <!-- مسارات الصيدلية -->
            @if(auth()->user()->role === 'pharmacy')
                <a href="{{ route('pharmacy.dashboard') }}" class="active">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path
                            d="M3 12c0-1.5.5-3 1.5-4S6.5 6 8 6c1.5 0 3 .5 4.5 2 1.5-1.5 3-2 4.5-2s3 .5 4.5 2c1 1 1.5 2.5 1.5 4 0 1.5-.5 3-1.5 4S17.5 18 16 18c-1.5 0-3-.5-4.5-2-1.5 1.5-3 2-4.5 2s-3-.5-4.5-2C1.5 15 3 13.5 3 12z" />
                    </svg>
                    لوحة التحكم
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                    </svg>
                    المخزون
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    الأدوية
                </a>
                <a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3" />
                        <path d="M15 3H9a3 3 0 0 0-3 3v12" />
                        <path d="M12 9v9" />
                    </svg>
                    التقييمات
                </a>
            @endif

            <!-- مشترك للجميع -->
            <a href="#">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" />
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                </svg>
                الملف الشخصي
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="{{ route('logout') }}"
                onclick="event.preventDefault(); document.getElementById('logoutForm').submit();">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <path d="M16 17l5-5-5-5" />
                    <path d="M21 12H9" />
                </svg>
                تسجيل الخروج
            </a>
            <form id="logoutForm" action="{{ route('logout') }}" method="POST" style="display: none;">
                @csrf
            </form>
        </div>
    </aside>

    <!-- ===== Main Content ===== -->
    <div class="main">
        <!-- ===== Navbar ===== -->
        <header class="navbar">
            <div class="navbar-left">
                <button class="hamburger" id="hamburgerBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
                <span class="navbar-title">لوحة التحكم</span>
            </div>

            <div class="navbar-right">
                <button class="notif-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                    <span class="notif-badge">3</span>
                </button>

                <div class="user-info">
                    <span class="name">{{ auth()->user()->name }}</span>
                    <div class="avatar">{{ auth()->user()->name[0] }}</div>
                </div>
            </div>
        </header>

        <!-- ===== Page Content ===== -->
        <div class="content">
            <h2
                style="font-family: 'Cairo', sans-serif; font-weight: 700; font-size: 22px; margin-bottom: 24px; color: var(--teal-main);">
                @yield('page_title', 'لوحة التحكم')
            </h2>

            @yield('content')
        </div>
    </div>

    <!-- ===== Mobile Sidebar Toggle ===== -->
    <script>
        document.getElementById('hamburgerBtn').addEventListener('click', function () {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function () {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        });
    </script>

</body>

</html>