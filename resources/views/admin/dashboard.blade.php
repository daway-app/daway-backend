@extends('dashboard')

@section('page_title', 'لوحة تحكم الأدمن')

@section('content')

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="10" cy="8" r="4" />
                </svg></div>
            <div class="stat-label">إجمالي المستخدمين</div>
            <div class="stat-value">1,240</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 21V9l8-6 8 6v12" />
                    <path d="M9 21v-6h6v6" />
                    <path d="M12 9v4" />
                    <path d="M10 11h4" />
                </svg></div>
            <div class="stat-label">عدد الصيدليات</div>
            <div class="stat-value">85</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                </svg></div>
            <div class="stat-label">الأدوية المسجلة</div>
            <div class="stat-value">2,300</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L2 7l10 5 10-5-10-5z" />
                    <path d="M2 17l10 5 10-5" />
                    <path d="M2 12l10 5 10-5" />
                </svg></div>
            <div class="stat-label">متوسط التقييم</div>
            <div class="stat-value">4.6 ⭐</div>
        </div>
    </div>

    <div class="recent-section">
        <h3>آخر النشاطات</h3>
        <div class="recent-item">
            <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 21V9l8-6 8 6v12" />
                    <path d="M9 21v-6h6v6" />
                </svg></div>
            <div class="text">
                <div class="title">تم إضافة صيدلية جديدة</div>
                <div class="sub">صيدلية النور - رام الله</div>
            </div>
            <div class="time">منذ ساعة</div>
        </div>
        <div class="recent-item">
            <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                </svg></div>
            <div class="text">
                <div class="title">تم إضافة دواء جديد</div>
                <div class="sub">بنادول أدفانس 500mg</div>
            </div>
            <div class="time">منذ 3 ساعات</div>
        </div>
        <div class="recent-item">
            <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3" />
                    <path d="M15 3H9a3 3 0 0 0-3 3v12" />
                    <path d="M12 9v9" />
                </svg></div>
            <div class="text">
                <div class="title">تقييم جديد</div>
                <div class="sub">صيدلية الحكيم حصلت على 5 نجوم</div>
            </div>
            <div class="time">منذ 5 ساعات</div>
        </div>
    </div>

@endsection