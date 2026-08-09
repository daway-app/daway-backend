@extends('dashboard')

@section('page_title', 'لوحة تحكم الصيدلية')

@section('content')

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                </svg></div>
            <div class="stat-label">الأدوية في المخزون</div>
            <div class="stat-value">350</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3" />
                    <path d="M15 3H9a3 3 0 0 0-3 3v12" />
                    <path d="M12 9v9" />
                </svg></div>
            <div class="stat-label">التقييمات</div>
            <div class="stat-value">4.8 ⭐</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L2 7l10 5 10-5-10-5z" />
                    <path d="M2 17l10 5 10-5" />
                    <path d="M2 12l10 5 10-5" />
                </svg></div>
            <div class="stat-label">الطلبات الشهرية</div>
            <div class="stat-value">120</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Z" />
                    <path d="M12 8v8M8 12h8" />
                </svg></div>
            <div class="stat-label">الأدوية منخفضة المخزون</div>
            <div class="stat-value">12</div>
        </div>
    </div>

    <div class="recent-section">
        <h3>آخر التحركات</h3>
        <div class="recent-item">
            <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 21V9l8-6 8 6v12" />
                    <path d="M9 21v-6h6v6" />
                </svg></div>
            <div class="text">
                <div class="title">تم إضافة دواء للمخزون</div>
                <div class="sub">أدول 500mg - 20 قطعة</div>
            </div>
            <div class="time">منذ 2 ساعة</div>
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
                <div class="sub">مريض قام بتقييمك 5 نجوم</div>
            </div>
            <div class="time">منذ 6 ساعات</div>
        </div>
        <div class="recent-item">
            <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                </svg></div>
            <div class="text">
                <div class="title">تنبيه انخفاض المخزون</div>
                <div class="sub">بنادول أدفانس - الكمية 5 فقط</div>
            </div>
            <div class="time">منذ 12 ساعة</div>
        </div>
    </div>

@endsection