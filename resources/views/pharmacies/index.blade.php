@extends('layouts.app')

@section('title', __('pharmacies.title'))

@section('content')
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #0B8FAC;
            --primary-dark: #00657A;
            --primary-glow: rgba(11, 143, 172, 0.35);
            --bg-body: #f8fafc;
            --border-color: rgba(226, 232, 240, 0.8);
            --text-main: #0f172a;
            --text-muted: #64748b;

            --icon-edit: #16a34a;
            --icon-edit-bg: #f0fdf4;
            --icon-view: #0284c7;
            --icon-view-bg: #f0f9ff;
            --icon-pause: #ea580c;
            --icon-pause-bg: #fff7ed;
            --icon-play: #db2777;
            --icon-play-bg: #fdf2f8;
            --icon-export: #4f46e5;
            --icon-export-bg: #eef2ff;
        }

        body.dark-mode {
            --bg-body: #0f172a;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;

            --icon-edit-bg: rgba(74, 222, 128, 0.1);
            --icon-view-bg: rgba(59, 130, 246, 0.1);
            --icon-pause-bg: rgba(251, 146, 60, 0.1);
            --icon-play-bg: rgba(236, 72, 153, 0.1);
            --icon-export-bg: rgba(99, 102, 241, 0.1);
        }

        .pharmacies-page-wrapper {
            position: relative;
            color: var(--text-main);
            padding: 10px;
            overflow: hidden;
            background-color: var(--bg-body);
            min-height: 100vh;
        }

        .bidi-text { direction: ltr; text-align: left; }

        .ambient-glow {
            position: absolute;
            width: 350px;
            height: 350px;
            border-radius: 50%;
            filter: blur(90px);
            z-index: -1;
            opacity: 0.5;
            animation: floatGlow 10s infinite alternate ease-in-out;
        }
        .glow-1 { top: -50px; right: -50px; background: rgba(11, 143, 172, 0.25); }
        .glow-2 { bottom: 100px; left: -50px; background: rgba(59, 130, 246, 0.2); }

        @keyframes floatGlow {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, 40px) scale(1.15); }
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.75) !important;
            backdrop-filter: blur(20px) saturate(190%);
            -webkit-backdrop-filter: blur(20px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.8) !important;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.03), inset 0 1px 1px 0 rgba(255, 255, 255, 0.9) !important;
            border-radius: 22px;
        }

        body.dark-mode .glass-panel {
            background: rgba(30, 41, 59, 0.75) !important;
            border: 1px solid rgba(51, 65, 85, 0.8) !important;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1), inset 0 1px 1px 0 rgba(51, 65, 85, 0.9) !important;
        }

        .animate-fade-down { animation: fadeInDown 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .animate-fade-up { animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .gradient-text {
            background: linear-gradient(135deg, #0f172a, #0B8FAC);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        body.dark-mode .gradient-text {
            background: linear-gradient(135deg, #f8fafc, #5eead4);
            -webkit-background-clip: text;
        }

        .icon-pulse-wrapper { position: relative; display: inline-flex; }
        .pulse-ring {
            position: absolute;
            inset: -4px;
            border-radius: 18px;
            border: 2px solid var(--primary);
            opacity: 0;
            animation: pulseGlow 2.5s infinite;
        }
        @keyframes pulseGlow {
            0% { transform: scale(0.95); opacity: 0.8; }
            100% { transform: scale(1.25); opacity: 0; }
        }

        .top-header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .header-title-flex { display: flex; align-items: center; gap: 14px; }
        .header-icon-glow { font-size: 30px; background: rgba(11, 143, 172, 0.1); padding: 10px; border-radius: 16px; }

        .btn-add-pharmacy {
            position: relative;
            background: linear-gradient(135deg, #0B8FAC, #00657A);
            color: #ffffff;
            padding: 12px 22px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            overflow: hidden;
            box-shadow: 0 8px 20px var(--primary-glow);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .btn-add-pharmacy:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 28px rgba(11, 143, 172, 0.45);
        }

        .hover-shimmer { position: relative; overflow: hidden; }
        .hover-shimmer::after {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: linear-gradient(60deg, transparent, rgba(255, 255, 255, 0.35), transparent);
            transform: rotate(30deg) translateY(-100%);
            transition: transform 0.8s ease;
        }
        .hover-shimmer:hover::after { transform: rotate(30deg) translateY(100%); }

        .breadcrumb-trail { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted); margin-bottom: 20px; }
        .breadcrumb-trail a { color: var(--primary); text-decoration: none; font-weight: 600; }

        .stats-overview-wrapper { margin-bottom: 24px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 18px; }

        .stat-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            padding: 18px;
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.03);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(11, 143, 172, 0.1); }

        .card-header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .stat-label { font-size: 12px; color: var(--text-muted); font-weight: 600; }
        .stat-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .icon-teal { background: rgba(11, 143, 172, 0.12); color: #0B8FAC; }
        .icon-green { background: rgba(22, 163, 74, 0.12); color: #16a34a; }
        .icon-amber { background: rgba(234, 88, 12, 0.12); color: #ea580c; }
        .icon-blue { background: rgba(2, 132, 199, 0.12); color: #0284c7; }

        .stat-value { font-size: 26px; font-weight: 800; color: var(--text-main); margin: 0; }
        .card-footer-flex { display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 11.5px; color: var(--text-muted); }
        .trend-up { font-weight: 700; color: #16a34a; }

        .charts-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 18px; }
        @media (max-width: 992px) { .charts-grid { grid-template-columns: 1fr; } }

        .chart-card { padding: 18px; display: flex; flex-direction: column; }
        .chart-header h4 { margin: 0 0 14px 0; font-size: 14px; font-weight: 700; color: var(--text-main); }
        .chart-body { position: relative; height: 230px; width: 100%; }

        .filter-card { padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .filter-right-side { display: flex; align-items: center; gap: 16px; }

        .search-input-group { position: relative; width: 280px; }
        .search-input-group input { width: 100%; padding: 9px 14px 9px 38px; background: rgba(248, 250, 252, 0.8); border: 1px solid var(--border-color); border-radius: 20px; font-size: 13px; outline: none; transition: all 0.3s ease; color: var(--text-main); }
        .search-input-group input:focus { background: #ffffff; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-glow); }
        .search-input-group .search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--primary); }

        .pills-group { display: flex; gap: 4px; background: rgba(241, 245, 249, 0.8); padding: 4px; border-radius: 20px; }
        body.dark-mode .pills-group { background: rgba(30, 41, 59, 0.8); }
        .pill-item { border: none; background: transparent; padding: 6px 16px; border-radius: 16px; font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 6px; }
        .pill-item.active { background: var(--primary); color: #ffffff; box-shadow: 0 4px 12px var(--primary-glow); }
        .pill-badge { background: rgba(0, 0, 0, 0.08); padding: 2px 7px; border-radius: 10px; font-size: 11px; }
        .pill-item.active .pill-badge { background: rgba(255, 255, 255, 0.25); }

        .export-link { color: var(--icon-export); background-color: var(--icon-export-bg); text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 12px; border: 1px solid rgba(79, 70, 229, 0.15); transition: transform 0.2s ease; }
        .export-link:hover { transform: translateY(-2px); }

        .grid-meta-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .grid-meta-header h3 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main); }
        .grid-count-tag { font-size: 12px; color: var(--text-muted); background: #e2e8f0; padding: 2px 8px; border-radius: 10px; }
        body.dark-mode .grid-count-tag { background: #334155; color: var(--text-muted); }

        .pharmacies-cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 20px; }

        .pharmacy-card-pro {
            position: relative;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 22px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.04);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }
        .pharmacy-card-pro:hover {
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 20px 35px var(--primary-glow) !important;
        }

        .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-bottom: 14px; border-bottom: 1px dashed var(--border-color); }
        .pharmacy-brand { display: flex; align-items: center; gap: 12px; }
        .avatar-glow-box { width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg, #0B8FAC, #14b8a6); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; box-shadow: 0 6px 14px var(--primary-glow); }

        .pharmacy-title { font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0 0 2px 0; }
        .pharmacy-phone-tag { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }

        .status-indicator-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .status-indicator-badge.active { background: rgba(220, 252, 231, 0.9); color: #15803d; }
        .status-indicator-badge.disabled { background: rgba(254, 226, 226, 0.9); color: #b91c1c; }
        body.dark-mode .status-indicator-badge.active { background: rgba(22, 163, 74, 0.2); color: #4ade80; }
        body.dark-mode .status-indicator-badge.disabled { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        .status-pulse { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .id-copy-strip { display: flex; align-items: center; justify-content: space-between; background: rgba(241, 245, 249, 0.6); padding: 8px 12px; border-radius: 12px; margin: 14px 0; border: 1px solid var(--border-color); }
        body.dark-mode .id-copy-strip { background: rgba(30, 41, 59, 0.5); }
        .strip-label { font-size: 11px; color: var(--text-muted); font-weight: 600; }
        .strip-code-group { display: flex; align-items: center; gap: 8px; }
        .code-text { font-family: monospace; font-size: 12px; font-weight: 700; color: #0B8FAC; background: #ffffff; padding: 2px 8px; border-radius: 6px; border: 1px solid #cbd5e1; }
        body.dark-mode .code-text { background: #1e293b; border-color: #334155; color: #5eead4; }
        .btn-copy-chip { background: #ffffff; border: 1px solid #cbd5e1; padding: 4px 6px; border-radius: 6px; cursor: pointer; color: #64748b; transition: all 0.2s ease; }
        body.dark-mode .btn-copy-chip { background: #1e293b; border-color: #334155; color: #94a3b8; }
        .btn-copy-chip:hover { background: var(--primary); color: #ffffff; }

        .card-info-grid { display: grid; grid-template-columns: 1.4fr 0.8fr; gap: 10px; margin-bottom: 14px; }
        .info-tile { background: rgba(255, 255, 255, 0.9); border: 1px solid rgba(241, 245, 249, 0.8); border-radius: 12px; padding: 8px 10px; display: flex; align-items: center; justify-content: space-between; gap: 6px; }
        body.dark-mode .info-tile { background: rgba(30, 41, 59, 0.8); border-color: #334155; }
        .tile-content { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
        .tile-header { display: flex; align-items: center; gap: 4px; }
        .tile-icon { font-size: 13px; color: var(--text-muted); }
        .tile-label { font-size: 10px; color: var(--text-muted); font-weight: 600; }
        .tile-address { font-size: 11px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tile-val { font-size: 12px; font-weight: 800; color: var(--text-main); }
        .accent-teal { color: #0B8FAC; }

        .btn-show-map { background: #f0fdf4; color: #0B8FAC; border: 1px solid rgba(11, 143, 172, 0.25); padding: 5px 8px; border-radius: 8px; font-size: 10.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: all 0.25s ease; flex-shrink: 0; }
        body.dark-mode .btn-show-map { background: rgba(11, 143, 172, 0.15); color: #5eead4; border-color: rgba(11, 143, 172, 0.3); }
        .btn-show-map:hover { background: #0B8FAC; color: #ffffff; transform: scale(1.05); }

        .card-foot { display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px solid var(--border-color); }
        .time-meta { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
        .actions-flex { display: flex; align-items: center; gap: 6px; }

        .act-btn { width: 32px; height: 32px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; text-decoration: none; transition: all 0.25s ease; }
        .act-btn:hover { transform: translateY(-3px) scale(1.1); }
        .act-edit { background: var(--icon-edit-bg); color: var(--icon-edit); }
        .act-view { background: var(--icon-view-bg); color: var(--icon-view); }
        .act-pause { background: var(--icon-pause-bg); color: var(--icon-pause); }
        .act-play { background: var(--icon-play-bg); color: var(--icon-play); }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(10px); display: none; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: opacity 0.3s ease; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-animate { transform: scale(0.8) translateY(30px); transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
        .modal-overlay.active .modal-animate { transform: scale(1) translateY(0); }

        .map-modal-container { width: 90%; max-width: 650px; padding: 20px; }
        body.dark-mode .map-modal-container { background: #1e293b !important; color: #f8fafc !important; }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .modal-title-group h3 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main); }
        .close-modal-btn { background: transparent; border: none; font-size: 24px; color: var(--text-muted); cursor: pointer; }
        .modal-map-view { height: 320px; width: 100%; border-radius: 14px; border: 1px solid var(--border-color); }
        .modal-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; }
        .btn-external-gmaps { background: #f1f5f9; color: #334155; padding: 8px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        body.dark-mode .btn-external-gmaps { background: #334155; color: #f8fafc; }
        .close-btn-secondary { background: #e2e8f0; color: #334155; border: 0; padding: 8px 16px; border-radius: 10px; cursor: pointer; font-size: 12px; font-weight: 600; }
        body.dark-mode .close-btn-secondary { background: #475569; color: #f8fafc; }
        .pagination-wrapper { margin-top: 20px; }

        body.dark-mode div[class*="card"] {
            background-color: rgba(30, 41, 59, 0.85) !important;
            color: #f8fafc !important;
            border-color: #334155 !important;
        }
    </style>

    <div class="pharmacies-page-wrapper">
        <div class="ambient-glow glow-1"></div>
        <div class="ambient-glow glow-2"></div>

        <div class="top-header-bar animate-fade-down">
            <div class="header-title-section">
                <div class="header-title-flex">
                    <div class="icon-pulse-wrapper">
                        <span class="header-icon-glow"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></span>
                        <div class="pulse-ring"></div>
                    </div>
                    <div>
                        <h1 class="gradient-text">@lang('pharmacies.main_heading')</h1>
                        <p class="bidi-text">@lang('pharmacies.main_description')</p>
                    </div>
                </div>
            </div>

            <div class="header-actions">
                <a href="{{ route('pharmacies.create') }}" class="btn-add-pharmacy hover-shimmer">
                    <svg class="btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>@lang('pharmacies.add_pharmacy_button')</span>
                </a>
            </div>
        </div>

        <div class="breadcrumb-trail animate-fade-down delay-1">
            <a href="{{ route('dashboard') }}">@lang('pharmacies.breadcrumb_main')</a>
            <span class="separator">/</span>
            <span class="current">@lang('pharmacies.breadcrumb_current')</span>
        </div>

        <div class="stats-overview-wrapper animate-fade-up delay-2">
            <div class="stats-grid">
                <div class="stat-card glass-panel">
                    <div class="card-header-flex">
                        <span class="stat-label">Total Pharmacies</span>
                        <div class="stat-icon icon-teal"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                    </div>
                    <h2 class="stat-value counter" data-target="{{ $pharmacies->total() }}">0</h2>
                    <div class="card-footer-flex">
                        <span class="trend-up">▲ 100%</span>
                        <span>registered in the system</span>
                    </div>
                </div>

                <div class="stat-card glass-panel">
                    <div class="card-header-flex">
                        <span class="stat-label">Active Pharmacies</span>
                        <div class="stat-icon icon-green"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                    </div>
                    <h2 class="stat-value counter" data-target="{{ $pharmacies->where('is_active', true)->count() }}">0</h2>
                    <div class="card-footer-flex">
                        <span class="trend-up">active</span>
                        <span>working efficiently</span>
                    </div>
                </div>

                <div class="stat-card glass-panel">
                    <div class="card-header-flex">
                        <span class="stat-label">Inactive Pharmacies</span>
                        <div class="stat-icon icon-amber"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg></div>
                    </div>
                    <h2 class="stat-value counter" data-target="{{ $pharmacies->where('is_active', false)->count() }}">0</h2>
                    <div class="card-footer-flex">
                        <span class="trend-up" style="color: #ef4444;">paused</span>
                        <span>temporarily inactive</span>
                    </div>
                </div>

                <div class="stat-card glass-panel">
                    <div class="card-header-flex">
                        <span class="stat-label">Total Items</span>
                        <div class="stat-icon icon-blue"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                    </div>
                    <h2 class="stat-value counter" data-target="{{ $pharmacies->sum('pharmacy_medicines_count') }}">0</h2>
                    <div class="card-footer-flex">
                        <span>Available Medicines</span>
                    </div>
                </div>
            </div>

            <div class="charts-grid" style="margin-top: 20px;">
                <div class="chart-card glass-panel">
                    <div class="chart-header">
                        <h4>Pharmacy Status Distribution</h4>
                    </div>
                    <div class="chart-body">
                        <canvas id="pharmacyStatusChart"></canvas>
                    </div>
                </div>

                <div class="chart-card glass-panel">
                    <div class="chart-header">
                        <h4>Top Pharmacies by Item Count</h4>
                    </div>
                    <div class="chart-body">
                        <canvas id="topPharmaciesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-card glass-panel animate-fade-up delay-3">
            <div class="filter-right-side">
                <div class="search-input-group">
                    <input type="text" id="searchInput" placeholder="@lang('pharmacies.search_placeholder')" autocomplete="off">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </div>

                <div class="pills-group" id="statusFilterPills">
                    <button type="button" class="pill-item active" data-filter="all">
                        <span>@lang('pharmacies.filter_all')</span>
                        <span class="pill-badge">{{ $pharmacies->total() }}</span>
                    </button>
                    <button type="button" class="pill-item" data-filter="active">
                        <span>@lang('pharmacies.filter_active')</span>
                    </button>
                    <button type="button" class="pill-item" data-filter="disabled">
                        <span>@lang('pharmacies.filter_disabled')</span>
                    </button>
                </div>
            </div>

            <div class="filter-left-side">
                <a href="#" id="exportBtn" class="export-link hover-shimmer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    <span>@lang('pharmacies.export_button')</span>
                </a>
            </div>
        </div>

        <div class="pharmacies-section-wrapper animate-fade-up delay-4">
            <div class="grid-meta-header">
                <h3>@lang('pharmacies.table_heading')</h3>
                <span class="grid-count-tag">{{ $pharmacies->total() }} Pharmacies</span>
            </div>

            <div class="pharmacies-cards-grid" id="pharmaciesGrid">
                @forelse($pharmacies as $index => $pharmacy)
                    <div class="pharmacy-card-pro glass-panel animate-stagger"
                         style="--stagger-index: {{ $index }};"
                         data-id="{{ $pharmacy->id }}"
                         data-status="{{ $pharmacy->is_active ? 'active' : 'disabled' }}"
                         data-name="{{ Str::lower($pharmacy->pharmacy_name) }}">

                        <div class="card-head">
                            <div class="pharmacy-brand">
                                <div class="avatar-glow-box">
                                    <span class="avatar-letter">{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</span>
                                </div>
                                <div class="brand-text">
                                    <h4 class="pharmacy-title">{{ $pharmacy->pharmacy_name }}</h4>
                                    <span class="pharmacy-phone-tag">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        {{ $pharmacy->phone_number ?? '—' }}
                                    </span>
                                </div>
                            </div>

                            <div class="status-indicator-badge {{ $pharmacy->is_active ? 'active' : 'disabled' }}">
                                <span class="status-pulse"></span>
                                <span>@lang('pharmacies.status_' . ($pharmacy->is_active ? 'active' : 'disabled'))</span>
                            </div>
                        </div>

                        <div class="id-copy-strip">
                            <span class="strip-label">@lang('pharmacies.col_pharmacy_id')</span>
                            <div class="strip-code-group">
                                <code class="code-text">{{ $pharmacy->pharmacy_custom_id }}</code>
                                <button type="button" class="btn-copy-chip" onclick="copyId(this, '{{ $pharmacy->pharmacy_custom_id }}')" title="@lang('pharmacies.copy_tooltip')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </button>
                            </div>
                        </div>

                        <div class="card-info-grid">
                            <div class="info-tile tile-location">
                                <div class="tile-content">
                                    <div class="tile-header">
                                        <span class="tile-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
                                        <span class="tile-label">@lang('pharmacies.col_location')</span>
                                    </div>
                                    <div class="tile-address bidi-text" title="{{ $pharmacy->address }}">
                                        {{ $pharmacy->address ?? 'Not specified' }}
                                    </div>
                                </div>

                                <button type="button"
                                        class="btn-show-map"
                                        onclick="openMapModal('{{ addslashes($pharmacy->pharmacy_name) }}', '{{ $pharmacy->latitude }}', '{{ $pharmacy->longitude }}')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
                                    <span>Map</span>
                                </button>
                            </div>

                            <div class="info-tile tile-medicines">
                                <div class="tile-content">
                                    <div class="tile-header">
                                        <span class="tile-icon"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></span>
                                        <span class="tile-label">@lang('pharmacies.col_medicines')</span>
                                    </div>
                                    <span class="tile-val accent-teal">
                                        {{ $pharmacy->pharmacy_medicines_count }} Items
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="card-foot">
                            <span class="time-meta">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                {{ $pharmacy->created_at ? $pharmacy->created_at->diffForHumans() : '—' }}
                            </span>
                            <div class="actions-flex">
                                <a href="{{ route('pharmacies.edit', $pharmacy->id) }}" class="act-btn act-edit" title="@lang('pharmacies.tooltip_edit')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </a>
                                <a href="{{ route('pharmacies.show', $pharmacy->id) }}" class="act-btn act-view" title="@lang('pharmacies.tooltip_show')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </a>
                                <form action="{{ route('pharmacies.toggleStatus', $pharmacy->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="act-btn {{ $pharmacy->is_active ? 'act-pause' : 'act-play' }}" title="{{ $pharmacy->is_active ? __('pharmacies.tooltip_disable') : __('pharmacies.tooltip_enable') }}">
                                        @if($pharmacy->is_active)
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                                        @else
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                        @endif
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="glass-panel" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                        <p style="color: var(--text-muted); margin: 0;">@lang('pharmacies.no_pharmacies_found')</p>
                    </div>
                @endforelse
            </div>

            @if(method_exists($pharmacies, 'links'))
                <div class="pagination-wrapper">
                    {{ $pharmacies->links() }}
                </div>
            @endif
        </div>
    </div>

    <div class="modal-overlay" id="mapModalOverlay">
        <div class="glass-panel map-modal-container modal-animate">
            <div class="modal-header">
                <div class="modal-title-group">
                    <h3 id="modalPharmacyName">Pharmacy Location</h3>
                </div>
                <button type="button" class="close-modal-btn" onclick="closeMapModal()">&times;</button>
            </div>
            <div class="modal-map-view" id="leafletModalMap"></div>
            <div class="modal-footer">
                <a href="#" id="externalMapLink" target="_blank" class="btn-external-gmaps">
                    <span>Open in Google Maps</span>
                </a>
                <button type="button" class="close-btn-secondary" onclick="closeMapModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function copyId(btn, text) {
            navigator.clipboard.writeText(text).then(() => {
                let originalHTML = btn.innerHTML;
                btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                setTimeout(() => btn.innerHTML = originalHTML, 1500);
            });
        }

        let modalMap = null;
        let modalMarker = null;

        function openMapModal(name, lat, lng) {
            document.getElementById('modalPharmacyName').innerText = name;
            let overlay = document.getElementById('mapModalOverlay');
            overlay.classList.add('active');

            let latitude = parseFloat(lat) || 31.9522;
            let longitude = parseFloat(lng) || 35.2332;

            setTimeout(() => {
                if (!modalMap) {
                    modalMap = L.map('leafletModalMap').setView([latitude, longitude], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors'
                    }).addTo(modalMap);
                    modalMarker = L.marker([latitude, longitude]).addTo(modalMap);
                } else {
                    modalMap.setView([latitude, longitude], 15);
                    modalMarker.setLatLng([latitude, longitude]);
                    modalMap.invalidateSize();
                }
            }, 200);

            document.getElementById('externalMapLink').href = `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`;
        }

        function closeMapModal() {
            document.getElementById('mapModalOverlay').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const counters = document.querySelectorAll('.counter');
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                let count = 0;
                const speed = target / 25;
                const updateCount = () => {
                    count += speed;
                    if (count < target) {
                        counter.innerText = Math.ceil(count);
                        setTimeout(updateCount, 30);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCount();
            });

            const searchInput = document.getElementById('searchInput');
            const pills = document.querySelectorAll('#statusFilterPills .pill-item');
            const cards = document.querySelectorAll('.pharmacy-card-pro');
            let currentFilter = 'all';

            function filterCards() {
                const query = searchInput.value.toLowerCase().trim();
                cards.forEach(card => {
                    const name = card.getAttribute('data-name');
                    const status = card.getAttribute('data-status');
                    const matchesSearch = name.includes(query);
                    const matchesStatus = (currentFilter === 'all' || status === currentFilter);

                    if (matchesSearch && matchesStatus) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }

            searchInput.addEventListener('input', filterCards);

            pills.forEach(pill => {
                pill.addEventListener('click', function () {
                    pills.forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.getAttribute('data-filter');
                    filterCards();
                });
            });

            const ctxStatus = document.getElementById('pharmacyStatusChart').getContext('2d');
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Disabled'],
                    datasets: [{
                        data: [
                            {{ $pharmacies->where('is_active', true)->count() }},
                            {{ $pharmacies->where('is_active', false)->count() }}
                        ],
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });

            const ctxTop = document.getElementById('topPharmaciesChart').getContext('2d');
            new Chart(ctxTop, {
                type: 'bar',
                data: {
                    labels: {!! json_encode($pharmacies->sortByDesc('pharmacy_medicines_count')->take(5)->pluck('pharmacy_name')) !!},
                    datasets: [{
                        label: 'Item Count',
                        data: {!! json_encode($pharmacies->sortByDesc('pharmacy_medicines_count')->take(5)->pluck('pharmacy_medicines_count')) !!},
                        backgroundColor: '#0B8FAC',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { display: false } },
                        x: { grid: { display: false } }
                    }
                }
            });
        });
    </script>
@endsection
